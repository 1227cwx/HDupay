<?php

namespace app\service;

use app\model\DepositOrder;
use app\model\EasyPayOrder;
use app\model\OpenApiClient;
use GuzzleHttp\Client;
use Hyperf\Guzzle\ClientFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use support\Request;
use Throwable;

class EasyPayService
{
    public function __construct()
    {
        (new OpenApiSchemaService())->ensure();
        (new EasyPaySchemaService())->ensure();
        (new DepositOrderSchemaService())->ensure();
    }

    public function submit(Request $request, array $input): string
    {
        $params = $this->normalizeParams($input);
        $client = $this->clientByPid((string)($params['pid'] ?? ''));
        $secret = $this->clientSecret($client);

        if (strtoupper((string)($params['sign_type'] ?? 'MD5')) !== 'MD5') {
            throw new InvalidArgumentException('当前只支持 MD5 签名');
        }
        if (!(new EasyPaySignatureService())->verify($params, $secret)) {
            throw new InvalidArgumentException('签名校验失败');
        }

        $this->validateSubmitParams($params, $client);
        $returnUrl = $this->normalizeOptionalReturnUrl((string)($params['return_url'] ?? ''));
        OpenApiClient::updateLastUsed((int)$client['id'], $request->getRealIp(false));

        $existing = EasyPayOrder::findByClientAndOutTradeNo((int)$client['id'], (string)$params['out_trade_no']);
        if ($existing) {
            return $this->payUrl((string)$existing['epay_order_no'], (new PublicUrlService())->publicBaseUrl($request));
        }

        $storedParams = $params;
        $storedParams['return_url'] = $returnUrl;
        unset($storedParams['type']);
        $order = EasyPayOrder::createRecord([
            'api_client_id' => (int)$client['id'],
            'api_secret_encrypted' => (string)($client['api_secret_encrypted'] ?? ''),
            'epay_order_no' => $this->makeEpayOrderNo(),
            'out_trade_no' => (string)$params['out_trade_no'],
            'deposit_order_no' => '',
            'name' => (string)($params['name'] ?? ''),
            'money' => (string)$params['money'],
            'notify_url' => $this->effectiveNotifyUrlForSubmit($params, $client),
            'return_url' => $returnUrl,
            'request_params' => json_encode($storedParams, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'notify_status' => 'pending',
            'notify_count' => 0,
        ]);

        return $this->payUrl((string)$order['epay_order_no'], (new PublicUrlService())->publicBaseUrl($request));
    }

    public function publicDetail(string $epayOrderNo): array
    {
        $order = $this->activeEasyPayOrder($epayOrderNo);
        $depositOrderNo = (string)($order['deposit_order_no'] ?? '');
        if ($depositOrderNo !== '') {
            $depositOrder = DepositOrder::findByOrderNo($depositOrderNo);
            if ($depositOrder) {
                $status = (string)($depositOrder['status'] ?? '');
                if (!in_array($status, ['waiting', 'confirming'], true)) {
                    throw new InvalidArgumentException($this->terminalAccessMessage($status));
                }
            }
        }

        return [
            'epay_order_no' => (string)$order['epay_order_no'],
            'out_trade_no' => (string)$order['out_trade_no'],
            'name' => (string)$order['name'],
            'money' => (string)$order['money'],
            'fiat_currency' => 'CNY',
            'deposit_order_no' => $depositOrderNo,
            'status' => (string)$order['status'],
        ];
    }

    public function existingDepositOrderForCreate(string $epayOrderNo): ?array
    {
        $order = $this->activeEasyPayOrder($epayOrderNo);
        $depositOrderNo = (string)($order['deposit_order_no'] ?? '');
        if ($depositOrderNo === '') {
            return null;
        }

        $depositOrder = DepositOrder::findByOrderNo($depositOrderNo);
        if (!$depositOrder) {
            return null;
        }
        if (!in_array((string)($depositOrder['status'] ?? ''), ['waiting', 'confirming'], true)) {
            throw new InvalidArgumentException($this->terminalAccessMessage((string)($depositOrder['status'] ?? '')));
        }
        return $depositOrder;
    }

    public function applyToDepositInput(string $epayOrderNo, array $input): array
    {
        $order = $this->activeEasyPayOrder($epayOrderNo);
        if ((string)($order['deposit_order_no'] ?? '') !== '') {
            throw new InvalidArgumentException('易支付订单已创建收款地址');
        }

        $input['source'] = 'epay';
        $input['api_client_id'] = (int)$order['api_client_id'];
        $input['fiat_currency'] = 'CNY';
        $input['fiat_amount'] = (string)$order['money'];
        $returnUrl = $this->normalizeOptionalReturnUrl((string)($order['return_url'] ?? ''));
        if ($returnUrl === '') {
            $returnUrl = $this->normalizeOptionalReturnUrl((string)($input['fallback_return_url'] ?? ''));
        }
        $input['return_url'] = $returnUrl;
        return $input;
    }

    public function attachDepositOrder(string $epayOrderNo, string $depositOrderNo): void
    {
        $order = EasyPayOrder::findByEpayOrderNo($epayOrderNo);
        if (!$order) {
            throw new InvalidArgumentException('易支付订单不存在');
        }
        EasyPayOrder::attachDepositOrder((int)$order['id'], $depositOrderNo);
    }

    public function sendNotifyForDepositOrder(array $depositOrder, bool $manual = false): array
    {
        $epayOrder = EasyPayOrder::findByDepositOrderNo((string)$depositOrder['order_no']);
        if (!$epayOrder) {
            if ($manual) {
                throw new InvalidArgumentException('易支付订单不存在');
            }
            return ['skipped' => true, 'reason' => 'not_easypay_order'];
        }
        if (($depositOrder['status'] ?? '') !== 'success') {
            if ($manual) {
                throw new InvalidArgumentException('只有交易成功的易支付订单才能回调');
            }
            return ['skipped' => true, 'reason' => 'order_not_success'];
        }
        if (($epayOrder['notify_status'] ?? '') === 'success') {
            if ($manual) {
                throw new InvalidArgumentException('该易支付订单回调已完成，不能重复回调');
            }
            return ['skipped' => true, 'reason' => 'notify_success'];
        }

        $client = OpenApiClient::findById((int)$epayOrder['api_client_id']);
        if (!$client || ($client['status'] ?? '') !== 'enabled') {
            if ($manual) {
                throw new InvalidArgumentException('API 信息不存在或已禁用');
            }
            return ['skipped' => true, 'reason' => 'api_disabled'];
        }
        $notifyUrl = $this->effectiveNotifyUrlForNotify($epayOrder, $client);
        if ($notifyUrl === '') {
            if ($manual) {
                throw new InvalidArgumentException('易支付订单未设置异步回调地址');
            }
            return ['skipped' => true, 'reason' => 'empty_notify_url'];
        }

        $secret = $this->orderSecret($epayOrder, $client);
        EasyPayOrder::markSuccess((int)$epayOrder['id']);
        $params = $this->callbackParams($client, $epayOrder, $secret);

        try {
            $response = $this->httpClient()->post($notifyUrl, [
                'http_errors' => false,
                'timeout' => 10,
                'connect_timeout' => 5,
                'form_params' => $params,
            ]);
        } catch (Throwable $e) {
            EasyPayOrder::updateNotifyResult((int)$epayOrder['id'], [
                'notify_status' => 'failed',
                'notify_count' => (int)($epayOrder['notify_count'] ?? 0) + 1,
                'notify_error' => substr($e->getMessage(), 0, 1000),
                'last_notified_at' => date('Y-m-d H:i:s'),
            ]);
            if ($manual) {
                throw new RuntimeException('易支付回调失败：' . $e->getMessage());
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $statusCode = $response->getStatusCode();
        $responseBody = substr((string)$response->getBody(), 0, 2000);
        $success = $statusCode >= 200 && $statusCode < 300 && trim($responseBody) === 'success';
        EasyPayOrder::updateNotifyResult((int)$epayOrder['id'], [
            'notify_status' => $success ? 'success' : 'failed',
            'notify_count' => (int)($epayOrder['notify_count'] ?? 0) + 1,
            'notify_response' => $responseBody,
            'notify_error' => $success ? '' : ('HTTP 状态码 ' . $statusCode . '，返回：' . $responseBody),
            'last_notified_at' => date('Y-m-d H:i:s'),
        ]);
        if (!$success) {
            $message = '易支付回调失败，HTTP 状态码 ' . $statusCode . '，返回：' . $responseBody;
            if ($manual) {
                throw new RuntimeException($message);
            }
            return ['ok' => false, 'error' => $message];
        }
        return ['ok' => true, 'http_status' => $statusCode, 'response_body' => $responseBody];
    }

    public function returnUrlForDepositOrder(array $depositOrder): array
    {
        $epayOrder = EasyPayOrder::findByDepositOrderNo((string)($depositOrder['order_no'] ?? ''));
        if (!$epayOrder) {
            return [
                'return_url' => (string)($depositOrder['return_url'] ?? ''),
                'return_url_signed' => false,
                'return_url_append_status' => false,
                'return_url_close_on_empty' => false,
            ];
        }

        $returnUrl = $this->normalizeOptionalReturnUrl((string)($epayOrder['return_url'] ?? ''));
        if ($returnUrl !== '') {
            return [
                'return_url' => $this->buildSignedReturnUrl($returnUrl, $epayOrder),
                'return_url_signed' => true,
                'return_url_append_status' => false,
                'return_url_close_on_empty' => true,
            ];
        }

        return [
            'return_url' => trim((string)($depositOrder['return_url'] ?? '')),
            'return_url_signed' => false,
            'return_url_append_status' => false,
            'return_url_close_on_empty' => true,
        ];
    }

    public function signedReturnUrlForDepositOrder(array $depositOrder): string
    {
        return (string)$this->returnUrlForDepositOrder($depositOrder)['return_url'];
    }

    private function buildSignedReturnUrl(string $returnUrl, array $epayOrder): string
    {
        try {
            $client = OpenApiClient::findById((int)$epayOrder['api_client_id']);
            if (!$client) {
                return $returnUrl;
            }
            $params = $this->callbackParams($client, $epayOrder, $this->orderSecret($epayOrder, $client));
            $separator = str_contains($returnUrl, '?') ? '&' : '?';
            return $returnUrl . $separator . http_build_query($params);
        } catch (Throwable) {
            return $returnUrl;
        }
    }

    private function callbackParams(array $client, array $epayOrder, string $secret): array
    {
        return (new EasyPaySignatureService())->signedParams([
            'pid' => (string)$client['api_key'],
            'trade_no' => (string)$epayOrder['epay_order_no'],
            'out_trade_no' => (string)$epayOrder['out_trade_no'],
            'name' => (string)($epayOrder['name'] ?? ''),
            'money' => (string)$epayOrder['money'],
            'trade_status' => 'TRADE_SUCCESS',
        ], $secret);
    }

    private function activeEasyPayOrder(string $epayOrderNo): array
    {
        $epayOrderNo = trim($epayOrderNo);
        if ($epayOrderNo === '') {
            throw new InvalidArgumentException('易支付订单号不能为空');
        }
        $order = EasyPayOrder::findByEpayOrderNo($epayOrderNo);
        if (!$order) {
            throw new InvalidArgumentException('易支付订单不存在');
        }
        if (!in_array((string)($order['status'] ?? ''), ['pending', 'paying'], true)) {
            throw new InvalidArgumentException($this->terminalAccessMessage((string)($order['status'] ?? '')));
        }
        return $order;
    }

    private function clientByPid(string $pid): array
    {
        $pid = trim($pid);
        if ($pid === '') {
            throw new InvalidArgumentException('签名校验失败');
        }
        $client = OpenApiClient::findByApiKey($pid);
        if (!$client || ($client['status'] ?? '') !== 'enabled') {
            throw new InvalidArgumentException('签名校验失败');
        }
        return $client;
    }

    private function clientSecret(array $client): string
    {
        $encrypted = trim((string)($client['api_secret_encrypted'] ?? ''));
        if ($encrypted === '') {
            throw new InvalidArgumentException('签名校验失败');
        }
        $secret = (new CryptoService())->decrypt($encrypted);
        if ($secret === '') {
            throw new InvalidArgumentException('签名校验失败');
        }
        return $secret;
    }

    private function orderSecret(array $epayOrder, array $client): string
    {
        $encrypted = trim((string)($epayOrder['api_secret_encrypted'] ?? ''));
        if ($encrypted !== '') {
            return (new CryptoService())->decrypt($encrypted);
        }
        return $this->clientSecret($client);
    }

    private function validateSubmitParams(array $params, array $client): void
    {
        foreach (['out_trade_no', 'name', 'money'] as $field) {
            if (trim((string)($params[$field] ?? '')) === '') {
                throw new InvalidArgumentException('缺少易支付参数：' . $field);
            }
        }
        if ($this->clientCallbackUrl($client) === '' && trim((string)($params['notify_url'] ?? '')) === '') {
            throw new InvalidArgumentException('缺少易支付参数：notify_url');
        }
        if (!$this->validMoney((string)$params['money'])) {
            throw new InvalidArgumentException('易支付金额格式不正确');
        }
        if ($this->clientCallbackUrl($client) === '') {
            $this->validateUrl((string)$params['notify_url'], '异步回调地址');
        }
        if (strlen((string)$params['out_trade_no']) > 128) {
            throw new InvalidArgumentException('商户订单号过长');
        }
    }

    private function effectiveNotifyUrlForSubmit(array $params, array $client): string
    {
        $callbackUrl = $this->clientCallbackUrl($client);
        return $callbackUrl !== '' ? $callbackUrl : trim((string)($params['notify_url'] ?? ''));
    }

    private function effectiveNotifyUrlForNotify(array $epayOrder, array $client): string
    {
        $callbackUrl = $this->clientCallbackUrl($client);
        return $callbackUrl !== '' ? $callbackUrl : trim((string)($epayOrder['notify_url'] ?? ''));
    }

    private function clientCallbackUrl(array $client): string
    {
        return trim((string)($client['callback_url'] ?? ''));
    }

    private function validMoney(string $money): bool
    {
        $money = trim($money);
        if (!preg_match('/^\d+(\.\d{1,18})?$/', $money)) {
            return false;
        }
        return (float)$money > 0;
    }

    private function validateUrl(string $url, string $label): void
    {
        if (strlen($url) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new InvalidArgumentException($label . '格式不正确');
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException($label . '必须是 http 或 https 地址');
        }
    }

    private function normalizeOptionalReturnUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strlen($url) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return '';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        return $url;
    }

    private function normalizeParams(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $result[(string)$key] = trim((string)$value);
        }
        return $result;
    }

    private function payUrl(string $epayOrderNo, string $baseUrl = ''): string
    {
        $path = '/pay?epay_order=' . rawurlencode($epayOrderNo);
        $baseUrl = rtrim(trim($baseUrl), '/');
        return $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    private function makeEpayOrderNo(): string
    {
        return 'EP' . date('YmdHis') . random_int(100000, 999999);
    }

    private function terminalAccessMessage(string $status): string
    {
        return match ($status) {
            'success' => '订单已完成，不能继续访问支付页面',
            'expired' => '订单已过期，不能继续访问支付页面',
            'failed' => '订单状态异常，不能继续访问支付页面',
            default => '订单当前状态不允许访问支付页面',
        };
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
