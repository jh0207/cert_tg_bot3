<?php

namespace app\controller;

use app\service\AuthService;
use app\service\TelegramService;
use app\service\AcmeService;
use app\service\DnsService;
use app\service\CertService;
use app\model\ActionLog;
use app\model\TgUser;

class Bot
{
    private TelegramService $telegram;
    private AuthService $auth;
    private CertService $certService;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
        $this->auth = new AuthService();
        $this->certService = new CertService(new AcmeService(), new DnsService());
    }

    public function handleUpdate(array $update): void
    {
        try {
            $this->logDebug('update_received', [
                'type' => isset($update['callback_query']) ? 'callback' : (isset($update['message']) ? 'message' : 'other'),
                'update_id' => $update['update_id'] ?? null,
            ]);
            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
                return;
            }

            $message = $update['message'] ?? null;
            if (!$message) {
                return;
            }

            $chatId = $message['chat']['id'] ?? null;
            $text = $this->normalizeIncomingText(trim($message['text'] ?? ''));
            if (!$chatId || $text === '') {
                return;
            }

            $this->auth->startUser($message['from']);
            $userRecord = TgUser::where('tg_id', $message['from']['id'])->find();
            if (!$userRecord) {
                return;
            }
            $this->logDebug('message_received', [
                'chat_id' => $chatId,
                'tg_id' => $message['from']['id'] ?? null,
                'text' => $text,
            ]);
            $user = $userRecord->toArray();
            if ((int) ($user['is_banned'] ?? 0) === 1) {
                $this->telegram->sendMessage($chatId, '🚫 你的账号已被封禁，请联系管理员。');
                return;
            }
            if (in_array($text, ['🆕 申请证书', '🆕申请证书'], true)) {
                $text = '/new';
            } elseif (in_array($text, ['📂 我的订单', '📂我的订单', '📂 订单记录'], true)) {
                $text = '/orders';
            } elseif (in_array($text, ['🔎 查询状态', '🔎查询状态'], true)) {
                $text = '/status';
            } elseif (in_array($text, ['📖 使用帮助', '📖使用帮助', '使用帮助', '帮助'], true)) {
                $text = '/help';
            }
            if (strpos($text, '/help') === 0) {
                $this->sendHelpMessage($chatId, $message['from']['id'], $user);
                return;
            }

            if (strpos($text, '/start') === 0) {
                $role = $user['role'];
                $messageText = "👋 <b>欢迎使用证书机器人</b>\n";
                $messageText .= "当前角色：<b>{$role}</b>\n\n";
                if (!$this->auth->isAdmin($message['from']['id'])) {
                    $quota = (int) ($user['apply_quota'] ?? 0);
                    $messageText .= "剩余申请次数：<b>{$quota}</b>\n\n";
                }
                $messageText .= "请选择操作👇";
                $this->sendMainMenu($chatId, $messageText);
                return;
            }

            if ($this->handlePendingInput($user, $message, $chatId, $text)) {
                return;
            }

            if ($this->handleFallbackDomainInput($user, $message, $chatId, $text)) {
                return;
            }
            $domainInput = $this->extractCommandArgument($text, '/domain');

            if (strpos($text, '/new') === 0) {
                $result = $this->certService->startOrder($message['from']);
                if (!$result['success']) {
                    $this->telegram->sendMessage($chatId, $result['message']);
                    return;
                }

                $orderId = $result['order']['id'];
                $keyboard = $this->buildTypeKeyboard($orderId);
                $messageText = "你正在申请 SSL 证书，请选择证书类型👇\n";
                $messageText .= "✅ <b>单域名证书</b>：保护单个域名（可为根域名或子域名，如 example.com / www.example.com）。\n";
                $messageText .= "✅ <b>通配符证书</b>：保护 *.example.com，并同时包含 example.com。\n";
                $messageText .= "📌 通配符证书只需输入主域名（example.com），不要输入 *.example.com。";
                $this->telegram->sendMessage($chatId, $messageText, $keyboard);
                return;
            }

            if (strpos($text, '/orders') === 0) {
                $pageArg = trim(str_replace('/orders', '', $text));
                $page = ctype_digit($pageArg) ? (int) $pageArg : 1;
                $result = $this->certService->listOrdersByPage($message['from'], $page);
                if (!($result['success'] ?? false)) {
                    $this->telegram->sendMessage($chatId, $result['message']);
                    return;
                }
                if (!empty($result['messages'])) {
                    foreach ($result['messages'] as $item) {
                        $this->telegram->sendMessage($chatId, $item['text'], $item['keyboard'] ?? null);
                    }
                } else {
                    $this->telegram->sendMessage($chatId, $result['message']);
                }
                return;
            }

            if (strpos($text, '/domain') === 0) {
                if ($domainInput === null) {
                    $this->sendMainMenu($chatId, '⚠️ 请输入要申请的域名，例如 <b>example.com</b> 或 <b>www.example.com</b>。');
                    return;
                }

                $this->sendProcessingMessage($chatId, '⏳ 正在提交申请，请稍候…');
                $result = $this->certService->createOrder($message['from'], $domainInput);
                $keyboard = $this->resolveOrderKeyboard($result);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if (strpos($text, '/verify') === 0) {
                $domain = trim(str_replace('/verify', '', $text));
                if ($domain === '') {
                    $this->sendMainMenu($chatId, '⚠️ 请输入要验证的域名，例如 <b>example.com</b>。');
                    return;
                }
                $this->sendVerifyProcessingMessageByDomain($chatId, $user['id'], $domain);
                $result = $this->certService->verifyOrder($message['from'], $domain);
                if (($result['success'] ?? false) && isset($result['order'])) {
                    $keyboard = $this->resolveOrderKeyboard($result);
                    $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                } else {
                    $this->telegram->sendMessage($chatId, $result['message']);
                }
                return;
            }

            if (strpos($text, '/status') === 0) {
                $domain = trim(str_replace('/status', '', $text));
                if ($domain === '') {
                    $this->setPendingAction($message['from']['id'], 'await_status_domain');
                    $this->sendMainMenu($chatId, '⚠️ 请输入要查询的域名，例如 <b>example.com</b>。');
                    return;
                }
                $result = $this->certService->status($message['from'], $domain);
                $keyboard = $this->resolveOrderKeyboard($result);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if (strpos($text, '/diag') === 0) {
                if (!$this->auth->isOwner($message['from']['id'])) {
                    $this->telegram->sendMessage($chatId, '❌ 仅 Owner 可使用该命令。');
                    return;
                }
                $diag = $this->buildDiagMessage($user['id']);
                $this->sendMainMenu($chatId, $diag);
                return;
            }

            if (strpos($text, '/quota') === 0) {
                if (!$this->auth->isAdmin($message['from']['id'])) {
                    $this->telegram->sendMessage($chatId, '❌ 仅管理员可调整申请次数。');
                    return;
                }

                $parts = preg_split('/\s+/', trim($text));
                if (count($parts) < 4 || $parts[1] !== 'add') {
                    $this->telegram->sendMessage($chatId, '⚠️ 用法：/quota add @用户名 <次数>');
                    return;
                }

                $targetUsername = $this->extractUsername($parts[2]);
                $amount = (int) $parts[3];
                if (!$targetUsername || $amount <= 0) {
                    $this->telegram->sendMessage($chatId, '⚠️ 用户名和次数必须正确填写。');
                    return;
                }

                $target = TgUser::where('username', $targetUsername)->find();
                if (!$target) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在。');
                    return;
                }

                $current = (int) $target['apply_quota'];
                $newQuota = $current + $amount;
                $target->save(['apply_quota' => $newQuota]);
                $label = $this->formatUserLabel($target);
                $this->telegram->sendMessage(
                    $chatId,
                    "✅ 已为用户 <b>{$label}</b> 增加 <b>{$amount}</b> 次申请额度（当前剩余 {$newQuota} 次）。"
                );
                return;
            }

            if (strpos($text, '/ban') === 0) {
                if (!$this->auth->isAdmin($message['from']['id'])) {
                    $this->telegram->sendMessage($chatId, '❌ 仅管理员可封禁用户。');
                    return;
                }
                $parts = preg_split('/\s+/', trim($text));
                $targetUsername = isset($parts[1]) ? $this->extractUsername($parts[1]) : null;
                if (!$targetUsername) {
                    $this->telegram->sendMessage($chatId, '⚠️ 用法：/ban @用户名');
                    return;
                }
                $target = TgUser::where('username', $targetUsername)->find();
                if (!$target) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在。');
                    return;
                }
                if ($target['role'] === 'owner') {
                    $this->telegram->sendMessage($chatId, '❌ 无法封禁 Owner。');
                    return;
                }
                $target->save(['is_banned' => 1]);
                $label = $this->formatUserLabel($target);
                $this->telegram->sendMessage($chatId, "✅ 已封禁用户 <b>{$label}</b>。");
                return;
            }

            if (strpos($text, '/unban') === 0) {
                if (!$this->auth->isAdmin($message['from']['id'])) {
                    $this->telegram->sendMessage($chatId, '❌ 仅管理员可解封用户。');
                    return;
                }
                $parts = preg_split('/\s+/', trim($text));
                $targetUsername = isset($parts[1]) ? $this->extractUsername($parts[1]) : null;
                if (!$targetUsername) {
                    $this->telegram->sendMessage($chatId, '⚠️ 用法：/unban @用户名');
                    return;
                }
                $target = TgUser::where('username', $targetUsername)->find();
                if (!$target) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在。');
                    return;
                }
                $target->save(['is_banned' => 0]);
                $label = $this->formatUserLabel($target);
                $this->telegram->sendMessage($chatId, "✅ 已解封用户 <b>{$label}</b>。");
                return;
            }

            if (strpos($text, '/admin') === 0) {
                if (!$this->auth->isOwner($message['from']['id'])) {
                    $this->telegram->sendMessage($chatId, '❌ 仅 Owner 可管理管理员权限。');
                    return;
                }
                $parts = preg_split('/\s+/', trim($text));
                if (count($parts) < 3 || !in_array($parts[1], ['add', 'remove'], true)) {
                    $this->telegram->sendMessage($chatId, '⚠️ 用法：/admin add @用户名 或 /admin remove @用户名');
                    return;
                }
                $targetUsername = $this->extractUsername($parts[2]);
                if (!$targetUsername) {
                    $this->telegram->sendMessage($chatId, '⚠️ 用户名必须正确填写。');
                    return;
                }
                $target = TgUser::where('username', $targetUsername)->find();
                if (!$target) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在。');
                    return;
                }
                if ($target['role'] === 'owner') {
                    $this->telegram->sendMessage($chatId, '❌ 无法修改 Owner 权限。');
                    return;
                }
                $action = $parts[1];
                $newRole = $action === 'add' ? 'admin' : 'user';
                $target->save(['role' => $newRole]);
                $label = $this->formatUserLabel($target);
                $messageText = $action === 'add'
                    ? "✅ 已设置 <b>{$label}</b> 为管理员。"
                    : "✅ 已取消 <b>{$label}</b> 的管理员权限。";
                $this->telegram->sendMessage($chatId, $messageText);
                return;
            }

            $this->sendMainMenu($chatId, '🤔 未知指令，点击下方菜单或发送 /help 查看指令。');
        } catch (\Throwable $e) {
            $this->logDebug('message_exception', [
                'update_id' => $update['update_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $message = $update['message'] ?? [];
            $chatId = $message['chat']['id'] ?? null;
            $from = $message['from'] ?? [];
            $userRecord = isset($from['id']) ? TgUser::where('tg_id', $from['id'])->find() : null;
            if ($userRecord) {
                $pendingOrderId = (int) ($userRecord['pending_order_id'] ?? 0);
                if ($pendingOrderId > 0) {
                    $this->certService->recordOrderError((int) $userRecord['id'], $pendingOrderId, $e->getMessage());
                } else {
                    $latestOrder = $this->certService->findLatestOrder((int) $userRecord['id']);
                    if ($latestOrder) {
                        $this->certService->recordOrderError((int) $userRecord['id'], (int) $latestOrder['id'], $e->getMessage());
                    }
                }
            }
            if ($chatId) {
                $this->sendMainMenu($chatId, '❌ 系统异常，请稍后重试或联系管理员。');
            }
        }
    }

    private function handleCallback(array $callback): void
    {
        $data = $callback['data'] ?? '';
        $from = $callback['from'] ?? [];
        $chatId = $callback['message']['chat']['id'] ?? null;

        if (!$chatId || $data === '') {
            return;
        }

        $this->logDebug('callback_received', ['data' => $data]);

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $orderId = isset($parts[2]) ? (int) $parts[2] : (isset($parts[1]) ? (int) $parts[1] : 0);
        try {
            if ($action === 'type') {
                $type = $parts[1] ?? 'root';
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->setOrderType($userId, $orderId, $type);
                if ($result['success']) {
                    if ($type === 'wildcard') {
                        $prompt = "📝 请输入主域名，例如 <b>example.com</b>。\n";
                        $prompt .= "不要输入 http:// 或 https://\n";
                        $prompt .= "不要输入 *.example.com 或 www.example.com";
                    } else {
                        $prompt = "📝 请输入要申请的域名，例如 <b>example.com</b> 或 <b>www.example.com</b>。\n";
                        $prompt .= "不要输入 http:// 或 https://\n";
                        $prompt .= "不要输入 *.example.com";
                    }
                    $this->sendMainMenu($chatId, $prompt);
                } else {
                    $this->sendMainMenu($chatId, $result['message']);
                }
                return;
            }

            if ($action === 'verify') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $order = $this->certService->findOrderById($userId, $orderId);
                if ($order && $this->certService->isOrderCoolingDown($order)) {
                    $this->sendProcessingMessage($chatId, '⏳ 系统正在处理上一次请求，请稍后再试。');
                    return;
                }
                $this->sendVerifyProcessingMessageById($chatId, $userId, $orderId);
                $result = $this->certService->verifyOrderById($userId, $orderId);
                if (isset($result['order'])) {
                    $keyboard = ($result['refresh_only'] ?? false)
                        ? $this->buildVerifyRefreshKeyboard($result['order'])
                        : $this->resolveOrderKeyboard($result);
                    $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                } else {
                    $this->telegram->sendMessage($chatId, $result['message']);
                }
                return;
            }

            if ($action === 'later') {
                $this->telegram->sendMessage($chatId, '✅ 好的，稍后完成解析后再点击验证即可。');
                return;
            }

            if ($action === 'download') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $this->sendProcessingMessage($chatId, '⏳ 正在准备下载信息，请稍候…');
                $result = $this->certService->getDownloadInfo($userId, $orderId);
                $keyboard = $this->buildIssuedKeyboard($orderId, $userId);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'reinstall') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $order = $this->certService->findOrderById($userId, $orderId);
                if ($order && $this->certService->isOrderCoolingDown($order)) {
                    $this->sendProcessingMessage($chatId, '⏳ 系统正在处理上一次请求，请稍后再试。');
                    return;
                }
                $this->sendProcessingMessage($chatId, '⏳ 正在重新导出证书，请稍候…');
                $result = $this->certService->reinstallCert($userId, $orderId);
                $keyboard = $this->buildIssuedKeyboard($orderId, $userId);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'file') {
                $fileKey = $parts[1] ?? '';
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->getDownloadFileInfo($userId, $orderId, $fileKey);
                $keyboard = $this->buildIssuedKeyboard($orderId, $userId);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'info') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->getCertificateInfo($userId, $orderId);
                $keyboard = $this->buildIssuedKeyboard($orderId, $userId);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'status') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $this->sendProcessingMessage($chatId, '⏳ 正在获取订单状态，请稍候…');
                $result = $this->certService->statusById($userId, $orderId);
                $keyboard = $this->resolveOrderKeyboard($result);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }
            if ($action === 'guide') {
                $messageText = "📖 <b>部署教程</b>\n\n";
                $messageText .= "主要文件\n";
                $messageText .= "绝大部分的环境配置, 只需要用到以下两个文件即可。\n";
                $messageText .= "private.pem：证书私钥, 可更改后缀为key。如果使用的是自己上传的CSR文件, 将不包含该文件。\n";
                $messageText .= "fullchain.crt：完整的证书链, 可更改后缀为pem。文件里一般有两段证书(也会有三张), 一张是你的域名证书, 另一张是所依赖的证书链(可能会有两张证书链)。";
                $this->telegram->sendMessage($chatId, $messageText);
                return;
            }

            if ($action === 'created') {
                $subAction = $parts[1] ?? '';
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }

                if ($subAction === 'type') {
                    $keyboard = $this->buildTypeKeyboard($orderId);
                    $messageText = "你正在申请 SSL 证书，请选择证书类型👇\n";
                    $messageText .= "✅ <b>单域名证书</b>：保护单个域名（可为根域名或子域名，如 example.com / www.example.com）。\n";
                    $messageText .= "✅ <b>通配符证书</b>：保护 *.example.com，并同时包含 example.com。\n";
                    $messageText .= "📌 通配符证书只需输入主域名（example.com），不要输入 *.example.com。";
                    $this->telegram->sendMessage($chatId, $messageText, $keyboard);
                    $this->telegram->sendMessageWithReplyKeyboard($chatId, '📌 也可使用下方菜单继续操作。', $this->buildReplyMenuKeyboard());
                    return;
                }

                if ($subAction === 'domain') {
                    $result = $this->certService->requestDomainInput($userId, $orderId);
                    $this->sendMainMenu($chatId, $result['message']);
                    return;
                }

                if ($subAction === 'retry') {
                    $order = $this->certService->findOrderById($userId, $orderId);
                    if ($order && $this->certService->isOrderCoolingDown($order)) {
                        $this->sendProcessingMessage($chatId, '⏳ 系统正在处理上一次请求，请稍后再试。');
                        return;
                    }
                    $this->sendProcessingMessage($chatId, '⏳ 正在生成 DNS TXT 记录，请稍候…');
                    $result = $this->certService->retryDnsChallenge($userId, $orderId);
                    $keyboard = $this->resolveOrderKeyboard($result);
                    $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                    return;
                }
            }

            if ($action === 'cancel') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $this->sendProcessingMessage($chatId, '⏳ 正在取消订单，请稍候…');
                $result = $this->certService->cancelOrder($userId, $orderId);
                $this->sendMainMenu($chatId, $result['message']);
                return;
            }

            if ($action === 'menu') {
                $menuAction = $parts[1] ?? '';
                if ($menuAction === 'new') {
                    $result = $this->certService->startOrder($from);
                    if (!$result['success']) {
                        $this->telegram->sendMessage($chatId, $result['message']);
                        return;
                    }

                    $keyboard = $this->buildTypeKeyboard($result['order']['id']);
                    $messageText = "你正在申请 SSL 证书，请选择证书类型👇\n";
                    $messageText .= "✅ <b>单域名证书</b>：保护单个域名（可为根域名或子域名，如 example.com / www.example.com）。\n";
                    $messageText .= "✅ <b>通配符证书</b>：保护 *.example.com，并同时包含 example.com。\n";
                    $messageText .= "📌 通配符证书只需输入主域名（example.com），不要输入 *.example.com。";
                    $this->telegram->sendMessage($chatId, $messageText, $keyboard);
                    return;
                }

                if ($menuAction === 'status') {
                    $this->setPendingAction($from['id'], 'await_status_domain');
                    $this->sendMainMenu($chatId, '🔎 请输入要查询的域名，例如 <b>example.com</b>。');
                    return;
                }

                if ($menuAction === 'help') {
                    $userId = $this->getUserIdByTgId($from);
                    $userInfo = $userId ? TgUser::where('id', $userId)->find() : null;
                    $this->sendHelpMessage($chatId, $from['id'] ?? 0, $userInfo ? $userInfo->toArray() : []);
                    return;
                }

                if ($menuAction === 'orders') {
                    $userId = $this->getUserIdByTgId($from);
                    if ($userId) {
                        $this->clearPendingAction($userId);
                    }
                    $page = isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : 1;
                    $result = $this->certService->listOrdersByPage($from, $page);
                    $this->sendBatchMessages($chatId, $result);
                    return;
                }
            }

            $this->logDebug('callback_unknown', ['data' => $data]);
            $this->sendMainMenu($chatId, '⚠️ 按钮已过期或无法识别，请返回订单列表重试。');
        } catch (\Throwable $e) {
            $this->logDebug('callback_exception', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);
            $userId = $this->getUserIdByTgId($from);
            if ($userId && $orderId) {
                $this->certService->recordOrderError($userId, $orderId, $e->getMessage());
                $order = $this->certService->findOrderById($userId, $orderId);
                if ($order) {
                    $keyboard = $this->resolveOrderKeyboard(['order' => $order]);
                    $this->telegram->sendMessage($chatId, "❌ 操作失败：{$e->getMessage()}\n请重试或取消订单。", $keyboard);
                } else {
                    $this->sendMainMenu($chatId, "❌ 操作失败：{$e->getMessage()}\n请重试或取消订单。");
                }
                return;
            }
            $this->sendMainMenu($chatId, "❌ 操作失败：{$e->getMessage()}\n请稍后重试。");
        }

        $this->sendMainMenu($chatId, '⚠️ 未识别的操作，请返回菜单重试。');
    }

    private function buildTypeKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '单域名证书（example.com / www.example.com）', 'callback_data' => "type:root:{$orderId}"],
            ],
            [
                ['text' => '通配符证书（*.example.com + example.com）', 'callback_data' => "type:wildcard:{$orderId}"],
            ],
        ];
    }

    private function buildDnsKeyboard(int $orderId, string $status): array
    {
        if ($status === 'dns_verified') {
            return [
                [
                    ['text' => '🔄 刷新状态', 'callback_data' => "status:{$orderId}"],
                ],
                [
                    ['text' => '❌ 取消订单', 'callback_data' => "cancel:{$orderId}"],
                ],
                [
                    ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
                ],
            ];
        }

        return [
            [
                ['text' => '✅ 我已解析，开始验证', 'callback_data' => "verify:{$orderId}"],
            ],
            [
                ['text' => '🔁 重新生成DNS记录', 'callback_data' => "created:retry:{$orderId}"],
                ['text' => '❌ 取消订单', 'callback_data' => "cancel:{$orderId}"],
            ],
            [
                ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
            ],
        ];
    }

    private function buildCreatedKeyboard(array $order): array
    {
        $orderId = $order['id'];
        $buttons = [];
        $certTypeMissing = empty($order['cert_type']) || !in_array($order['cert_type'], ['root', 'wildcard'], true);
        if ($certTypeMissing) {
            $buttons[] = [
                ['text' => '选择证书类型', 'callback_data' => "created:type:{$orderId}"],
            ];
        } else {
            if ((int) ($order['need_dns_generate'] ?? 0) === 1) {
                $buttons[] = [
                    ['text' => '🔄 刷新状态', 'callback_data' => "status:{$orderId}"],
                ];
                $buttons[] = [
                    ['text' => '❌ 取消订单', 'callback_data' => "cancel:{$orderId}"],
                ];
                $buttons[] = [
                    ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
                ];
                return $buttons;
            }
            if (($order['domain'] ?? '') === '') {
                $buttons[] = [
                    ['text' => '提交域名', 'callback_data' => "created:domain:{$orderId}"],
                ];
                $buttons[] = [
                    ['text' => '重新选择证书类型', 'callback_data' => "created:type:{$orderId}"],
                ];
            } else {
                $buttons[] = [
                    ['text' => '提交生成 DNS 记录任务', 'callback_data' => "created:retry:{$orderId}"],
                ];
            }
        }
        $buttons[] = [
            ['text' => '重新申请证书', 'callback_data' => 'menu:new'],
        ];
        $buttons[] = [
            ['text' => '取消订单', 'callback_data' => "cancel:{$orderId}"],
        ];

        return $buttons;
    }

    private function buildIssuedKeyboard(int $orderId, ?int $userId = null): array
    {
        $downloadButton = null;
        if ($userId) {
            $zipUrl = $this->certService->getOrderZipUrl($userId, $orderId);
            if ($zipUrl) {
                $downloadButton = ['text' => '⬇️ 下载压缩包', 'url' => $zipUrl];
            }
        }

        $firstRow = [];
        if ($downloadButton) {
            $firstRow[] = $downloadButton;
        }
        $firstRow[] = ['text' => '📖 部署教程', 'callback_data' => "guide:{$orderId}"];

        return [
            [
                ['text' => '📖 部署教程', 'callback_data' => "guide:{$orderId}"],
            ],
            [
                ['text' => '重新导出', 'callback_data' => "reinstall:{$orderId}"],
            ],
            [
                ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
            ],
        ];
    }

    private function buildVerifyRefreshKeyboard(array $order): array
    {
        $orderId = (int) ($order['id'] ?? 0);
        return [
            [
                ['text' => '🔄 刷新状态', 'callback_data' => "status:{$orderId}"],
            ],
            [
                ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
            ],
        ];
    }

    private function buildFailedKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '🆕 重新申请证书', 'callback_data' => 'menu:new'],
            ],
            [
                ['text' => '❌ 取消订单', 'callback_data' => "cancel:{$orderId}"],
            ],
            [
                ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
            ],
        ];
    }

    private function buildMainMenuKeyboard(): array
    {
        return [
            [
                ['text' => '🆕 申请证书', 'callback_data' => 'menu:new'],
                ['text' => '📂 我的订单', 'callback_data' => 'menu:orders'],
            ],
            [
                ['text' => '📖 使用帮助', 'callback_data' => 'menu:help'],
            ],
        ];
    }

    private function buildReplyMenuKeyboard(): array
    {
        return [
            ['🆕 申请证书', '📂 我的订单'],
            ['🔎 查询状态', '📖 使用帮助'],
        ];
    }

    private function sendMainMenu(int $chatId, string $text): void
    {
        $this->telegram->sendMessageWithReplyKeyboard($chatId, $text, $this->buildReplyMenuKeyboard());
    }

    private function registerBotCommands(): void
    {
        $this->telegram->setMyCommands([
            ['command' => 'start', 'description' => '开始使用/打开菜单'],
            ['command' => 'new', 'description' => '申请证书'],
            ['command' => 'orders', 'description' => '查看订单记录'],
            ['command' => 'status', 'description' => '查询订单状态'],
            ['command' => 'help', 'description' => '使用帮助'],
        ]);
    }

    private function normalizeIncomingText(string $text): string
    {
        $normalized = preg_replace('/[\x{FE0F}\x{200B}\x{FEFF}]/u', '', $text);
        return is_string($normalized) ? trim($normalized) : trim($text);
    }

    private function isBypassPendingCommand(string $text): bool
    {
        return preg_match('/^\/(help|start|new|orders|status)(?:@[A-Za-z0-9_]+)?(?:\s|$)/u', $text) === 1;
    }

    private function extractCommandArgument(string $text, string $command): ?string
    {
        if (strpos($text, $command) !== 0) {
            return null;
        }

        $argument = trim(substr($text, strlen($command)));
        return $argument === '' ? null : $argument;
    }

    private function extractUsername(string $value): ?string
    {
        $username = trim($value);
        if ($username === '') {
            return null;
        }
        if (strpos($username, '@') === 0) {
            $username = substr($username, 1);
        }
        return $username === '' ? null : $username;
    }

    private function formatUserLabel(TgUser $user): string
    {
        $username = trim((string) ($user['username'] ?? ''));
        if ($username !== '') {
            return '@' . $username;
        }

        return (string) ($user['tg_id'] ?? '');
    }

    private function setPendingAction(int $userId, string $action): void
    {
        $user = TgUser::where('tg_id', $userId)->find();
        if (!$user) {
            return;
        }

        $user->save(['pending_action' => $action, 'pending_order_id' => 0]);
    }

    private function clearPendingAction(int $userId): void
    {
        $user = TgUser::where('id', $userId)->find();
        if (!$user) {
            return;
        }

        $user->save(['pending_action' => '', 'pending_order_id' => 0]);
    }

    private function getUserIdByTgId(array $from): ?int
    {
        if (!isset($from['id'])) {
            return null;
        }

        $this->auth->startUser($from);
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return null;
        }

        return (int) $user['id'];
    }

    private function resolveOrderKeyboard(array $result): ?array
    {
        if (!isset($result['order'])) {
            return null;
        }

        $order = $this->normalizeOrder($result['order']);
        if ($order === []) {
            return null;
        }

        $status = $order['status'] ?? '';
        if (in_array($status, ['dns_wait', 'dns_verified'], true)) {
            return $this->buildDnsKeyboard($order['id'], $status);
        }

        if ($status === 'created') {
            return $this->buildCreatedKeyboard($order);
        }

        if ($status === 'issued') {
            return $this->buildIssuedKeyboard($order['id'], (int) ($order['tg_user_id'] ?? 0));
        }

        if ($status === 'failed') {
            return $this->buildFailedKeyboard($order['id']);
        }

        return null;
    }

    private function normalizeOrder($order): array
    {
        if (is_array($order)) {
            return $order;
        }

        if (is_object($order)) {
            if (method_exists($order, 'toArray')) {
                $array = $order->toArray();
                return is_array($array) ? $array : [];
            }

            if ($order instanceof \ArrayAccess) {
                $array = [];
                foreach ($order as $key => $value) {
                    $array[$key] = $value;
                }
                return $array;
            }
        }

        return [];
    }

    private function handlePendingInput(array $user, array $message, int $chatId, string $text): bool
    {
        if ($user['pending_action'] === '') {
            return false;
        }

        if ($this->isBypassPendingCommand($text)) {
            return false;
        }

        $this->logDebug('pending_action_hit', [
            'user_id' => $user['id'],
            'action' => $user['pending_action'],
            'text' => $text,
        ]);

        if ($user['pending_action'] === 'await_domain') {
            $domainInput = $this->extractCommandArgument($text, '/domain');
            if ($domainInput === null && strpos($text, '/') === 0) {
                $this->telegram->sendMessage($chatId, '⚠️ 请先输入要申请的域名，例如 <b>example.com</b> 或 <b>www.example.com</b>。');
                return true;
            }

            $domain = $domainInput ?? $text;
            $this->sendProcessingMessage($chatId, '⏳ 正在提交域名，请稍候…');
            $result = $this->certService->submitDomain($user['id'], $domain);
            $keyboard = $this->resolveOrderKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return true;
        }

        if ($user['pending_action'] === 'await_status_domain') {
            $domainInput = $this->extractCommandArgument($text, '/status');
            if ($domainInput === null && strpos($text, '/') === 0) {
                $this->sendMainMenu($chatId, '⚠️ 请输入要查询的域名，例如 <b>example.com</b>。');
                return true;
            }

            $domain = $domainInput ?? $text;
            $result = $this->certService->status($message['from'], $domain);
            $this->clearPendingAction($user['id']);
            $keyboard = $this->resolveOrderKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return true;
        }

        return false;
    }

    private function sendHelpMessage(int $chatId, int $tgId, array $user): void
    {
        if ($this->auth->isAdmin($tgId)) {
            $help = implode("\n", [
                '🛠️ <b>管理员指令大全</b>',
                '',
                '/new 申请证书（进入选择类型流程）',
                '/domain example.com 快速申请单域名证书',
                '/verify example.com DNS 解析完成后验证并签发',
                '/status example.com 查看订单状态',
                '/diag 查看诊断信息（Owner 专用）',
                '/quota add @用户名 &lt;次数&gt; 追加申请次数',
                '/ban @用户名 封禁用户',
                '/unban @用户名 解封用户',
                '/admin add @用户名 设置管理员（Owner 专用）',
                '/admin remove @用户名 取消管理员（Owner 专用）',
                '',
                '📌 <b>常用按钮</b>',
                '🆕 申请证书 / 🔎 查询状态 / 📂 订单记录 / 📖 使用帮助',
                '待完善：选择证书类型、提交域名、提交生成 DNS 记录任务、取消订单',
                '待添加 DNS 解析：✅ 我已解析，开始验证 / 🔁 重新生成DNS记录 / ❌ 取消订单',
                'DNS 已验证：等待后台签发 / 刷新状态',
                '已签发：下载文件、查看证书信息、重新导出',
                '',
                '📌 <b>状态说明</b>',
                '待完善：订单未完成，需选择证书类型并提交域名。',
                '待添加 DNS 解析：已生成 TXT 记录，需完成 DNS 解析后点击验证。',
                'DNS 已验证：DNS 已验证，系统自动签发，等待完成。',
                '已签发：证书已签发，可下载文件。',
            ]);
            $this->sendMainMenu($chatId, $help);
            return;
        }

        $quota = (int) ($user['apply_quota'] ?? 0);
        $help = implode("\n", [
            '📖 <b>使用帮助</b>',
            '',
            "当前剩余申请次数：<b>{$quota}</b>",
            '如需增加次数，请联系管理员。',
            '',
            '📌 <b>常用按钮</b>',
            '🆕 申请证书 / 🔎 查询状态 / 📂 订单记录 / 📖 使用帮助',
            '待完善：选择证书类型、提交域名、提交生成 DNS 记录任务、取消订单',
            '待添加 DNS 解析：✅ 我已解析，开始验证 / 🔁 重新生成DNS记录 / ❌ 取消订单',
            'DNS 已验证：🔄 刷新状态',
            '已签发：下载文件、查看证书信息、重新导出',
            '',
            '待完善：请选择证书类型并提交域名。',
            '待添加 DNS 解析：按提示添加 TXT 记录后点击「我已完成解析（验证）」。',
            'DNS 已验证：DNS 已验证，系统自动签发，请稍后刷新状态。',
            '已签发：证书已签发，使用下方按钮下载。',
            '',
            '提示：任何时候都可以通过订单列表继续或取消订单。',
        ]);
        $this->sendMainMenu($chatId, $help);
    }

    private function handleFallbackDomainInput(array $user, array $message, int $chatId, string $text): bool
    {
        if ($user['pending_action'] !== '') {
            return false;
        }

        if (strpos($text, '/') === 0) {
            return false;
        }

        $domain = strtolower(trim($text));
        if ($domain === '' || strpos($domain, '.') === false) {
            return false;
        }

        $order = $this->certService->findLatestPendingDomainOrder($user['id']);
        if ($order) {
            $this->logDebug('fallback_domain_submit', [
                'user_id' => $user['id'],
                'order_id' => $order['id'],
                'domain' => $domain,
            ]);
            $this->sendProcessingMessage($chatId, '⏳ 正在提交域名，请稍候…');
            $result = $this->certService->submitDomain($user['id'], $domain);
            $keyboard = $this->resolveOrderKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return true;
        }

        $status = $this->certService->status($message['from'], $domain);
        if ($status['success'] ?? false) {
            $keyboard = $this->resolveOrderKeyboard($status);
            $this->telegram->sendMessage($chatId, $status['message'], $keyboard);
            return true;
        }

        $this->sendMainMenu($chatId, "❌ 未找到域名 <b>{$domain}</b> 的订单。\n你可以点击下方按钮重新申请证书。");
        return true;
    }

    private function buildDiagMessage(int $userId): string
    {
        $user = TgUser::where('id', $userId)->find();
        $pendingAction = $user['pending_action'] ?? '';
        $pendingOrderId = $user['pending_order_id'] ?? 0;
        $latestOrder = $this->certService->findLatestOrder($userId);
        $lastError = $latestOrder['last_error'] ?? '';

        $logs = ActionLog::where('tg_user_id', $userId)
            ->order('id', 'desc')
            ->limit(5)
            ->select();
        $logLines = [];
        foreach ($logs as $log) {
            $logLines[] = "{$log['created_at']} {$log['action']} {$log['detail']}";
        }
        if ($logLines === []) {
            $logLines[] = '（无记录）';
        }

        $message = "<b>🧪 诊断信息</b>\n";
        $message .= "pending_action：<b>{$pendingAction}</b>\n";
        $message .= "pending_order_id：<b>{$pendingOrderId}</b>\n";
        $message .= "最近错误：<b>{$lastError}</b>\n\n";
        $message .= "最近 5 条 ActionLog：\n<pre>" . implode("\n", $logLines) . "</pre>";
        return $message;
    }

    private function sendBatchMessages(int $chatId, array $result): void
    {
        if (isset($result['messages']) && is_array($result['messages'])) {
            foreach ($result['messages'] as $message) {
                $text = $message['text'] ?? '';
                if ($text === '') {
                    continue;
                }
                $keyboard = $message['keyboard'] ?? null;
                $this->telegram->sendMessage($chatId, $text, $keyboard);
            }
            return;
        }

        if (isset($result['message'])) {
            $this->telegram->sendMessage($chatId, $result['message']);
        }
    }

    private function sendProcessingMessage(int $chatId, string $message): void
    {
        $this->telegram->sendMessage($chatId, $message);
    }

    private function sendVerifyProcessingMessageById(int $chatId, int $userId, int $orderId): void
    {
        $order = $this->certService->findOrderById($userId, $orderId);
        if ($order && $order['status'] === 'dns_verified') {
            $this->sendProcessingMessage($chatId, '⏳ 正在签发证书，请稍后刷新状态…');
            return;
        }
        $this->sendProcessingMessage($chatId, '⏳ 正在验证 DNS 解析，这可能需要几十秒…');
    }

    private function sendVerifyProcessingMessageByDomain(int $chatId, int $userId, string $domain): void
    {
        $order = $this->certService->findOrderByDomain($userId, $domain);
        if ($order && $order['status'] === 'dns_verified') {
            $this->sendProcessingMessage($chatId, '⏳ 正在签发证书，请稍后刷新状态…');
            return;
        }
        $this->sendProcessingMessage($chatId, '⏳ 正在验证 DNS 解析，这可能需要几十秒…');
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
