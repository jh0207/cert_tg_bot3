<?php

namespace app\service;

use app\model\ActionLog;
use app\model\CertOrder;
use app\model\TgUser;
use app\validate\DomainValidate;

class CertService
{
    private AcmeService $acme;
    private DnsService $dns;

    public function __construct(AcmeService $acme, DnsService $dns)
    {
        $this->acme = $acme;
        $this->dns = $dns;
    }

    public function createOrder(array $from, string $domain): array
    {
        $domainHadNoise = false;
        $domain = $this->sanitizeDomainInput($domain, $domainHadNoise);
        $validator = new DomainValidate();
        if (!$validator->check(['domain' => $domain])) {
            return ['success' => false, 'message' => $this->domainFormatErrorMessage($domainHadNoise)];
        }
        $typeError = $this->validateDomainByType($domain, 'root');
        if ($typeError) {
            return ['success' => false, 'message' => $typeError];
        }

        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }
        if (!$this->hasQuota($user)) {
            return ['success' => false, 'message' => $this->quotaExhaustedMessage($user)];
        }

        $existing = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->where('status', '<>', 'issued')
            ->find();
        if ($existing) {
            if ($existing['status'] !== 'created') {
                return [
                    'success' => false,
                    'message' => $this->buildOrderStatusMessage($existing, true),
                    'order' => $existing,
                ];
            }

            return [
                'success' => false,
                'message' => $this->buildOrderStatusMessage($existing, true),
                'order' => $existing,
            ];
        }

        $order = CertOrder::create([
            'tg_user_id' => $user['id'],
            'domain' => $domain,
            'status' => 'created',
        ]);

        $this->consumeQuota($user);

        return $this->issueOrder($user, $order);
    }

    public function findOrderById(int $userId, int $orderId): ?array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        return $order ? $order->toArray() : null;
    }

    public function findOrderByDomain(int $userId, string $domain): ?array
    {
        $order = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $userId)
            ->find();
        return $order ? $order->toArray() : null;
    }

    public function findLatestPendingDomainOrder(int $userId): ?array
    {
        $order = CertOrder::where('tg_user_id', $userId)
            ->where('status', 'created')
            ->where('domain', '')
            ->where('cert_type', '<>', '')
            ->order('id', 'desc')
            ->find();
        return $order ? $order->toArray() : null;
    }

    public function findLatestOrder(int $userId): ?array
    {
        $order = CertOrder::where('tg_user_id', $userId)
            ->order('id', 'desc')
            ->find();
        return $order ? $order->toArray() : null;
    }

    public function startOrder(array $from): array
    {
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }
        if (!$this->hasQuota($user)) {
            return ['success' => false, 'message' => $this->quotaExhaustedMessage($user)];
        }

        $existing = CertOrder::where('tg_user_id', $user['id'])
            ->where('status', 'created')
            ->where('domain', '')
            ->find();
        if ($existing) {
            return ['success' => true, 'order' => $existing];
        }

        $order = CertOrder::create([
            'tg_user_id' => $user['id'],
            'domain' => '',
            'status' => 'created',
        ]);

        return ['success' => true, 'order' => $order];
    }

    public function setOrderType(int $userId, int $orderId, string $certType): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '⚠️ 当前状态不可选择类型。'];
        }

        if (!in_array($certType, ['root', 'wildcard'], true)) {
            return ['success' => false, 'message' => '❌ 证书类型不合法。'];
        }

        $order->save(['cert_type' => $certType]);

        $user = TgUser::where('id', $userId)->find();
        if ($user) {
            $user->save([
                'pending_action' => 'await_domain',
                'pending_order_id' => $orderId,
            ]);
        }

        return ['success' => true, 'order' => $order];
    }

    public function submitDomain(int $userId, string $domain): array
    {
        $domainHadNoise = false;
        $domain = $this->sanitizeDomainInput($domain, $domainHadNoise);
        $validator = new DomainValidate();
        if (!$validator->check(['domain' => $domain])) {
            return ['success' => false, 'message' => $this->domainFormatErrorMessage($domainHadNoise)];
        }

        $user = TgUser::where('id', $userId)->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 用户不存在。'];
        }
        if (!$this->hasQuota($user)) {
            return ['success' => false, 'message' => $this->quotaExhaustedMessage($user)];
        }

        if (!$user['pending_order_id']) {
            $fallback = $this->findLatestPendingDomainOrder($userId);
            if ($fallback) {
                $user->save(['pending_order_id' => $fallback['id']]);
            } else {
                $user->save(['pending_action' => '', 'pending_order_id' => 0]);
                return ['success' => false, 'message' => '⚠️ 没有待处理的订单，请先申请证书。'];
            }
        }

        $order = CertOrder::where('id', $user['pending_order_id'])
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'created') {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
            return ['success' => false, 'message' => '⚠️ 当前订单状态不可提交域名。'];
        }

        if ($order['domain'] !== '') {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
            return ['success' => false, 'message' => '⚠️ 该订单已提交域名。'];
        }

        $typeError = $this->validateDomainByType($domain, $order['cert_type']);
        if ($typeError) {
            return ['success' => false, 'message' => $typeError];
        }

        $duplicate = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $userId)
            ->where('status', '<>', 'issued')
            ->find();
        if ($duplicate) {
            return [
                'success' => false,
                'message' => $this->buildOrderStatusMessage($duplicate, true),
                'order' => $duplicate,
            ];
        }

        $order->save(['domain' => $domain]);
        $user->save(['pending_action' => '', 'pending_order_id' => 0]);
        $this->consumeQuota($user);

        return $this->issueOrder($user, $order);
    }

    public function verifyOrderById(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        return $this->verifyOrderByOrder($order);
    }

    public function getCertificateInfo(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '⚠️ 证书尚未签发。'];
        }

        $info = $this->readCertificateInfo($order['cert_path']);
        $typeText = $this->formatCertType($order['cert_type']);
        $issuedAt = $order['updated_at'] ?? '';
        $message = "📄 证书类型：{$typeText}";
        if ($issuedAt) {
            $message .= "\n签发时间：{$issuedAt}";
        }
        if ($info['expires_at']) {
            $message .= "\n有效期至：{$info['expires_at']}";
        }

        return ['success' => true, 'message' => $message];
    }

    public function reinstallCert(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '⚠️ 证书尚未签发，无法重新导出。'];
        }

        $order->save([
            'need_install' => 1,
            'last_error' => '',
        ]);
        $this->log($userId, 'reinstall_schedule', (string) $orderId);

        return [
            'success' => true,
            'message' => '⏳ 重新导出任务已提交，请稍后刷新状态查看下载。',
        ];
    }

    public function getDownloadInfo(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '⚠️ 证书尚未签发。'];
        }

        $typeText = $this->formatCertType($order['cert_type']);
        $issuedAt = $order['updated_at'] ?? '';
        $message = "✅ 证书已签发\n证书类型：{$typeText}\n";
        if ($issuedAt) {
            $message .= "签发时间：{$issuedAt}\n";
        }
        $message .= $this->buildDownloadFilesMessage($order);
        return ['success' => true, 'message' => $message];
    }

    public function getDownloadFileInfo(int $userId, int $orderId, string $fileKey): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '⚠️ 证书尚未签发。'];
        }

        $fileMap = [
            'fullchain' => 'fullchain.cer',
            'cert' => 'cert.cer',
            'key' => 'key.key',
            'ca' => 'ca.cer',
        ];
        if (!isset($fileMap[$fileKey])) {
            return ['success' => false, 'message' => '⚠️ 文件类型不正确。'];
        }

        $filename = $fileMap[$fileKey];
        $label = $fileKey === 'key' ? 'key.key' : $filename;
        $downloadUrl = $this->buildDownloadUrl($order, $filename);
        $message = "📥 {$label} 下载地址：\n{$downloadUrl}\n\n如按钮无法打开，请复制链接到浏览器下载。";
        return ['success' => true, 'message' => $message];
    }

    public function getOrderZipUrl(int $userId, int $orderId): ?string
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return null;
        }
        $archiveName = $this->getOrderArchiveName($order);
        if ($archiveName === '') {
            return null;
        }
        return $this->buildDownloadUrl($order, $archiveName);
    }

    public function requestDomainInput(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '⚠️ 当前状态不可提交域名。'];
        }

        if (!$order['cert_type']) {
            return ['success' => false, 'message' => '⚠️ 请先选择证书类型。'];
        }

        $user = TgUser::where('id', $userId)->find();
        if ($user) {
            $user->save([
                'pending_action' => 'await_domain',
                'pending_order_id' => $orderId,
            ]);
        }

        return ['success' => true, 'message' => '📝 请发送要申请的域名，例如 <b>example.com</b> 或 <b>www.example.com</b>。'];
    }

    public function cancelOrder(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if (!in_array($order['status'], ['created', 'dns_wait', 'dns_verified', 'failed'], true)) {
            return ['success' => false, 'message' => '⚠️ 当前订单无法取消。'];
        }

        $user = TgUser::where('id', $userId)->find();
        if ($user && $user['pending_order_id'] === $orderId) {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
        }

        $shouldRefund = $order['domain'] !== '' && !$this->isUnlimitedUser($user);
        if ($shouldRefund && $user) {
            $user->save(['apply_quota' => (int) $user['apply_quota'] + 1]);
        }

        $order->delete();
        $this->log($userId, 'order_cancel', (string) $orderId);

        return ['success' => true, 'message' => '✅ 订单已取消。'];
    }

    public function retryDnsChallenge(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if (!in_array($order['status'], ['created', 'dns_wait'], true) || $order['domain'] === '') {
            return ['success' => false, 'message' => '⚠️ 当前订单无需重新生成 DNS 记录。'];
        }

        $user = TgUser::where('id', $userId)->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 用户不存在。'];
        }

        $this->acme->removeOrder($order['domain']);
        return $this->issueOrder($user, $order);
    }

    private function issueOrder($user, CertOrder $order): array
    {
        if (!in_array($order['status'], ['created', 'dns_wait', 'failed'], true)) {
            return ['success' => false, 'message' => '⚠️ 当前订单状态不可生成 TXT。'];
        }

        if ($order['domain'] === '') {
            return ['success' => false, 'message' => '⚠️ 请先提交域名。'];
        }

        $domain = $order['domain'];
        $this->acme->removeOrder($domain);
        $this->updateOrderStatus($user['id'], $order, 'created', [
            'need_dns_generate' => 1,
            'need_issue' => 0,
            'need_install' => 0,
            'retry_count' => 0,
            'last_error' => '',
            'txt_host' => '',
            'txt_value' => '',
            'txt_values_json' => '',
        ]);

        $this->log($user['id'], 'order_create', $domain);

        $this->processDnsGenerationOrder($order);
        $latest = CertOrder::where('id', $order['id'])->find();
        if (!$latest) {
            $latest = CertOrder::where('tg_user_id', $user['id'])
                ->where('domain', $domain)
                ->order('id', 'desc')
                ->find();
        }
        if ($latest && in_array($latest['status'], ['dns_wait', 'issued', 'failed', 'created'], true)) {
            return [
                'success' => true,
                'message' => $this->buildOrderStatusMessage($latest, true),
                'order' => $latest,
            ];
        }

        return [
            'success' => true,
            'message' => '⏳ 正在生成 DNS TXT 记录，请稍候…',
            'order' => $order,
        ];
    }

    public function verifyOrder(array $from, string $domain): array
    {
        $domainHadNoise = false;
        $domain = $this->sanitizeDomainInput($domain, $domainHadNoise);
        if ($domain === '') {
            return ['success' => false, 'message' => $this->domainFormatErrorMessage($domainHadNoise)];
        }
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }

        $order = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        return $this->verifyOrderByOrder($order);
    }

    private function verifyOrderByOrder(CertOrder $order): array
    {
        $userId = $order['tg_user_id'];
        if (!in_array($order['status'], ['dns_wait', 'dns_verified'], true)) {
            return ['success' => false, 'message' => '⚠️ 当前状态不可验证，请先完成 DNS 解析。'];
        }

        if ($order['status'] === 'dns_wait') {
            $txtValues = $this->getTxtValues($order);
            if (!$order['txt_host'] || $txtValues === []) {
                $order->save([
                    'status' => 'dns_wait',
                    'last_error' => '缺少 TXT 记录信息，请重新生成 DNS 记录。',
                ]);
                return [
                    'success' => false,
                    'message' => '⚠️ 缺少 TXT 记录信息，请点击「🔁 重新生成DNS记录」后再验证。',
                    'order' => $order->toArray(),
                ];
            }

            $this->logDebug('dns_verify_start', [
                'order_id' => $order['id'],
                'host' => $order['txt_host'],
                'values' => $txtValues,
            ]);
            if (!$this->dns->verifyTxt($order['txt_host'], $txtValues)) {
                $order->save([
                    'status' => 'dns_wait',
                    'last_error' => 'DNS TXT 记录未全部生效，请稍后重试。',
                ]);
                $this->logDebug('dns_verify_failed', ['order_id' => $order['id']]);
                return [
                    'success' => false,
                    'message' => '⏳ 当前未检测到全部 TXT 记录，DNS 可能仍在生效中。通常需要 1~10 分钟，部分 DNS 更久。',
                    'order' => $order->toArray(),
                    'refresh_only' => true,
                ];
            }
            $this->logDebug('dns_verify_success', ['order_id' => $order['id']]);

            $this->updateOrderStatus($userId, $order, 'dns_verified', [
                'last_error' => '',
                'need_issue' => 1,
                'retry_count' => 0,
            ]);
            $this->processIssueOrder($order);
            $latest = CertOrder::where('id', $order['id'])->find();
            if (!$latest) {
                $latest = CertOrder::where('tg_user_id', $userId)
                    ->where('domain', $order['domain'])
                    ->order('id', 'desc')
                    ->find();
            }
            if ($latest) {
                $latestOrder = $latest->toArray();
                return [
                    'success' => true,
                    'message' => $this->buildOrderStatusMessage($latestOrder, true),
                    'order' => $latestOrder,
                ];
            }
            $message = "✅ <b>状态：dns_verified（DNS 已验证）</b>\n";
            $message .= "正在签发，请稍候查看状态。";
            return ['success' => true, 'message' => $message, 'order' => $order];
        }
        return ['success' => true, 'message' => '⏳ 正在签发，请稍后查看状态。', 'order' => $order];
    }

    public function status(array $from, string $domain): array
    {
        $domainHadNoise = false;
        $domain = $this->sanitizeDomainInput($domain, $domainHadNoise);
        if ($domain === '') {
            return ['success' => false, 'message' => $this->domainFormatErrorMessage($domainHadNoise)];
        }
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }

        $order = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        $message = $this->buildOrderStatusMessage($order, false);
        if (!in_array($order['status'], ['dns_wait', 'issued'], true)) {
            $message .= "\n\n⚠️ 该订单尚未完成，请继续下一步或取消订单。";
        }

        return ['success' => true, 'message' => $message, 'order' => $order];
    }

    public function statusById(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        $message = $this->buildOrderStatusMessage($order, false);
        return ['success' => true, 'message' => $message, 'order' => $order];
    }

    public function recordOrderError(int $userId, int $orderId, string $message): void
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if ($order) {
            $order->save(['last_error' => $message]);
        }
        $this->log($userId, 'order_error', $message);
    }

    public function statusByDomain(string $domain): array
    {
        $domainHadNoise = false;
        $domain = $this->sanitizeDomainInput($domain, $domainHadNoise);
        if ($domain === '') {
            return ['success' => false, 'message' => $this->domainFormatErrorMessage($domainHadNoise)];
        }
        $order = CertOrder::where('domain', $domain)->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        return ['success' => true, 'message' => $this->buildOrderStatusMessage($order, false)];
    }

    public function listOrders(array $from): array
    {
        return $this->listOrdersByPage($from, 1);
    }

    public function listOrdersByPage(array $from, int $page): array
    {
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }

        $perPage = 5;
        $page = max(1, $page);
        $total = (int) CertOrder::where('tg_user_id', $user['id'])->count();
        if ($total === 0) {
            return ['success' => true, 'message' => '📂 暂无证书订单记录。'];
        }
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $orders = CertOrder::where('tg_user_id', $user['id'])
            ->order('id', 'desc')
            ->page($page, $perPage)
            ->select();
        if (!$orders || count($orders) === 0) {
            return ['success' => true, 'message' => '📂 暂无证书订单记录。'];
        }

        $headerText = "📂 <b>证书订单记录</b>\n点击下方按钮查看订单详情或继续操作。";
        $headerText .= "\n第 <b>{$page}</b>/{$totalPages} 页";
        $headerKeyboard = $this->buildOrdersPaginationKeyboard($page, $totalPages);

        $messages = [
            [
                'text' => $headerText,
                'keyboard' => $headerKeyboard,
            ],
        ];

        foreach ($orders as $order) {
            $messages[] = $this->buildOrderCard($order);
        }

        return [
            'success' => true,
            'message' => '订单列表已发送',
            'messages' => $messages,
        ];
    }

    public function processCertTasks(int $limit = 20): array
    {
        $dnsProcessed = $this->processDnsGeneration($limit);
        $issueProcessed = $this->processIssueOrders($limit);
        $installProcessed = $this->processInstallOrders($limit);
        $failedProcessed = $this->processFailedOrders($limit);

        return [
            'dns' => $dnsProcessed,
            'issue' => $issueProcessed,
            'install' => $installProcessed,
            'failed' => $failedProcessed,
        ];
    }

    private function log(int $userId, string $action, string $detail): void
    {
        ActionLog::create([
            'tg_user_id' => $userId,
            'action' => $action,
            'detail' => $detail,
        ]);
    }

    private function processDnsGeneration(int $limit): array
    {
        $orders = CertOrder::where('status', 'created')
            ->where('need_dns_generate', 1)
            ->order('id', 'asc')
            ->limit($limit)
            ->select();

        $processed = 0;
        foreach ($orders as $order) {
            if ($this->processDnsGenerationOrder($order)) {
                $processed++;
            }
        }

        return ['processed' => $processed];
    }

    private function processDnsGenerationOrder(CertOrder $order): bool
    {
        $domains = $this->getAcmeDomains($order);
        $this->logDebug('acme_issue_start', ['domains' => $domains, 'order_id' => $order['id']]);
        try {
            $result = $this->acme->issueDns($domains);
        } catch (\Throwable $e) {
            $this->logDebug('acme_issue_exception', ['error' => $e->getMessage(), 'order_id' => $order['id']]);
            $this->recordAcmeFailure($order, $e->getMessage(), [
                'acme_output' => $e->getMessage(),
            ]);
            return false;
        }

        $stderr = $result['stderr'] ?? '';
        $output = $result['output'] ?? '';
        $combinedOutput = trim($output . "\n" . $stderr);
        if ($this->isExistingCertOutput($combinedOutput)) {
            $this->handleExistingCert($order, $combinedOutput);
            return false;
        }
        if ($this->isCertSuccessOutput($combinedOutput)) {
            $installOutput = $this->installOrExportCert($order);
            if ($installOutput === null) {
                $this->recordAcmeFailure($order, '证书已签发但导出失败，请稍后重试。', [
                    'acme_output' => $combinedOutput,
                ]);
                return false;
            }
            return $this->markOrderIssued($order, trim($combinedOutput . "\n" . $installOutput));
        }
        $txt = $this->dns->parseTxtRecords($combinedOutput);
        if (!$txt) {
            if (!($result['success'] ?? false)) {
                $this->logDebug('acme_issue_failed', ['order_id' => $order['id']]);
                $this->recordAcmeFailure($order, $this->resolveAcmeError($stderr, $output), [
                    'acme_output' => $combinedOutput,
                ]);
                return false;
            }
            $this->recordAcmeFailure($order, '无法解析 TXT 记录，请检查 acme.sh 输出。', [
                'acme_output' => $combinedOutput,
            ]);
            return false;
        }

        $txtValues = $txt['values'] ?? [];
        $this->updateOrderStatus($order['tg_user_id'], $order, 'dns_wait', [
            'txt_host' => $txt['host'] ?? '',
            'txt_value' => $txtValues !== [] ? $txtValues[0] : '',
            'txt_values_json' => json_encode($txtValues, JSON_UNESCAPED_UNICODE),
            'acme_output' => $combinedOutput,
            'last_error' => '',
            'need_dns_generate' => 0,
            'retry_count' => 0,
        ]);

        return true;
    }

    private function processIssueOrders(int $limit): array
    {
        $orders = CertOrder::where('status', 'dns_verified')
            ->where('need_issue', 1)
            ->order('id', 'asc')
            ->limit($limit)
            ->select();

        $processed = 0;
        foreach ($orders as $order) {
            if ($this->processIssueOrder($order)) {
                $processed++;
            }
        }

        return ['processed' => $processed];
    }

    private function processIssueOrder(CertOrder $order): bool
    {
        $domains = $this->getAcmeDomains($order);
        $this->logDebug('acme_renew_start', ['domains' => $domains, 'order_id' => $order['id']]);
        try {
            $renew = $this->acme->renew($domains);
        } catch (\Throwable $e) {
            $this->logDebug('acme_renew_exception', ['error' => $e->getMessage(), 'order_id' => $order['id']]);
            $this->recordAcmeFailure($order, $e->getMessage(), [
                'acme_output' => $e->getMessage(),
            ]);
            return false;
        }

        $renewStderr = $renew['stderr'] ?? '';
        $renewOutput = $renew['output'] ?? '';
        $renewCombined = trim($renewOutput . "\n" . $renewStderr);
        if ($this->isExistingCertOutput($renewCombined)) {
            $this->handleExistingCert($order, $renewCombined);
            return false;
        }
        $renewSuccess = (bool) ($renew['success'] ?? false);
        if (!$renewSuccess && $this->isCertSuccessOutput($renewCombined)) {
            $renewSuccess = true;
        }
        if (!$renewSuccess) {
            if ($this->isTxtMismatchError($renewCombined)) {
                $order->save([
                    'status' => 'dns_wait',
                    'need_issue' => 0,
                    'last_error' => 'TXT 记录在 CA 侧尚未生效，请等待 5~10 分钟后再点击验证。',
                    'acme_output' => $renewCombined,
                ]);
                return false;
            }
            $this->logDebug('acme_renew_failed', ['order_id' => $order['id']]);
            $this->recordAcmeFailure($order, $this->resolveAcmeError($renewStderr, $renewOutput), [
                'acme_output' => $renewCombined,
            ]);
            return false;
        }

        $this->logDebug('acme_install_start', ['domain' => $order['domain'], 'order_id' => $order['id']]);
        $exportError = '';
        if (!$this->ensureOrderExportDir($order, $exportError)) {
            $this->recordAcmeFailure($order, $exportError);
            return false;
        }
        $installCombined = '';
        $install = $this->acme->installCert($order['domain'], $this->getOrderExportPath($order));
        $installStderr = $install['stderr'] ?? '';
        $installOutput = $install['output'] ?? '';
        $installCombined = trim($installOutput . "\n" . $installStderr);
        $installSuccess = (bool) ($install['success'] ?? false);
        if (!$installSuccess && $this->isInstallSuccessOutput($installCombined)) {
            $installSuccess = true;
        }
        if (!$installSuccess) {
            $this->logDebug('acme_install_failed', ['order_id' => $order['id']]);
            $this->recordAcmeFailure($order, $this->resolveAcmeError($installStderr, $installOutput), [
                'acme_output' => $installCombined,
            ]);
            return false;
        }

        return $this->markOrderIssued($order, trim($renewCombined . "\n" . $installCombined));
    }

    private function processInstallOrders(int $limit): array
    {
        $orders = CertOrder::where('status', 'issued')
            ->where('need_install', 1)
            ->order('id', 'asc')
            ->limit($limit)
            ->select();

        $processed = 0;
        foreach ($orders as $order) {
            $processed++;
            $this->logDebug('acme_reinstall_start', ['domain' => $order['domain'], 'order_id' => $order['id']]);
            try {
                $install = $this->acme->installCert($order['domain'], $this->getOrderExportPath($order));
            } catch (\Throwable $e) {
                $this->logDebug('acme_reinstall_exception', ['error' => $e->getMessage(), 'order_id' => $order['id']]);
                $this->recordAcmeFailure($order, $e->getMessage(), [
                    'acme_output' => $e->getMessage(),
                ]);
                continue;
            }

            $installStderr = $install['stderr'] ?? '';
            $installOutput = $install['output'] ?? '';
            if (!($install['success'] ?? false)) {
                $this->recordAcmeFailure($order, $this->resolveAcmeError($installStderr, $installOutput), [
                    'acme_output' => $installOutput,
                ]);
                continue;
            }

            $order->save([
                'need_install' => 0,
                'retry_count' => 0,
                'last_error' => '',
                'acme_output' => $installOutput,
            ]);
        }

        return ['processed' => $processed];
    }

    private function processFailedOrders(int $limit): array
    {
        $ttlMinutes = $this->getFailedOrderTtlMinutes();
        if ($ttlMinutes <= 0) {
            return ['processed' => 0];
        }

        $threshold = date('Y-m-d H:i:s', time() - $ttlMinutes * 60);
        $orders = CertOrder::where('status', 'failed')
            ->where('updated_at', '<', $threshold)
            ->order('id', 'asc')
            ->limit($limit)
            ->select();

        $processed = 0;
        foreach ($orders as $order) {
            if ($this->cleanupFailedOrder($order)) {
                $processed++;
            }
        }

        return ['processed' => $processed];
    }

    private function resolveAcmeError(string $stderr, string $output): string
    {
        $error = trim($stderr);
        if ($error !== '') {
            return $error;
        }
        return trim($output) !== '' ? trim($output) : 'acme.sh 执行失败';
    }

    private function recordAcmeFailure(CertOrder $order, string $error, array $extra = []): void
    {
        $retryCount = (int) $order['retry_count'] + 1;
        $payload = array_merge([
            'last_error' => $error,
            'retry_count' => $retryCount,
        ], $extra);

        $limit = $this->getRetryLimit();
        if ($retryCount >= $limit) {
            $payload['need_dns_generate'] = 0;
            $payload['need_issue'] = 0;
            $payload['need_install'] = 0;
            $this->updateOrderStatus($order['tg_user_id'], $order, 'failed', $payload);
            $this->log($order['tg_user_id'], 'order_failed', "{$order['domain']} retry={$retryCount}");
            return;
        }

        $order->save($payload);
    }

    private function getRetryLimit(): int
    {
        $config = config('tg');
        $limit = (int) ($config['acme_retry_limit'] ?? 3);
        return $limit > 0 ? $limit : 3;
    }

    private function getFailedOrderTtlMinutes(): int
    {
        $config = config('tg');
        $ttl = (int) ($config['failed_order_ttl_minutes'] ?? 0);
        return $ttl > 0 ? $ttl : 0;
    }

    private function formatCertType(string $type): string
    {
        return $type === 'wildcard' ? '通配符证书' : '单域名证书';
    }

    private function getAcmeDomains(CertOrder $order): array
    {
        if ($order['cert_type'] === 'wildcard') {
            return [$order['domain'], '*.' . $order['domain']];
        }

        return [$order['domain']];
    }

    private function getOrderExportPath($order): string
    {
        $order = $this->normalizeOrderData($order);
        $config = config('tg');
        $key = $this->getOrderExportKey($order);
        return rtrim($config['cert_export_path'], '/') . '/' . $key . '/';
    }

    private function getOrderExportKey($order): string
    {
        $order = $this->normalizeOrderData($order);
        $domain = $order['domain'] ?? '';
        $orderId = (int) ($order['id'] ?? 0);
        if ($domain === '' || $orderId <= 0) {
            return trim($domain) !== '' ? $domain : 'unknown';
        }
        return "{$domain}_{$orderId}";
    }

    private function getDownloadExportKey($order): string
    {
        $order = $this->normalizeOrderData($order);
        $domain = $order['domain'] ?? '';
        $orderId = (int) ($order['id'] ?? 0);
        $keyNew = ($domain !== '' && $orderId > 0) ? "{$domain}_{$orderId}" : $domain;
        $certPath = $order['cert_path'] ?? '';
        if ($certPath !== '' && $domain !== '') {
            if (strpos($certPath, "/{$keyNew}/") === false && strpos($certPath, "/{$domain}/") !== false) {
                return $domain;
            }
        }
        return $keyNew !== '' ? $keyNew : 'unknown';
    }

    private function getDownloadExportPath($order): string
    {
        $order = $this->normalizeOrderData($order);
        $config = config('tg');
        $key = $this->getDownloadExportKey($order);
        return rtrim($config['cert_export_path'], '/') . '/' . $key . '/';
    }

    private function getOrderArchiveName($order): string
    {
        $order = $this->normalizeOrderData($order);
        $domain = $order['domain'] ?? '';
        if ($domain === '') {
            return '';
        }
        $key = $this->getDownloadExportKey($order);
        if ($key === 'unknown') {
            return '';
        }
        return "{$key}.zip";
    }

    private function getDownloadBaseUrl(): string
    {
        $config = config('tg');
        return rtrim($config['cert_download_base_url'] ?? '', '/');
    }

    private function readCertificateInfo(string $certPath): array
    {
        if (!is_file($certPath)) {
            return ['expires_at' => null];
        }

        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            return ['expires_at' => null];
        }

        $certData = openssl_x509_parse($certContent);
        if (!$certData || !isset($certData['validTo_time_t'])) {
            return ['expires_at' => null];
        }

        return ['expires_at' => date('Y-m-d H:i:s', $certData['validTo_time_t'])];
    }

    private function hasQuota(TgUser $user): bool
    {
        if ($this->isUnlimitedUser($user)) {
            return true;
        }

        return (int) $user['apply_quota'] > 0;
    }

    private function consumeQuota(TgUser $user): void
    {
        $current = (int) $user['apply_quota'];
        if ($current <= 0) {
            return;
        }

        $user->save(['apply_quota' => $current - 1]);
    }

    private function quotaExhaustedMessage(TgUser $user): string
    {
        if ($this->isUnlimitedUser($user)) {
            return '✅ 管理员不受申请次数限制。';
        }

        $quota = (int) $user['apply_quota'];
        return "🚫 <b>申请次数不足</b>（剩余 {$quota} 次）。请联系管理员添加次数。";
    }

    private function buildOrderStatusMessage($order, bool $withTips): string
    {
        $order = $this->normalizeOrderData($order);
        $status = $order['status'] ?? '';
        $statusLabel = $this->formatStatusLabel($status);
        $domain = ($order['domain'] ?? '') !== '' ? $order['domain'] : '（未提交域名）';
        $typeText = ($order['cert_type'] ?? '') ? $this->formatCertType($order['cert_type']) : '（未选择）';
        $message = "📌 当前状态：<b>{$statusLabel}</b>\n域名：<b>{$domain}</b>\n证书类型：<b>{$typeText}</b>";

        if ($status === 'dns_wait') {
            $message .= "\n\n🧾 <b>状态：待添加 DNS 解析</b>\n";
            $message .= "请添加 TXT 记录后点击「✅ 我已解析，开始验证」。\n";
            $txtValues = $this->getTxtValues($order);
            if (($order['txt_host'] ?? '') && $txtValues !== []) {
                $message .= $this->formatTxtRecordBlock(
                    $order['domain'] ?? '',
                    $order['txt_host'],
                    $txtValues,
                    (string) ($order['cert_type'] ?? '')
                );
            }
        } elseif ($status === 'dns_verified') {
            $message .= "\n\n✅ <b>状态：DNS 已验证</b>\n正在签发，请稍后刷新状态。";
        } elseif ($status === 'created' && ($order['domain'] ?? '') === '') {
            $message .= "\n\n📝 订单未完成，请继续选择证书类型并提交域名。";
        } elseif ($status === 'created' && ($order['domain'] ?? '') !== '') {
            if ((int) ($order['need_dns_generate'] ?? 0) === 1) {
                $message .= "\n\n⏳ DNS 记录生成任务已提交，稍后展示 TXT。";
            } else {
                $message .= "\n\n⚠️ 订单未完成，下一步请生成 DNS TXT 记录。\n";
                $message .= "提示：单域名证书可填写 <b>example.com</b> 或 <b>www.example.com</b>；通配符证书只需填写主域名 <b>example.com</b>（不要输入 * 或子域名）。";
            }
            if ($this->isOrderStale($order)) {
                $message .= "\n⚠️ 该订单已长时间未推进，建议取消后重新申请。";
            }
        } elseif ($status === 'issued') {
            $issuedAt = $order['updated_at'] ?? '';
            $message .= "\n\n🎉 <b>状态：已签发</b>\n";
            if ($issuedAt) {
                $message .= "签发时间：{$issuedAt}\n";
            }
            $message .= $this->buildDownloadFilesMessage($order);
            if ((int) ($order['need_install'] ?? 0) === 1) {
                $message .= "\n\n⏳ 重新导出任务已提交，请稍后刷新状态。";
            }
        } elseif ($status === 'failed') {
            $message .= "\n\n❌ <b>状态：处理失败</b>\n订单处理失败，请根据错误信息重新申请或取消订单。";
        }

        if (!empty($order['last_error'])) {
            $message .= "\n\n⚠️ 最近错误：{$order['last_error']}";
        }

        return $message;
    }

    private function buildOrderCard(CertOrder $order): array
    {
        $status = $order['status'];
        $domain = $order['domain'] !== '' ? $order['domain'] : '（未提交域名）';
        $typeText = $order['cert_type'] ? $this->formatCertType($order['cert_type']) : '（未选择）';
        $statusLabel = $this->formatStatusLabel($status);
        $message = "🔖 订单 #{$order['id']}\n域名：<b>{$domain}</b>\n证书类型：<b>{$typeText}</b>\n状态：<b>{$statusLabel}</b>";

        return [
            'text' => $message,
            'keyboard' => [
                [
                    ['text' => '查看详情/操作', 'callback_data' => "status:{$order['id']}"],
                ],
            ],
        ];
    }

    private function buildOrdersPaginationKeyboard(int $page, int $totalPages): ?array
    {
        if ($totalPages <= 1) {
            return null;
        }

        $buttons = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $buttons[] = ['text' => '⬅️ 上一页', 'callback_data' => "menu:orders:{$prevPage}"];
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $buttons[] = ['text' => '下一页 ➡️', 'callback_data' => "menu:orders:{$nextPage}"];
        }

        return $buttons !== [] ? [$buttons] : null;
    }

    private function formatTxtRecordBlock(string $domain, string $host, array $values, string $certType = ''): string
    {
        $recordName = $this->normalizeTxtHost($domain, $host);
        $displayHost = $this->getTxtHostDisplay($domain, $recordName, $certType);
        $valueCount = count($values);
        $message = '';
        foreach ($values as $index => $value) {
            $lineNo = $index + 1;
            $message .= "\n<b>第 {$lineNo} 条记录</b>\n";
            $message .= "<b>记录名（主机记录）</b>\n<pre>{$displayHost}</pre>\n";
            $message .= "<b>记录类型</b>\n<pre>TXT</pre>\n";
            $message .= "<b>记录值</b>\n<pre>{$value}</pre>\n";
        }
        if ($valueCount > 1) {
            $message .= "⚠️ 当前需要添加 <b>{$valueCount}</b> 条 TXT 记录，请全部添加后再验证。\n";
            $message .= "✅ DNS 允许同一个主机记录（_acme-challenge）存在多条 TXT 记录值，请放心添加。\n";
        } elseif ($valueCount === 1) {
            $message .= "✅ 当前仅需添加 <b>1</b> 条 TXT 记录。\n";
        }
        if ($displayHost === $recordName) {
            $message .= "\n说明：请按上方完整主机记录填写（<b>{$recordName}</b>）。";
        } else {
            $message .= "\n说明：主机记录只填 <b>{$displayHost}</b>，系统会自动拼接域名 {$domain}（完整记录为 {$recordName}）。";
        }
        return $message;
    }

    private function getTxtHostDisplay(string $domain, string $recordName, string $certType = ''): string
    {
        $domain = trim($domain);
        $recordName = trim($recordName);

        if ($domain !== '' && $recordName !== '') {
            $displayBaseDomain = $this->resolveTxtDisplayBaseDomain($domain, $certType);
            $suffix = '.' . $displayBaseDomain;
            if (substr($recordName, -strlen($suffix)) === $suffix) {
                $host = rtrim(substr($recordName, 0, -strlen($suffix)), '.');
                if ($host !== '') {
                    return $host;
                }
            }
        }

        return $recordName !== '' ? $recordName : '_acme-challenge';
    }

    private function resolveTxtDisplayBaseDomain(string $domain, string $certType = ''): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }

        // 通配符证书输入已限制为主域名，直接使用。
        if ($certType === 'wildcard') {
            return $domain;
        }

        // 单域名证书：为了便于复制，展示相对主域名的主机记录。
        // 例如：www.example.com -> _acme-challenge.www
        $labels = explode('.', $domain);
        if (count($labels) <= 2) {
            return $domain;
        }

        return implode('.', array_slice($labels, -2));
    }

    private function buildDownloadFilesMessage($order): string
    {
        $order = $this->normalizeOrderData($order);
        $archiveName = $this->ensureCertificateArchive($order);
        if (!$archiveName) {
            return "下载压缩包：暂未生成，请稍后刷新状态。";
        }
        $archiveUrl = $this->buildDownloadUrl($order, $archiveName);
        $message = "下载压缩包：点击下载 ({$archiveUrl})\n";
        $message .= "复制链接：\n<pre>{$archiveUrl}</pre>";
        $message .= "\n\n部署提示：压缩包内已包含 <b>detail.txt</b>，请先按文档中的文件映射关系再部署，避免证书链或私钥文件放错。";
        return $message;
    }

    private function buildDownloadUrl($order, string $filename): string
    {
        $order = $this->normalizeOrderData($order);
        $base = rtrim($this->getDownloadBaseUrl(), '/');
        $key = $this->getDownloadExportKey($order);
        return "{$base}/{$key}/{$filename}";
    }

    private function buildCreatedKeyboard(CertOrder $order): array
    {
        $buttons = [];
        $certTypeMissing = !$order['cert_type'] || !in_array($order['cert_type'], ['root', 'wildcard'], true);
        if ($certTypeMissing) {
            $buttons[] = [
                ['text' => '选择证书类型', 'callback_data' => "created:type:{$order['id']}"],
            ];
        } else {
            if ($order['domain'] === '') {
                $buttons[] = [
                    ['text' => '提交域名', 'callback_data' => "created:domain:{$order['id']}"],
                ];
                $buttons[] = [
                    ['text' => '重新选择证书类型', 'callback_data' => "created:type:{$order['id']}"],
                ];
            } else {
                $buttons[] = [
                    ['text' => '提交生成 DNS 记录任务', 'callback_data' => "created:retry:{$order['id']}"],
                ];
            }
        }
        $buttons[] = [
            ['text' => '重新申请证书', 'callback_data' => 'menu:new'],
        ];
        $buttons[] = [
            ['text' => '取消订单', 'callback_data' => "cancel:{$order['id']}"],
        ];

        return $buttons;
    }

    private function normalizeTxtHost(string $domain, string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return "_acme-challenge.{$domain}";
        }

        $normalizedHost = rtrim($host, '.');
        if (strpos($normalizedHost, $domain) !== false) {
            return $normalizedHost;
        }

        return "{$normalizedHost}.{$domain}";
    }

    private function formatStatusLabel(string $status): string
    {
        $map = [
            'created' => '待完善',
            'dns_wait' => '待添加 DNS 解析',
            'dns_verified' => 'DNS 已验证',
            'issued' => '已签发',
            'failed' => '处理失败',
        ];

        return $map[$status] ?? $status;
    }

    private function normalizeOrderData($order): array
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

    private function isTxtMismatchError(string $output): bool
    {
        $output = strtolower($output);
        return strpos($output, 'incorrect txt record') !== false;
    }

    private function isCertSuccessOutput(string $output): bool
    {
        $output = strtolower($output);
        return strpos($output, 'cert success') !== false
            || strpos($output, 'your cert is in') !== false
            || strpos($output, 'full-chain cert is in') !== false;
    }

    private function isExistingCertOutput(string $output): bool
    {
        $output = strtolower($output);
        return strpos($output, 'seems to already have an ecc cert') !== false;
    }

    private function isInstallSuccessOutput(string $output): bool
    {
        $output = strtolower($output);
        return strpos($output, 'installing cert to') !== false
            && strpos($output, 'installing key to') !== false
            && strpos($output, 'installing full chain to') !== false;
    }

    private function handleExistingCert(CertOrder $order, string $acmeOutput): void
    {
        $recent = ActionLog::where('tg_user_id', $order['tg_user_id'])
            ->where('action', 'order_existing_cert')
            ->where('detail', $order['domain'])
            ->order('id', 'desc')
            ->find();
        if ($recent && !empty($recent['created_at'])) {
            $timestamp = strtotime($recent['created_at']);
            if ($timestamp && (time() - $timestamp) < 600) {
                $this->updateOrderStatus($order['tg_user_id'], $order, 'failed', [
                    'need_dns_generate' => 0,
                    'need_issue' => 0,
                    'need_install' => 0,
                    'retry_count' => 0,
                    'last_error' => '检测到已有证书，请稍后再试或取消订单重新申请。',
                    'txt_host' => '',
                    'txt_value' => '',
                    'txt_values_json' => '',
                    'acme_output' => $acmeOutput,
                ]);
                $this->log($order['tg_user_id'], 'order_existing_cert_blocked', $order['domain']);
                return;
            }
        }

        $this->log($order['tg_user_id'], 'order_existing_cert', $order['domain']);
        $this->acme->removeOrder($order['domain']);
        $domain = $order['domain'];
        $certType = $order['cert_type'];
        $order->delete();

        $user = TgUser::where('id', $order['tg_user_id'])->find();
        if (!$user) {
            return;
        }

        $newOrder = CertOrder::create([
            'tg_user_id' => $order['tg_user_id'],
            'domain' => $domain,
            'cert_type' => $certType,
            'status' => 'created',
        ]);
        $this->log($order['tg_user_id'], 'order_recreate', $domain);
        $this->issueOrder($user, $newOrder);
    }

    private function cleanupFailedOrder(CertOrder $order): bool
    {
        $user = TgUser::where('id', $order['tg_user_id'])->find();
        $shouldRefund = $order['domain'] !== '' && !$this->isUnlimitedUser($user);
        if ($shouldRefund && $user) {
            $user->save(['apply_quota' => (int) $user['apply_quota'] + 1]);
        }
        $order->delete();
        $this->log($order['tg_user_id'], 'order_auto_cancel', (string) $order['id']);
        return true;
    }

    private function installOrExportCert(CertOrder $order): ?string
    {
        $exportError = '';
        if (!$this->ensureOrderExportDir($order, $exportError)) {
            $this->recordAcmeFailure($order, $exportError);
            return null;
        }

        $install = $this->acme->installCert($order['domain'], $this->getOrderExportPath($order));
        $installStderr = $install['stderr'] ?? '';
        $installOutput = $install['output'] ?? '';
        $installCombined = trim($installOutput . "\n" . $installStderr);
        $installSuccess = (bool) ($install['success'] ?? false);
        if (!$installSuccess && $this->isInstallSuccessOutput($installCombined)) {
            $installSuccess = true;
        }
        if (!$installSuccess) {
            $this->logDebug('acme_install_failed', ['order_id' => $order['id'], 'output' => $installCombined]);
            return null;
        }

        return $installCombined;
    }

    private function markOrderIssued(CertOrder $order, string $acmeOutput): bool
    {
        $exportPath = $this->getOrderExportPath($order);
        $this->updateOrderStatus($order['tg_user_id'], $order, 'issued', [
            'cert_path' => $exportPath . 'cert.cer',
            'key_path' => $exportPath . 'key.key',
            'fullchain_path' => $exportPath . 'fullchain.cer',
            'last_error' => '',
            'acme_output' => $acmeOutput,
            'need_dns_generate' => 0,
            'need_issue' => 0,
            'need_install' => 0,
            'retry_count' => 0,
        ]);
        $this->log($order['tg_user_id'], 'order_issued', $order['domain']);
        return true;
    }

    private function ensureOrderExportDir(CertOrder $order, ?string &$error = null): bool
    {
        $path = $this->getOrderExportPath($order);
        if (@is_dir($path)) {
            return true;
        }
        if (!@mkdir($path, 0755, true) && !@is_dir($path)) {
            $error = "证书已签发但导出目录不可用，请检查 CERT_EXPORT_PATH：{$path}";
            return false;
        }
        return true;
    }

    private function isOrderStale($order, int $minutes = 30): bool
    {
        $order = $this->normalizeOrderData($order);
        if (empty($order['updated_at'])) {
            return false;
        }
        $updated = strtotime($order['updated_at']);
        if (!$updated) {
            return false;
        }
        return $updated < (time() - $minutes * 60);
    }

    public function isOrderCoolingDown($order, int $seconds = 8): bool
    {
        $order = $this->normalizeOrderData($order);
        if (empty($order['updated_at'])) {
            return false;
        }
        $updated = strtotime($order['updated_at']);
        if (!$updated) {
            return false;
        }
        return $updated > (time() - $seconds);
    }

    private function ensureCertificateArchive($order): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $order = $this->normalizeOrderData($order);
        $domain = $order['domain'] ?? '';
        if ($domain === '') {
            return null;
        }

        $exportPath = $this->getDownloadExportPath($order);
        $files = ['fullchain.cer', 'cert.cer', 'key.key', 'ca.cer'];
        $latestMtime = 0;
        foreach ($files as $file) {
            $path = $exportPath . $file;
            if (!@is_file($path)) {
                return null;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false) {
                $latestMtime = max($latestMtime, $mtime);
            }
        }

        $archiveName = $this->getOrderArchiveName($order);
        if ($archiveName === '') {
            return null;
        }
        $archivePath = $exportPath . $archiveName;
        if (@is_file($archivePath)) {
            $archiveMtime = @filemtime($archivePath);
            if (
                $archiveMtime !== false
                && $archiveMtime >= $latestMtime
                && $this->archiveMatchesTargetLayout($archivePath)
            ) {
                return $archiveName;
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $compatibilityMap = [
            'certificate.crt' => 'cert.cer',
            'chain.crt' => 'ca.cer',
            'fullchain.crt' => 'fullchain.cer',
            'private.pem' => 'key.key',
        ];
        foreach ($compatibilityMap as $zipName => $sourceFile) {
            $zip->addFile($exportPath . $sourceFile, $zipName);
        }

        $publicPem = $this->buildPublicKeyPem($exportPath . 'cert.cer');
        if ($publicPem === '') {
            $publicPem = "# public key extract failed\n";
        }
        $zip->addFromString('public.pem', $publicPem);

        $zip->addFromString('detail.txt', $this->buildCertificateBundleDetail());

        $zip->close();
        return $archiveName;
    }

    private function buildPublicKeyPem(string $certFile): string
    {
        if (!function_exists('openssl_pkey_get_public')) {
            return '';
        }

        $certContent = @file_get_contents($certFile);
        if ($certContent === false || $certContent === '') {
            return '';
        }

        $public = @openssl_pkey_get_public($certContent);
        if ($public === false) {
            return '';
        }

        $details = openssl_pkey_get_details($public);
        if (function_exists('openssl_pkey_free')) {
            @openssl_pkey_free($public);
        }

        if (!is_array($details) || empty($details['key']) || !is_string($details['key'])) {
            return '';
        }

        return $details['key'];
    }

    private function buildCertificateBundleDetail(): string
    {
        $lines = [
            '综述',
            '所有的证书文件都打包在ZIP压缩包文件里, 解压出来。里面的所有文件本质上都是文本, 可以通过文本编辑器(记事本, vscode, notepad, EditPlus, emeditor等等)直接读取打开。',
            '主要文件',
            '绝大部分的环境配置, 只需要用到以下两个文件即可。',
            'private.pem：证书私钥, 可更改后缀为key。如果使用的是自己上传的CSR文件, 将不包含该文件。',
            'fullchain.crt：完整的证书链, 可更改后缀为pem。文件里一般有两段证书(也会有三张), 一张是你的域名证书, 另一张是所依赖的证书链(可能会有两张证书链)。',
        ];

        return implode("\n", $lines) . "\n";
    }

    private function archiveMatchesTargetLayout(string $archivePath): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return false;
        }

        $required = ['certificate.crt', 'chain.crt', 'fullchain.crt', 'private.pem', 'public.pem', 'detail.txt'];
        foreach ($required as $name) {
            if ($zip->locateName($name) === false) {
                $zip->close();
                return false;
            }
        }

        $forbidden = ['cert.cer', 'ca.cer', 'fullchain.cer', 'key.key'];
        foreach ($forbidden as $name) {
            if ($zip->locateName($name) !== false) {
                $zip->close();
                return false;
            }
        }

        $zip->close();
        return true;
    }

    private function getTxtValues($order): array
    {
        $order = $this->normalizeOrderData($order);
        $values = [];
        if (!empty($order['txt_values_json'])) {
            $decoded = json_decode($order['txt_values_json'], true);
            if (is_array($decoded)) {
                $values = $decoded;
            }
        }
        if ($values === [] && !empty($order['txt_value'])) {
            $values = [$order['txt_value']];
        }
        return array_values(array_filter($values, static function ($value) {
            return $value !== '';
        }));
    }

    private function isUnlimitedUser(?TgUser $user): bool
    {
        if (!$user) {
            return false;
        }

        return in_array($user['role'], ['owner', 'admin'], true);
    }

    private function validateDomainByType(string $domain, ?string $certType): ?string
    {
        if (strpos($domain, '*') !== false) {
            return '❌ 请不要输入通配符格式（*.example.com），只需要输入域名，例如 <b>example.com</b>。';
        }

        if ($certType !== 'wildcard') {
            return null;
        }

        $labels = explode('.', $domain);
        if (count($labels) > 2) {
            return '⚠️ 通配符证书请输入主域名（根域名），例如 <b>example.com</b>，不要输入子域名。';
        }

        return null;
    }

    private function sanitizeDomainInput(string $domain, ?bool &$hadNoise = null): string
    {
        $clean = strip_tags($domain);
        $clean = trim($clean);
        $normalized = preg_replace('/[\s\p{Cc}\x{200B}\x{FEFF}]+/u', '', $clean);
        $hadNoise = $normalized !== $clean;
        return strtolower($normalized ?? '');
    }

    private function domainFormatErrorMessage(bool $hadNoise): string
    {
        $message = '❌ 域名格式错误，请检查后重试。';
        if ($hadNoise) {
            $message .= "\n⚠️ 检测到输入包含空格或不可见字符，请删除后重新发送。";
        }
        return $message;
    }

    private function updateOrderStatus(int $userId, CertOrder $order, string $status, array $extra = []): void
    {
        $fromStatus = $order['status'];
        $payload = array_merge(['status' => $status], $extra);
        $order->save($payload);
        $this->logStatusTransition($userId, $order['domain'], $fromStatus, $status);
    }

    private function logStatusTransition(int $userId, string $domain, string $from, string $to): void
    {
        $detail = "{$domain} {$from} -> {$to}";
        $this->log($userId, 'order_status_change', $detail);
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

    private function summarizeOutput(string $output, int $limit = 500): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $output));
        if (strlen($clean) <= $limit) {
            return $clean;
        }
        return substr($clean, 0, $limit) . '...';
    }
}
