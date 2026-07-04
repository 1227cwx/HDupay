<?php

namespace app\service;

use app\model\DepositOrder;
use app\model\EasyPayOrder;
use app\model\OpenApiCallbackLog;
use app\model\OpenApiClient;
use app\model\PaymentAddress;
use app\model\WalletAccount;
use InvalidArgumentException;

class DepositService
{
    public function __construct()
    {
        (new DepositOrderSchemaService())->ensure();
        (new EasyPaySchemaService())->ensure();
    }

    public function networks(): array
    {
        return (new WalletService())->depositNetworkOptions();
    }

    public function options(): array
    {
        return (new FiatRateService())->options();
    }

    public function create(array $input): array
    {
        $epayOrderNo = trim((string)($input['epay_order_no'] ?? ''));
        $existingEpayDeposit = null;
        if ($epayOrderNo !== '') {
            $existingEpayDeposit = (new EasyPayService())->existingDepositOrderForCreate($epayOrderNo);
            if ($existingEpayDeposit) {
                return $this->orderPayload($existingEpayDeposit, 0, (string)($input['base_url'] ?? ''));
            }
            $input = (new EasyPayService())->applyToDepositInput($epayOrderNo, $input);
        }

        $networkCode = trim((string)($input['network'] ?? $input['network_id'] ?? ''));
        if (!config('chains.networks.' . $networkCode)) {
            throw new InvalidArgumentException('网络不存在');
        }

        $tokenCode = strtoupper(trim((string)($input['token'] ?? $input['token_code'] ?? 'USDC')));
        (new DepositNetworkAvailabilityService())->assertAvailable($networkCode, $tokenCode);
        $fiatCurrency = strtoupper(trim((string)($input['fiat_currency'] ?? '')));
        $fiatAmount = trim((string)($input['fiat_amount'] ?? ''));
        $quote = (new FiatRateService())->quote($tokenCode, $fiatCurrency, $fiatAmount);
        (new EvmRpcService())->tokenConfig($networkCode, $tokenCode);
        (new EvmMonitorService())->prepareCursorForOrder($networkCode, $tokenCode);
        if ($quote['amount_int'] === '0') {
            throw new InvalidArgumentException('交易金额必须大于 0');
        }

        $orderNo = $this->makeOrderNo();
        $address = (new AddressPoolService())->allocate($networkCode, $tokenCode, $orderNo);
        $timeoutMinutes = $this->depositTimeoutMinutes((int)($address['wallet_account_id'] ?? 0));
        $expireAt = date('Y-m-d H:i:s', time() + $timeoutMinutes * 60);
        $source = $this->normalizeSource((string)($input['source'] ?? 'frontend'));
        $sourceIp = trim((string)($input['source_ip'] ?? ''));
        $apiClientId = (int)($input['api_client_id'] ?? 0);
        if ($apiClientId > 0 && $source !== 'epay') {
            $source = 'api';
        }
        $returnUrl = $this->normalizeReturnUrl((string)($input['return_url'] ?? ''));
        $baseUrl = trim((string)($input['base_url'] ?? ''));

        $record = [
            'order_no' => $orderNo,
            'user_id' => (int)($input['user_id'] ?? 0),
            'source' => $source,
            'source_ip' => $sourceIp,
            'api_client_id' => $apiClientId,
            'network_code' => $networkCode,
            'token_code' => $tokenCode,
            'fiat_currency' => $quote['fiat_currency'],
            'fiat_amount' => $quote['fiat_amount'],
            'token_amount' => $quote['token_amount'],
            'exchange_rate' => $quote['exchange_rate'],
            'rate_provider' => $quote['rate_provider'],
            'rate_fetched_at' => $quote['rate_fetched_at'],
            'return_url' => $returnUrl,
            'amount_int' => $quote['amount_int'],
            'amount_display' => $quote['token_amount'],
            'paid_amount_int' => '0',
            'address_id' => $address['id'],
            'address' => strtolower($address['address_lower']),
            'to_address' => strtolower($address['address_lower']),
            'status' => 'waiting',
            'expire_at' => $expireAt,
        ];
        DepositOrder::createRecord($record);
        if ($epayOrderNo !== '') {
            (new EasyPayService())->attachDepositOrder($epayOrderNo, $orderNo);
        }

        return $this->orderPayload(DepositOrder::findByOrderNo($orderNo) ?: $record, $timeoutMinutes, $baseUrl);
    }

    public function detail(string $orderNo): array
    {
        $order = DepositOrder::findByOrderNo($orderNo);
        if (!$order) {
            throw new InvalidArgumentException('订单不存在');
        }
        $epayOrder = (($order['source'] ?? '') === 'epay') ? EasyPayOrder::findByDepositOrderNo((string)$order['order_no']) : null;
        if ($epayOrder) {
            unset($epayOrder['api_secret_encrypted']);
        }
        $apiClient = null;
        $callbackLog = null;
        if ((int)($order['api_client_id'] ?? 0) > 0) {
            $client = OpenApiClient::findById((int)$order['api_client_id']);
            if ($client) {
                unset($client['api_secret_hash']);
                unset($client['api_secret_encrypted']);
                $apiClient = $client;
            }
            $callbackLog = OpenApiCallbackLog::findByClientAndOrder((int)$order['api_client_id'], (string)$order['order_no']);
        }
        $order = $this->appendOrderView($order, $callbackLog, $apiClient, $epayOrder);
        $order['payment_address'] = !empty($order['address_id']) ? PaymentAddress::findById((int)$order['address_id']) : null;
        $order['api_client'] = $apiClient;
        $order['callback_log'] = $callbackLog;
        $order['easypay_order'] = $epayOrder;
        return $order;
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $filters = $this->normalizeFilters($filters);
        $result = DepositOrder::listPageWithFilters($filters, $page, $perPage);
        $logs = OpenApiCallbackLog::latestByOrderNos(array_column($result['items'], 'order_no'));
        $clients = $this->clientsByIds(array_column($result['items'], 'api_client_id'));
        $epayOrders = EasyPayOrder::latestByDepositOrderNos(array_column($result['items'], 'order_no'));
        foreach ($result['items'] as &$item) {
            $item = $this->appendOrderView(
                $item,
                $logs[$item['order_no']] ?? null,
                $clients[(int)($item['api_client_id'] ?? 0)] ?? null,
                $epayOrders[$item['order_no']] ?? null
            );
        }
        return $result;
    }

    public function publicStatus(string $orderNo, bool $allowTerminal = false, string $baseUrl = ''): array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            throw new InvalidArgumentException('订单号不能为空');
        }
        $order = DepositOrder::findByOrderNo($orderNo);
        if (!$order) {
            throw new InvalidArgumentException('订单不存在');
        }
        $order = $this->refreshExpiredOrder($order);
        $order = $this->appendConfirmationProgress($order);
        $status = (string)($order['status'] ?? 'waiting');
        if (!$allowTerminal && !in_array($status, ['waiting', 'confirming'], true)) {
            throw new InvalidArgumentException($this->publicAccessDeniedMessage($status));
        }
        $progress = match ($status) {
            'success' => 100,
            'confirming' => min(99, max(1, (int)$order['confirmation_percent'])),
            default => 0,
        };

        return [
            'order_no' => $orderNo,
            'status' => $status,
            'progress' => $progress,
            'network_id' => (string)$order['network_code'],
            'token' => (string)$order['token_code'],
            'fiat_currency' => (string)($order['fiat_currency'] ?? ''),
            'token_amount' => (string)($order['token_amount'] ?: $order['amount_display']),
            'product_name' => $this->productNameForOrder($order),
            'address' => (string)$order['address'],
            'expire_at' => (string)$order['expire_at'],
            'return_url' => ($order['source'] ?? '') === 'epay'
                ? (new EasyPayService())->signedReturnUrlForDepositOrder($order)
                : (string)($order['return_url'] ?? ''),
            'return_url_signed' => ($order['source'] ?? '') === 'epay',
            'pay_url' => $this->payUrl($orderNo, $baseUrl),
        ];
    }

    private function appendOrderView(array $order, ?array $callbackLog = null, ?array $apiClient = null, ?array $epayOrder = null): array
    {
        $order = $this->appendConfirmationProgress($order);
        $order['token_amount'] = (string)($order['token_amount'] ?: ($order['amount_display'] ?? ''));
        $paidAmountInt = (string)($order['paid_amount_int'] ?? '0');
        $order['paid_amount_display'] = $paidAmountInt !== '' && $paidAmountInt !== '0'
            ? (new TokenAmountService())->toDisplay($paidAmountInt, $this->tokenDecimals((string)($order['token_code'] ?? 'USDC'))) . ' ' . ((string)($order['token_code'] ?? 'USDC') ?: 'USDC')
            : '';
        $order['callback_status'] = $this->callbackStatus($order, $callbackLog, $apiClient, $epayOrder);
        $order['callback_status_text'] = $this->callbackStatusText($order['callback_status']);
        $order['source_text'] = $this->sourceText((string)($order['source'] ?? ''));
        $order['status_text'] = $this->statusText((string)($order['status'] ?? ''));
        return $order;
    }

    private function appendConfirmationProgress(array $order): array
    {
        $required = (int)($order['required_confirmations'] ?? 0);
        if ($required <= 0) {
            $required = (int)(config('chains.networks.' . $order['network_code'] . '.confirm_blocks') ?: 0);
        }
        $current = (int)($order['current_confirmations'] ?? 0);
        if (($order['status'] ?? '') === 'success' && $required > 0 && $current <= 0) {
            $current = $required;
        }
        $current = $required > 0 ? min($required, $current) : $current;
        $percent = $required > 0 ? (int)floor($current * 100 / $required) : 0;
        if (($order['status'] ?? '') === 'success') {
            $percent = 100;
        }

        $order['confirmation_current'] = $current;
        $order['confirmation_required'] = $required;
        $order['confirmation_percent'] = min(100, max(0, $percent));
        return $order;
    }

    private function callbackStatus(array $order, ?array $callbackLog, ?array $apiClient, ?array $epayOrder = null): string
    {
        if (($order['source'] ?? '') === 'epay') {
            if (!$epayOrder) {
                return 'none';
            }
            return (string)($epayOrder['notify_status'] ?? 'pending');
        }
        if (($order['source'] ?? '') !== 'api' || (int)($order['api_client_id'] ?? 0) <= 0) {
            return 'none';
        }
        if (!$apiClient || trim((string)($apiClient['callback_url'] ?? '')) === '') {
            return 'none';
        }
        if (!$callbackLog) {
            return 'pending';
        }
        return (string)($callbackLog['status'] ?? 'pending');
    }

    private function callbackStatusText(string $status): string
    {
        return match ($status) {
            'success' => '已完成',
            'failed' => '失败',
            'pending' => '待回调',
            default => '-',
        };
    }

    private function sourceText(string $source): string
    {
        return match ($source) {
            'admin' => '后台',
            'api' => 'API',
            'epay' => 'api-epay',
            'frontend' => '前台',
            default => '-',
        };
    }

    private function statusText(string $status): string
    {
        return match ($status) {
            'waiting' => '等待支付',
            'confirming' => '确认中',
            'success' => '交易成功',
            'expired' => '已过期',
            'failed' => '失败',
            default => $status ?: '-',
        };
    }

    private function clientsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = OpenApiClient::findByIds($ids);
        $result = [];
        foreach ($rows as $row) {
            unset($row['api_secret_hash']);
            unset($row['api_secret_encrypted']);
            $result[(int)$row['id']] = $row;
        }
        return $result;
    }

    private function normalizeFilters(array $filters): array
    {
        if (isset($filters['status'])) {
            $filters['status'] = $this->normalizeStatus((string)$filters['status']);
        }
        return $filters;
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'assigned' => 'waiting',
            'paid_detected', 'confirming' => 'confirming',
            'confirmed' => 'success',
            default => $status,
        };
    }

    private function normalizeSource(string $source): string
    {
        return in_array($source, ['admin', 'frontend', 'api', 'epay'], true) ? $source : 'frontend';
    }

    private function orderPayload(array $order, int $timeoutMinutes = 0, string $baseUrl = ''): array
    {
        return [
            'order_no' => $order['order_no'],
            'network' => $order['network_code'],
            'network_id' => $order['network_code'],
            'token' => $order['token_code'],
            'fiat_currency' => $order['fiat_currency'] ?? '',
            'token_amount' => $order['token_amount'] ?: ($order['amount_display'] ?? ''),
            'product_name' => $this->productNameForOrder($order),
            'amount_int' => $order['amount_int'],
            'address' => $order['address'],
            'expire_at' => $order['expire_at'],
            'return_url' => $order['return_url'] ?? '',
            'pay_url' => $this->payUrl((string)$order['order_no'], $baseUrl),
            'timeout_minutes' => $timeoutMinutes,
        ];
    }

    private function productNameForOrder(array $order): string
    {
        if (($order['source'] ?? '') !== 'epay') {
            return '';
        }
        $epayOrder = EasyPayOrder::findByDepositOrderNo((string)($order['order_no'] ?? ''));
        return $epayOrder ? (string)($epayOrder['name'] ?? '') : '';
    }

    private function refreshExpiredOrder(array $order): array
    {
        if (($order['status'] ?? '') !== 'waiting') {
            return $order;
        }
        $expireAt = trim((string)($order['expire_at'] ?? ''));
        $expireTimestamp = $expireAt !== '' ? strtotime($expireAt) : false;
        if ($expireTimestamp === false || time() <= $expireTimestamp) {
            return $order;
        }
        DepositOrder::markExpired((string)$order['order_no']);
        if (!empty($order['address_id'])) {
            PaymentAddress::freezeExpired((int)$order['address_id']);
        }
        return DepositOrder::findByOrderNo((string)$order['order_no']) ?: array_merge($order, ['status' => 'expired']);
    }

    private function publicAccessDeniedMessage(string $status): string
    {
        return match ($status) {
            'success' => '订单已完成，不能继续访问支付页面',
            'expired' => '订单已过期，不能继续访问支付页面',
            'failed' => '订单状态异常，不能继续访问支付页面',
            default => '订单当前状态不允许访问支付页面',
        };
    }

    private function normalizeReturnUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strlen($url) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new InvalidArgumentException('同步跳转地址格式不正确');
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('同步跳转地址必须是 http 或 https 地址');
        }
        return $url;
    }

    private function payUrl(string $orderNo, string $baseUrl = ''): string
    {
        $path = '/pay?order_no=' . rawurlencode($orderNo);
        $baseUrl = rtrim(trim($baseUrl), '/');
        return $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    private function makeOrderNo(): string
    {
        return 'D' . date('YmdHis') . random_int(100000, 999999);
    }

    private function depositTimeoutMinutes(int $walletAccountId): int
    {
        $account = $walletAccountId > 0 ? WalletAccount::findById($walletAccountId) : null;
        $minutes = (int)($account['deposit_timeout_minutes'] ?? 10);
        return min(1440, max(1, $minutes));
    }

    private function tokenDecimals(string $tokenCode): int
    {
        return (int)(config('chains.tokens.' . strtoupper($tokenCode) . '.decimals') ?: 6);
    }
}
