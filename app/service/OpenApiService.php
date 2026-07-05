<?php

namespace app\service;

use app\model\DepositOrder;
use app\model\OpenApiCallbackLog;
use app\model\OpenApiClient;
use GuzzleHttp\Client;
use Hyperf\Guzzle\ClientFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

class OpenApiService
{
    public function list(array $filters, int $page, int $perPage): array
    {
        $result = OpenApiClient::searchPage($filters, $page, $perPage);
        foreach ($result['items'] as &$item) {
            $item = $this->sanitizeClient($item);
        }
        return $result;
    }

    public function save(array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        $exists = $id > 0 ? OpenApiClient::findById($id) : [];
        if ($id > 0 && !$exists) {
            throw new InvalidArgumentException('API 信息不存在');
        }

        $name = trim((string)($input['name'] ?? ($exists['name'] ?? '')));
        if ($name === '') {
            throw new InvalidArgumentException('API 名称不能为空');
        }

        $callbackUrl = trim((string)($input['callback_url'] ?? ($exists['callback_url'] ?? '')));
        if ($callbackUrl !== '' && !filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('回调地址格式不正确');
        }

        $ipWhitelist = $this->normalizeIpWhitelist((string)($input['ip_whitelist'] ?? ($exists['ip_whitelist'] ?? '0.0.0.0')));
        $status = (string)($exists['status'] ?? 'enabled');
        if (array_key_exists('status', $input) && $input['status'] !== '') {
            $status = (string)$input['status'];
            if (!in_array($status, ['enabled', 'disabled'], true)) {
                throw new InvalidArgumentException('API 状态无效');
            }
        } elseif (array_key_exists('enabled', $input)) {
            $status = !empty($input['enabled']) ? 'enabled' : 'disabled';
        }

        $data = [
            'name' => $name,
            'callback_url' => $callbackUrl,
            'ip_whitelist' => $ipWhitelist,
            'status' => $status,
        ];

        if ($id > 0) {
            OpenApiClient::updateById($id, $data);
            return $this->sanitizeClient(OpenApiClient::findById($id) ?: []);
        }

        $apiKey = 'ak_' . bin2hex(random_bytes(16));
        $apiSecret = $this->makeApiSecret();
        $data['api_key'] = $apiKey;
        $data['api_secret_hash'] = password_hash($apiSecret, PASSWORD_DEFAULT);
        $data['api_secret_encrypted'] = (new CryptoService())->encrypt($apiSecret);

        $created = $this->sanitizeClient(OpenApiClient::createRecord($data));
        $created['api_secret'] = $apiSecret;
        return $created;
    }

    public function resetSecret(int $id): array
    {
        $client = OpenApiClient::findById($id);
        if (!$client) {
            throw new InvalidArgumentException('API 信息不存在');
        }

        $apiSecret = $this->makeApiSecret();
        OpenApiClient::updateSecret(
            $id,
            password_hash($apiSecret, PASSWORD_DEFAULT),
            (new CryptoService())->encrypt($apiSecret)
        );

        $updated = $this->sanitizeClient(OpenApiClient::findById($id) ?: []);
        $updated['api_secret'] = $apiSecret;
        return $updated;
    }

    public function toggle(int $id, string $status): array
    {
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new InvalidArgumentException('API 状态无效');
        }
        if (!OpenApiClient::findById($id)) {
            throw new InvalidArgumentException('API 信息不存在');
        }
        OpenApiClient::markStatus($id, $status);
        return $this->sanitizeClient(OpenApiClient::findById($id) ?: []);
    }

    public function delete(int $id): bool
    {
        if (!OpenApiClient::findById($id)) {
            throw new InvalidArgumentException('API 信息不存在');
        }
        return OpenApiClient::deleteById($id) > 0;
    }

    public function networks(array $input, string $ip): array
    {
        $this->authenticate($input, $ip);
        return $this->networkPayload();
    }

    public function createOrder(array $input, string $ip, string $baseUrl): array
    {
        $client = $this->authenticate($input, $ip);
        $network = trim((string)($input['network_id'] ?? ($input['network'] ?? '')));
        $order = (new DepositService())->create([
            'network' => $network,
            'token' => (string)($input['token'] ?? 'USDC'),
            'fiat_currency' => (string)($input['fiat_currency'] ?? ''),
            'fiat_amount' => (string)($input['fiat_amount'] ?? ''),
            'return_url' => (string)($input['return_url'] ?? ''),
            'source' => 'api',
            'source_ip' => $ip,
            'api_client_id' => (int)$client['id'],
            'base_url' => $baseUrl,
        ]);
        return $this->orderPayload($order);
    }

    public function orderStatus(array $input, string $ip, string $baseUrl): array
    {
        $client = $this->authenticate($input, $ip);
        $orderNo = trim((string)($input['order_no'] ?? ''));
        if ($orderNo === '') {
            throw new InvalidArgumentException('订单号不能为空');
        }
        $order = DepositOrder::findByOrderNo($orderNo);
        if (!$order || (int)($order['api_client_id'] ?? 0) !== (int)$client['id']) {
            throw new InvalidArgumentException('订单不存在');
        }
        return (new DepositService())->publicStatus($orderNo, '', true, $baseUrl, false);
    }

    public function sendCallbackForOrder(array $order): void
    {
        try {
            if (($order['source'] ?? '') === 'epay') {
                (new EasyPayService())->sendNotifyForDepositOrder($order, false);
                return;
            }
            $this->dispatchCallback($order, false);
        } catch (Throwable) {
            // 自动回调失败已经写入回调日志，不中断监听流程。
        }
    }

    public function callbackOrder(string $orderNo): array
    {
        $order = DepositOrder::findByOrderNo($orderNo);
        if (!$order) {
            throw new InvalidArgumentException('订单不存在');
        }
        if (($order['source'] ?? '') === 'epay') {
            return (new EasyPayService())->sendNotifyForDepositOrder($order, true);
        }
        return $this->dispatchCallback($order, true);
    }

    private function dispatchCallback(array $order, bool $manual): array
    {
        $clientId = (int)($order['api_client_id'] ?? 0);
        if ($clientId <= 0) {
            if ($manual) {
                throw new InvalidArgumentException('该订单不是 API 订单，不能回调');
            }
            return ['skipped' => true, 'reason' => 'not_api_order'];
        }
        $client = OpenApiClient::findById($clientId);
        if (!$client || ($client['status'] ?? '') !== 'enabled') {
            if ($manual) {
                throw new InvalidArgumentException('API 信息不存在或已禁用');
            }
            return ['skipped' => true, 'reason' => 'api_disabled'];
        }
        $callbackUrl = trim((string)($client['callback_url'] ?? ''));
        if ($callbackUrl === '') {
            if ($manual) {
                throw new InvalidArgumentException('该 API 未设置回调地址');
            }
            return ['skipped' => true, 'reason' => 'empty_callback_url'];
        }
        if (($order['status'] ?? '') !== 'success') {
            if ($manual) {
                throw new InvalidArgumentException('只有交易成功的 API 订单才能回调');
            }
            return ['skipped' => true, 'reason' => 'order_not_success'];
        }

        $exists = OpenApiCallbackLog::findByClientAndOrder($clientId, (string)$order['order_no']);
        if ($exists && ($exists['status'] ?? '') === 'success') {
            if ($manual) {
                throw new InvalidArgumentException('该订单回调已完成，不能重复回调');
            }
            return ['skipped' => true, 'reason' => 'callback_success'];
        }

        $body = [
            'order_no' => (string)$order['order_no'],
            'status' => 'success',
        ];
        $log = $exists ?: OpenApiCallbackLog::createRecord([
            'client_id' => $clientId,
            'order_no' => (string)$order['order_no'],
            'callback_url' => $callbackUrl,
            'request_body' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        try {
            $response = $this->httpClient()->post($callbackUrl, [
                'http_errors' => false,
                'timeout' => 10,
                'connect_timeout' => 5,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);
            $statusCode = $response->getStatusCode();
            $responseBody = substr((string)$response->getBody(), 0, 2000);
            OpenApiCallbackLog::updateResult((int)$log['id'], [
                'http_status' => $statusCode,
                'response_body' => $responseBody,
                'status' => $statusCode >= 200 && $statusCode < 300 ? 'success' : 'failed',
                'retry_count' => (int)($log['retry_count'] ?? 0) + 1,
                'error_message' => $statusCode >= 200 && $statusCode < 300 ? '' : ('HTTP 状态码 ' . $statusCode),
                'last_called_at' => date('Y-m-d H:i:s'),
            ]);
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException('回调接口返回 HTTP 状态码 ' . $statusCode);
            }
            return ['ok' => true, 'http_status' => $statusCode, 'response_body' => $responseBody];
        } catch (Throwable $e) {
            OpenApiCallbackLog::updateResult((int)$log['id'], [
                'status' => 'failed',
                'retry_count' => (int)($log['retry_count'] ?? 0) + 1,
                'error_message' => substr($e->getMessage(), 0, 1000),
                'last_called_at' => date('Y-m-d H:i:s'),
            ]);
            if ($manual) {
                throw new RuntimeException('回调失败：' . $e->getMessage());
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function authenticate(array $input, string $ip): array
    {
        $apiKey = trim((string)($input['api_key'] ?? ''));
        $apiSecret = trim((string)($input['api_secret'] ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            throw new InvalidArgumentException('缺少 API Key 或 API Key Secret');
        }

        $client = OpenApiClient::findByApiKey($apiKey);
        if (!$client || ($client['status'] ?? '') !== 'enabled') {
            throw new InvalidArgumentException('API Key 无效或已禁用');
        }
        if (!password_verify($apiSecret, (string)($client['api_secret_hash'] ?? ''))) {
            throw new InvalidArgumentException('API Key Secret 不正确');
        }

        if (!$this->ipAllowed($ip, (string)($client['ip_whitelist'] ?? '0.0.0.0'))) {
            throw new InvalidArgumentException('当前 IP 不在白名单内');
        }

        OpenApiClient::updateLastUsed((int)$client['id'], $ip);
        return $client;
    }


    private function networkPayload(): array
    {
        return array_map(static fn(array $item) => [
            'network_id' => $item['value'],
            'network_name' => $item['label'],
            'token' => $item['tokens'][0] ?? 'USDC',
            'tokens' => $item['tokens'] ?? ['USDC', 'USDT'],
        ], (new DepositService())->networks());
    }

    private function orderPayload(array $order): array
    {
        return [
            'order_no' => $order['order_no'],
            'network_id' => $order['network_id'] ?? $order['network'],
            'token' => $order['token'] ?? 'USDC',
            'fiat_currency' => $order['fiat_currency'] ?? '',
            'token_amount' => $order['token_amount'] ?? $order['amount'],
            'address' => $order['address'],
            'expire_at' => $order['expire_at'],
            'pay_url' => $order['pay_url'] ?? '',
        ];
    }

    private function normalizeIpWhitelist(string $value): string
    {
        $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []))));
        if (!$items) {
            return '0.0.0.0';
        }
        if (in_array('0.0.0.0', $items, true) && count($items) > 1) {
            throw new InvalidArgumentException('0.0.0.0 表示允许全部 IP，不能和具体 IP 同时填写');
        }
        foreach ($items as $ip) {
            if ($ip === '0.0.0.0') {
                return '0.0.0.0';
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException('IP 白名单格式不正确');
            }
        }
        return implode(',', $items);
    }

    private function ipAllowed(string $ip, string $whitelist): bool
    {
        $items = array_filter(array_map('trim', explode(',', $whitelist)));
        if (!$items || in_array('0.0.0.0', $items, true)) {
            return true;
        }
        return in_array($ip, $items, true);
    }

    private function sanitizeClient(array $client): array
    {
        if (!$client) {
            return [];
        }
        unset($client['api_secret_hash']);
        unset($client['api_secret_encrypted']);
        return $client;
    }

    private function makeApiSecret(): string
    {
        return 'sk_' . bin2hex(random_bytes(24));
    }

    private function httpClient(): Client
    {
        static $factory = null;
        if ($factory === null) {
            $factory = new ClientFactory(new class implements ContainerInterface {
                public function get(string $id)
                {
                    throw new RuntimeException('容器未配置服务：' . $id);
                }

                public function has(string $id): bool
                {
                    return false;
                }
            });
        }

        return $factory->create([]);
    }
}
