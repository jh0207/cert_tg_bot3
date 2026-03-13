<?php

namespace app\service;

class TelegramService
{
    private string $token;
    /** @var string[] */
    private array $apiBases;

    public function __construct()
    {
        $config = config('tg');
        $this->token = $config['token'];

        $bases = [];
        if (!empty($config['api_bases']) && is_array($config['api_bases'])) {
            $bases = $config['api_bases'];
        } elseif (!empty($config['api_base'])) {
            $bases = [$config['api_base']];
        }
        $bases = array_values(array_filter(array_map(static function ($value) {
            return rtrim((string) $value, '/');
        }, $bases), static function ($value) {
            return $value !== '';
        }));
        if ($bases === []) {
            $bases = ['https://api.telegram.org'];
        }

        $this->apiBases = array_values(array_unique($bases));
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
        $payloadForLog = $this->sanitizePayloadForLog($payload);

        foreach ($this->apiBases as $index => $apiBase) {
            $url = $apiBase . '/bot' . $this->token . '/' . $method;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $curlErrNo = curl_errno($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrNo !== 0) {
                $this->logDebug('telegram_request_curl_error', [
                    'method' => $method,
                    'api_base' => $apiBase,
                    'curl_errno' => $curlErrNo,
                    'curl_error' => $curlError,
                    'payload' => $payloadForLog,
                ]);
                continue;
            }

            if (!is_string($response) || $response === '') {
                $this->logDebug('telegram_request_empty_response', [
                    'method' => $method,
                    'api_base' => $apiBase,
                    'http_code' => $httpCode,
                    'payload' => $payloadForLog,
                ]);
                continue;
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $this->logDebug('telegram_request_invalid_json', [
                    'method' => $method,
                    'api_base' => $apiBase,
                    'http_code' => $httpCode,
                    'response' => $this->truncateText($response),
                    'payload' => $payloadForLog,
                ]);
                continue;
            }

            if (($decoded['ok'] ?? false) === true) {
                if ($index > 0) {
                    $this->logDebug('telegram_request_fallback_success', [
                        'method' => $method,
                        'api_base' => $apiBase,
                    ]);
                }
                return;
            }

            $this->logDebug('telegram_request_api_error', [
                'method' => $method,
                'api_base' => $apiBase,
                'http_code' => $httpCode,
                'error_code' => $decoded['error_code'] ?? null,
                'description' => $decoded['description'] ?? '',
                'response' => $this->truncateText($response),
                'payload' => $payloadForLog,
            ]);

            $errorCode = (int) ($decoded['error_code'] ?? 0);
            if ($errorCode >= 400 && $errorCode < 500) {
                return;
            }
        }
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
