<?php

namespace app\service;

use app\model\ProxyPool;
use app\model\RpcConfig;
use app\model\SystemSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Guzzle\ClientFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;

class ProxyPoolService
{
    private const TYPES = ['http', 'https', 'socks5', 'socks5h'];

    public function list(): array
    {
        $items = ProxyPool::listPage([], 1, 100, 'id', 'desc')['items'];
        foreach ($items as &$item) {
            $item = $this->sanitize($item);
        }
        return $items;
    }

    public function enabledList(): array
    {
        $items = ProxyPool::enabledList();
        foreach ($items as &$item) {
            $item = $this->sanitize($item);
        }
        return $items;
    }

    public function save(array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        $exists = $id > 0 ? ProxyPool::findById($id) : [];
        if ($id > 0 && !$exists) {
            throw new InvalidArgumentException('代理不存在');
        }

        $proxyType = strtolower(trim((string)($input['proxy_type'] ?? ($exists['proxy_type'] ?? ''))));
        if (!in_array($proxyType, self::TYPES, true)) {
            throw new InvalidArgumentException('代理类型只能是 http、https、socks5 或 socks5h');
        }

        $host = trim((string)($input['host'] ?? ($exists['host'] ?? '')));
        $host = preg_replace('#^https?://#i', '', $host);
        $host = preg_replace('#^socks5h?://#i', '', (string)$host);
        $host = trim((string)$host, '/');
        if ($host === '') {
            throw new InvalidArgumentException('代理地址不能为空');
        }

        $port = (int)($input['port'] ?? ($exists['port'] ?? 0));
        if ($port <= 0 || $port > 65535) {
            throw new InvalidArgumentException('代理端口无效');
        }

        $name = trim((string)($input['name'] ?? ($exists['name'] ?? '')));
        if ($name === '') {
            $name = strtoupper($proxyType) . ' ' . $host . ':' . $port;
        }

        $username = trim((string)($input['username'] ?? ($exists['username'] ?? '')));
        $password = (string)($input['password'] ?? '');
        $data = [
            'name' => $name,
            'proxy_type' => $proxyType,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'status' => !empty($input['status']) && $input['status'] === 'disabled' ? 'disabled' : ($exists['status'] ?? 'enabled'),
        ];

        $crypto = new CryptoService();
        if ($password !== '') {
            $data['password_cipher'] = $crypto->encrypt($password);
            $data['password_masked'] = $crypto->mask($password);
        } elseif (!$exists || $username === '') {
            $data['password_cipher'] = $exists['password_cipher'] ?? '';
            $data['password_masked'] = $exists['password_masked'] ?? '';
            if ($username === '') {
                $data['password_cipher'] = '';
                $data['password_masked'] = '';
            }
        }

        if ($id > 0) {
            ProxyPool::updateById($id, $data);
            return $this->sanitize(ProxyPool::findById($id) ?: []);
        }

        return $this->sanitize(ProxyPool::createRecord($data));
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('代理 ID 无效');
        }
        if (RpcConfig::countByProxyId($id) > 0) {
            throw new RuntimeException('该代理正在被 RPC 配置使用，请先把对应 RPC 改成直连或其他代理');
        }
        if ((int)SystemSetting::getValue('fiat_rate_proxy_id', '0') === $id) {
            throw new RuntimeException('该代理正在被汇率接口设置使用，请先把汇率接口改成直连或其他代理');
        }
        return ProxyPool::deleteById($id) > 0;
    }

    public function toggle(int $id, string $status): array
    {
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new InvalidArgumentException('代理状态无效');
        }
        if (!ProxyPool::findById($id)) {
            throw new InvalidArgumentException('代理不存在');
        }
        ProxyPool::markStatus($id, $status);
        return $this->sanitize(ProxyPool::findById($id) ?: []);
    }

    public function test(int $id, string $testUrl = ''): array
    {
        $proxy = $this->runtimeById($id, false);
        $testUrl = trim($testUrl) ?: 'https://api.ipify.org?format=json';
        $steps = [
            $this->step('读取代理配置', 'finish', $this->proxyLabel($proxy)),
            $this->step('通过代理请求测试地址', 'process', $testUrl),
            $this->step('代理测试完成', 'wait', ''),
        ];

        try {
            $options = [
                'http_errors' => false,
                'timeout' => 12,
                'connect_timeout' => 6,
            ];
            $this->applyToOptions($options, $proxy);
            $response = $this->httpClient()->get($testUrl, $options);
            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException('测试地址返回 HTTP 状态码 ' . $statusCode);
            }
            $steps[1]['status'] = 'finish';
            $steps[1]['message'] = '代理连接成功，测试地址已返回响应';
            $steps[2]['status'] = 'finish';
            $steps[2]['message'] = '代理可用';
            ProxyPool::updateTestResult($id, 'success', '代理测试成功');
            return ['ok' => true, 'steps' => $steps, 'http_status' => $statusCode];
        } catch (GuzzleException|RuntimeException $e) {
            $steps[1]['status'] = 'error';
            $steps[1]['message'] = '代理请求失败：' . $this->safeError($e->getMessage());
            $steps[2]['status'] = 'error';
            $steps[2]['message'] = '代理不可用';
            ProxyPool::updateTestResult($id, 'failed', $steps[1]['message']);
            return ['ok' => false, 'steps' => $steps, 'error' => $steps[1]['message']];
        }
    }

    public function runtimeById(int $id, bool $requireEnabled = true, string $scene = 'RPC'): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('代理 ID 无效');
        }
        $proxy = ProxyPool::findById($id);
        if (!$proxy) {
            throw new RuntimeException($scene . ' 绑定的代理不存在，已按要求停止请求，未回退直连');
        }
        if ($requireEnabled && $proxy['status'] !== 'enabled') {
            throw new RuntimeException($scene . ' 绑定的代理已禁用，已按要求停止请求，未回退直连');
        }
        $proxy['password'] = (new CryptoService())->decrypt($proxy['password_cipher'] ?? '');
        return $proxy;
    }

    public function applyToOptions(array &$options, array $proxy): void
    {
        $options['proxy'] = $this->proxyUrl($proxy);
    }

    public function proxyLabel(array $proxy): string
    {
        return strtoupper((string)$proxy['proxy_type']) . ' ' . $proxy['host'] . ':' . $proxy['port'];
    }

    private function proxyUrl(array $proxy): string
    {
        $auth = '';
        if (!empty($proxy['username'])) {
            $auth = rawurlencode((string)$proxy['username']);
            if (!empty($proxy['password'])) {
                $auth .= ':' . rawurlencode((string)$proxy['password']);
            }
            $auth .= '@';
        }
        return strtolower((string)$proxy['proxy_type']) . '://' . $auth . $proxy['host'] . ':' . (int)$proxy['port'];
    }

    private function sanitize(array $proxy): array
    {
        if (!$proxy) {
            return [];
        }
        $proxy['password_cipher'] = '';
        $proxy['password'] = '';
        return $proxy;
    }

    private function step(string $title, string $status, string $message): array
    {
        return ['title' => $title, 'status' => $status, 'message' => $message];
    }

    private function safeError(string $message): string
    {
        if ($message === '') {
            return '未知错误';
        }
        return substr($message, 0, 300);
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
