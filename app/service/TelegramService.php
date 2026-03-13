<?php

namespace app\service;

class TelegramService
{
    private string $token;
    private string $apiBase;
    /** @var string[] */
    private array $apiBases;

    public function __construct()
    {
        $config = config('tg');
        $this->token = $config['token'];

        $base = rtrim((string) ($config['api_base'] ?? 'https://api.telegram.org'), '/');
        if ($base === '') {
            $base = 'https://api.telegram.org';
        }

        $this->apiBase = $base;
        $this->apiBases = [$base];
    }

    public function sendMessage(int $chatId, string $text, ?array $inlineKeyboard = null, bool $disablePreview = true): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => $disablePreview,
        ];
        if ($inlineKeyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
        }

        $this->request('sendMessage', $payload);
    }

    public function sendMessageWithReplyKeyboard(int $chatId, string $text, array $keyboard, bool $disablePreview = true): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => $disablePreview,
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ];

        $this->request('sendMessage', $payload);
    }

    public function answerCallbackQuery(string $callbackId, string $text = ''): void
    {
        $payload = [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => false,
        ];

        $this->request('answerCallbackQuery', $payload);
    }

    public function setMyCommands(array $commands): void
    {
        $payload = [
            'commands' => json_encode($commands, JSON_UNESCAPED_UNICODE),
        ];

        $this->request('setMyCommands', $payload);
    }

    private function request(string $method, array $payload): void
    {
        $result = $this->requestOnce($this->apiBase, $method, $payload);
        if (($result['success'] ?? false) === true) {
            return;
        }

        $this->logDebug('telegram_request_failed', [
            'method' => $method,
            'api_base' => $this->apiBase,
            'kind' => $result['kind'] ?? '',
            'curl_errno' => $result['curl_errno'] ?? null,
            'curl_error' => $result['curl_error'] ?? '',
            'http_code' => $result['http_code'] ?? null,
            'description' => $result['description'] ?? '',
            'payload' => $this->sanitizePayloadForLog($payload),
            'response' => isset($result['response']) ? $this->truncateText((string) $result['response']) : '',
        ]);
    }

    private function requestOnce(string $apiBase, string $method, array $payload): array
    {
        $url = $apiBase . '/bot' . $this->token . '/' . $method;

        $maxAttempts = 2;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $curlErrNo = curl_errno($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrNo !== 0) {
                $kind = 'curl_error';
                $context = [
                    'method' => $method,
                    'api_base' => $apiBase,
                    'attempt' => $attempt,
                    'curl_errno' => $curlErrNo,
                    'curl_error' => $curlError,
                ];
                // 首次 timeout 再重试一次，抖动时可自动恢复。
                if ($curlErrNo === 28 && $attempt < $maxAttempts) {
                    $this->logDebug('telegram_request_retry_timeout', $context);
                    continue;
                }
                $this->logDebug('telegram_request_curl_error', $context + [
                    'payload' => $this->sanitizePayloadForLog($payload),
                ]);
                return [
                    'success' => false,
                    'kind' => $kind,
                    'api_base' => $apiBase,
                    'attempt' => $attempt,
                    'curl_errno' => $curlErrNo,
                    'curl_error' => $curlError,
                    'http_code' => $httpCode,
                ];
            }

            if (!is_string($response) || $response === '') {
                $this->logDebug('telegram_request_empty_response', [
                    'method' => $method,
                    'api_base' => $apiBase,
                    'attempt' => $attempt,
                    'http_code' => $httpCode,
                    'payload' => $this->sanitizePayloadForLog($payload),
                ]);
                return [
                    'success' => false,
                    'kind' => 'empty_response',
                    'api_base' => $apiBase,
                    'attempt' => $attempt,
                    'http_code' => $httpCode,
                    'response' => $response,
                ];
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $this->logDebug('telegram_request_invalid_json', [
                    'method' => $method,
                    'api_base' => $apiBase,
                    'attempt' => $attempt,
                    'http_code' => $httpCode,
                    'response' => $this->truncateText($response),
                    'payload' => $this->sanitizePayloadForLog($payload),
                ]);
                return [
                    'success' => false,
                    'kind' => 'invalid_json',
                    'api_base' => $apiBase,
                    'attempt' => $attempt,
                    'http_code' => $httpCode,
                    'response' => $response,
                ];
            }

            if (($decoded['ok'] ?? false) !== true) {
                $this->logDebug('telegram_request_api_error', [
                    'method' => $method,
                    'api_base' => $apiBase,
                    'attempt' => $attempt,
                    'http_code' => $httpCode,
                    'error_code' => $decoded['error_code'] ?? null,
                    'description' => $decoded['description'] ?? '',
                    'response' => $this->truncateText($response),
                    'payload' => $this->sanitizePayloadForLog($payload),
                ]);
                return [
                    'success' => false,
                    'kind' => 'api_error',
                    'api_base' => $apiBase,
                    'attempt' => $attempt,
                    'http_code' => $httpCode,
                    'description' => (string) ($decoded['description'] ?? ''),
                    'response' => $response,
                ];
            }

            return [
                'success' => true,
                'api_base' => $apiBase,
                'attempt' => $attempt,
            ];
        }

        return [
            'success' => false,
            'kind' => 'unknown',
            'api_base' => $apiBase,
        ];
    }

    private function sanitizePayloadForLog(array $payload): array
    {
        $copy = $payload;
        if (isset($copy['text']) && is_string($copy['text'])) {
            $copy['text'] = $this->truncateText($copy['text']);
        }

        return $copy;
    }

    private function truncateText(string $text, int $limit = 500): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . '...';
    }

    private function logDebug(string $message, array $context = []): void
    {
        $logFile = $this->resolveLogFile();
        $line = date('Y-m-d H:i:s') . ' ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $line .= PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private function resolveLogFile(): string
    {
        $base = function_exists('root_path') ? root_path() : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $logDir = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        return $logDir . DIRECTORY_SEPARATOR . 'tg_bot.log';
    }
}
