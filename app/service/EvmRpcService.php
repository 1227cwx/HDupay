<?php

namespace app\service;

use app\model\RpcConfig;
use app\model\RpcGroup;
use app\model\NetworkToken;
use app\model\RpcNetworkSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Guzzle\ClientFactory;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

class EvmRpcService
{
    private static array $sequenceIndexes = [];

    public function getChainId(string $networkCode): int
    {
        return (new EvmHexService())->quantityToInt($this->request($networkCode, 'eth_chainId'));
    }

    public function getBlockNumber(string $networkCode): int
    {
        return (new EvmHexService())->quantityToInt($this->request($networkCode, 'eth_blockNumber'));
    }

    public function getBlockTimestamp(string $networkCode, int $blockNumber): int
    {
        $hex = new EvmHexService();
        $block = $this->request($networkCode, 'eth_getBlockByNumber', [$hex->decimalToQuantity($blockNumber), false]);
        if (!is_array($block)) {
            return 0;
        }
        return $hex->quantityToInt((string)($block['timestamp'] ?? '0x0'));
    }

    public function getLogs(string $networkCode, array $filter): array
    {
        return $this->request($networkCode, 'eth_getLogs', [$filter]);
    }

    public function getTransactionReceipt(string $networkCode, string $txHash): ?array
    {
        return $this->request($networkCode, 'eth_getTransactionReceipt', [$txHash]);
    }

    public function ethCall(string $networkCode, string $to, string $data): string
    {
        return $this->request($networkCode, 'eth_call', [[
            'to' => $to,
            'data' => $data,
        ], 'latest']);
    }

    public function getBalance(string $networkCode, string $address): string
    {
        return (new EvmHexService())->quantityToDecimalString($this->request($networkCode, 'eth_getBalance', [$address, 'latest']));
    }

    public function gasPrice(string $networkCode): string
    {
        return (new EvmHexService())->quantityToDecimalString($this->request($networkCode, 'eth_gasPrice'));
    }

    public function getTransactionCount(string $networkCode, string $address): string
    {
        return (new EvmHexService())->quantityToDecimalString($this->request($networkCode, 'eth_getTransactionCount', [$address, 'pending']));
    }

    public function sendRawTransaction(string $networkCode, string $rawTx): string
    {
        return $this->request($networkCode, 'eth_sendRawTransaction', [$rawTx]);
    }

    public function tokenBalanceOf(string $networkCode, string $contract, string $address): string
    {
        $hex = new EvmHexService();
        $data = '0x70a08231' . substr($hex->addressToTopic($address), 2);
        return $hex->hexToDecimal($this->ethCall($networkCode, $contract, $data));
    }

    public function usdcBalanceOf(string $networkCode, string $contract, string $address): string
    {
        return $this->tokenBalanceOf($networkCode, $contract, $address);
    }

    public function tokenConfig(string $networkCode, string $tokenCode): array
    {
        $tokenCode = strtoupper(trim($tokenCode));
        $row = NetworkToken::enabledByNetworkToken($networkCode, $tokenCode);
        $defaults = config('chains.networks.' . $networkCode . '.tokens.' . $tokenCode) ?: [];
        $contract = strtolower((string)($row['contract_address'] ?? ($defaults['contract'] ?? '')));
        if ($contract === '') {
            throw new RuntimeException($networkCode . ' 网络未配置 ' . $tokenCode . ' 合约地址');
        }
        if (!preg_match('/^0x[a-f0-9]{40}$/', $contract)) {
            throw new RuntimeException($networkCode . ' 网络 ' . $tokenCode . ' 合约地址格式不正确');
        }

        return [
            'network_code' => $networkCode,
            'token_code' => $tokenCode,
            'contract_address' => $contract,
            'decimals' => (int)($row['decimals'] ?? ($defaults['decimals'] ?? (config('chains.tokens.' . $tokenCode . '.decimals') ?: 6))),
            'standard' => (string)($row['standard'] ?? 'ERC20'),
        ];
    }

    public function request(string $networkCode, string $method, array $params = []): mixed
    {
        $context = $this->runtimeGroup($networkCode);
        return $this->requestWithGroup($context, $method, $params);
    }

    public function runtimeConfig(string $networkCode): array
    {
        return $this->runtimeNetworkSetting($networkCode);
    }

    public function testConnectionWithSteps(string $networkCode): array
    {
        try {
            $group = $this->activeGroupForNetwork($networkCode, $this->runtimeNetworkSetting($networkCode));
            return $this->testGroupWithSteps((int)$group['id']);
        } catch (Throwable $e) {
            return [
                'network_code' => $networkCode,
                'ok' => false,
                'steps' => [$this->step('RPC 测试完成', 'error', $this->shortError($e->getMessage()))],
                'error' => $this->shortError($e->getMessage()),
            ];
        }
    }

    public function testNodeWithSteps(int $nodeId): array
    {
        $steps = [];
        try {
            $row = RpcConfig::findById($nodeId);
            if (!$row) {
                throw new RuntimeException('RPC 节点不存在');
            }
            $settings = $this->runtimeNetworkSetting((string)$row['network_code']);
            $node = $this->runtimeNodeConfig($row, $settings);
            $steps[] = $this->step('读取 RPC 节点配置', 'finish', $this->nodeLabel($node) . '，' . $node['rpc_url']);
            $this->appendProxyStep($steps, $node);

            $steps[] = $this->step('请求完整 RPC 接口', 'process', '发送 JSON-RPC POST：eth_chainId');
            $chainRaw = $this->sendRpcHttp($node, 'eth_chainId');
            $chainId = (new EvmHexService())->quantityToInt($this->extractResult($chainRaw));
            $steps[count($steps) - 1]['status'] = 'finish';
            $steps[count($steps) - 1]['message'] = 'HTTP 状态码 ' . $chainRaw['status_code'] . '，链编号：' . $chainId;

            $steps[] = $this->step('读取当前区块', 'process', '请求 eth_blockNumber');
            $blockRaw = $this->sendRpcHttp($node, 'eth_blockNumber');
            $block = (new EvmHexService())->quantityToInt($this->extractResult($blockRaw));
            $steps[count($steps) - 1]['status'] = 'finish';
            $steps[count($steps) - 1]['message'] = '当前区块：' . $block;

            $ok = $chainId === (int)$settings['chain_id'];
            $steps[] = $this->step('RPC 节点测试完成', $ok ? 'finish' : 'error', $ok ? '节点请求成功' : '链编号与当前网络配置不一致');
            return $this->testResult((string)$row['network_code'], $settings, $steps, $ok, $chainId, $block, $node);
        } catch (Throwable $e) {
            $steps[] = $this->step('RPC 节点测试完成', 'error', $this->shortError($e->getMessage()));
            return [
                'node_id' => $nodeId,
                'ok' => false,
                'steps' => $steps,
                'error' => $this->shortError($e->getMessage()),
            ];
        }
    }

    public function testGroupWithSteps(int $groupId): array
    {
        $steps = [];
        try {
            $group = RpcGroup::findById($groupId);
            if (!$group) {
                throw new RuntimeException('RPC 分组不存在');
            }
            $context = $this->runtimeGroup((string)$group['network_code'], $groupId);
            $settings = $context['settings'];
            $steps[] = $this->step(
                '读取 RPC 分组配置',
                'finish',
                $group['name'] . '，' . $this->rotationLabel((string)$group['rotation_mode']) . '，单节点请求 ' . (int)$group['single_attempts'] . ' 次，最多尝试 ' . (int)$group['max_nodes'] . ' 个节点'
            );

            $steps[] = $this->step('请求完整 RPC 接口', 'process', '发送 JSON-RPC POST：eth_chainId');
            $rpcStepIndex = count($steps) - 1;
            $chainId = (new EvmHexService())->quantityToInt($this->requestWithGroup($context, 'eth_chainId', [], $steps));
            $steps[$rpcStepIndex]['status'] = 'finish';
            $steps[$rpcStepIndex]['message'] = 'eth_chainId 请求完成';
            $steps[] = $this->step('链编号检查', $chainId === (int)$settings['chain_id'] ? 'finish' : 'error', '链编号：' . $chainId);
            if ($chainId !== (int)$settings['chain_id']) {
                return $this->testResult((string)$group['network_code'], $settings, $steps, false, $chainId, null, null, $group);
            }

            $steps[] = $this->step('读取当前区块', 'process', '请求 eth_blockNumber');
            $blockStepIndex = count($steps) - 1;
            $block = (new EvmHexService())->quantityToInt($this->requestWithGroup($context, 'eth_blockNumber', [], $steps));
            $steps[$blockStepIndex]['status'] = 'finish';
            $steps[$blockStepIndex]['message'] = 'eth_blockNumber 请求完成，当前区块：' . $block;
            $steps[] = $this->step('RPC 分组测试完成', 'finish', 'RPC 分组请求成功，当前区块：' . $block);
            return $this->testResult((string)$group['network_code'], $settings, $steps, true, $chainId, $block, null, $group);
        } catch (Throwable $e) {
            $steps[] = $this->step('RPC 分组测试完成', 'error', $this->shortError($e->getMessage()));
            return [
                'group_id' => $groupId,
                'ok' => false,
                'steps' => $steps,
                'error' => $this->shortError($e->getMessage()),
            ];
        }
    }

    private function requestWithGroup(array $context, string $method, array $params = [], ?array &$steps = null): mixed
    {
        $group = $context['group'];
        $nodes = $this->selectNodes($group, $context['nodes']);
        $singleAttempts = max(1, (int)($group['single_attempts'] ?? 1));
        $lastError = '';
        $triedNodes = 0;

        if ($steps !== null) {
            $steps[] = $this->step('选择 RPC 节点', 'finish', '本次候选节点：' . implode('、', array_map(fn ($node) => $this->nodeLabel($node), $nodes)));
        }

        foreach ($nodes as $node) {
            $triedNodes++;
            for ($attempt = 1; $attempt <= $singleAttempts; $attempt++) {
                $stepIndex = null;
                if ($steps !== null) {
                    $steps[] = $this->step('请求节点：' . $this->nodeLabel($node), 'process', $method . '，第 ' . $attempt . ' / ' . $singleAttempts . ' 次');
                    $stepIndex = count($steps) - 1;
                }

                try {
                    $raw = $this->sendRpcHttp($node, $method, $params);
                    $result = $this->extractResult($raw);
                    if ($steps !== null && $stepIndex !== null) {
                        $steps[$stepIndex]['status'] = 'finish';
                        $steps[$stepIndex]['message'] = '请求成功，HTTP 状态码 ' . $raw['status_code'];
                    }
                    return $result;
                } catch (Throwable $e) {
                    $lastError = $this->nodeErrorMessage($node, $attempt, $e);
                    if ($steps !== null && $stepIndex !== null) {
                        $steps[$stepIndex]['status'] = 'error';
                        $steps[$stepIndex]['message'] = $lastError;
                    }
                    if (!$this->isRetryableRpcFailure($e, $method)) {
                        throw new RuntimeException($lastError);
                    }
                }
            }
        }

        throw new RuntimeException('RPC 分组请求失败，已尝试 ' . $triedNodes . ' 个节点，每个节点最多请求 ' . $singleAttempts . ' 次，最后错误：' . ($lastError ?: '未知错误'));
    }

    private function runtimeNetworkSetting(string $networkCode): array
    {
        (new RpcNetworkSettingSchemaService())->ensure();
        $defaults = config('chains.networks.' . $networkCode) ?: [];
        if (!$defaults) {
            throw new RuntimeException('网络不存在：' . $networkCode);
        }
        $row = RpcNetworkSetting::findByNetwork($networkCode) ?: [];
        return [
            'network_code' => $networkCode,
            'chain_id' => (int)($defaults['chain_id'] ?? 0),
            'default_rpc_url' => $defaults['rpc_url'] ?? '',
            'contract_address' => $row['contract_address'] ?? ($defaults['usdc_contract'] ?? ''),
            'decimals' => (int)($row['decimals'] ?? ($defaults['decimals'] ?? 6)),
            'min_confirm_blocks' => (int)($row['min_confirm_blocks'] ?? ($defaults['min_confirm_blocks'] ?? ($row['confirm_blocks'] ?? ($defaults['confirm_blocks'] ?? 12)))),
            'confirm_blocks' => (int)($row['confirm_blocks'] ?? ($defaults['confirm_blocks'] ?? 12)),
            'large_amount_threshold' => (string)($row['large_amount_threshold'] ?? ($defaults['large_amount_threshold'] ?? '100')),
            'scan_step_blocks' => (int)($row['scan_step_blocks'] ?? ($defaults['scan_step_blocks'] ?? 500)),
            'monitor_interval_seconds' => (int)($row['monitor_interval_seconds'] ?? ($defaults['monitor_interval_seconds'] ?? 10)),
            'enabled' => (int)($row['enabled'] ?? 0),
            'active_group_id' => (int)($row['active_group_id'] ?? 0),
            'last_monitor_at' => $row['last_monitor_at'] ?? null,
        ];
    }

    private function runtimeGroup(string $networkCode, ?int $groupId = null): array
    {
        $settings = $this->runtimeNetworkSetting($networkCode);
        $group = $groupId ? RpcGroup::findById($groupId) : $this->activeGroupForNetwork($networkCode, $settings);
        if (!$group || (string)$group['network_code'] !== $networkCode) {
            throw new RuntimeException($networkCode . ' 网络没有可用的 RPC 分组，请先在后台创建并选择分组');
        }

        $nodes = RpcConfig::enabledByGroup((int)$group['id']);
        if (!$nodes) {
            throw new RuntimeException('RPC 分组「' . $group['name'] . '」下没有启用的 RPC 节点');
        }

        $runtimeNodes = [];
        foreach ($nodes as $node) {
            $runtimeNodes[] = $this->runtimeNodeConfig($node, $settings);
        }

        return [
            'settings' => $settings,
            'group' => $group,
            'nodes' => $runtimeNodes,
        ];
    }

    private function activeGroupForNetwork(string $networkCode, array $settings): array
    {
        $activeGroupId = (int)($settings['active_group_id'] ?? 0);
        if ($activeGroupId > 0) {
            $group = RpcGroup::findById($activeGroupId);
            if ($group && (string)$group['network_code'] === $networkCode) {
                return $group;
            }
        }
        $group = RpcGroup::firstByNetwork($networkCode);
        if (!$group) {
            throw new RuntimeException($networkCode . ' 网络没有 RPC 分组，请先在后台创建分组');
        }
        return $group;
    }

    private function runtimeNodeConfig(array $row, array $settings): array
    {
        $crypto = new CryptoService();
        $apiKey = $crypto->decrypt($row['api_key_cipher'] ?? '');
        $provider = (string)($row['provider'] ?? 'infura');
        $rpcUrl = $row['rpc_url'] ?: ($settings['default_rpc_url'] ?? '');
        if ($apiKey !== '') {
            $originalRpcUrl = $rpcUrl;
            $rpcUrl = str_replace($this->apiKeyPlaceholders(), $apiKey, $rpcUrl);
            if ($provider === 'infura' && str_ends_with($rpcUrl, '/v3/')) {
                $rpcUrl .= $apiKey;
            } elseif ($provider === 'infura' && str_ends_with($rpcUrl, '/v3')) {
                $rpcUrl .= '/' . $apiKey;
            } elseif ($provider === 'dwellir' && $rpcUrl === $originalRpcUrl && str_ends_with($rpcUrl, '/')) {
                $rpcUrl .= $apiKey;
            }
        }

        $proxyId = (int)($row['proxy_id'] ?? 0);
        $proxy = $proxyId > 0 ? (new ProxyPoolService())->runtimeById($proxyId, false) : null;

        return array_merge($settings, [
            'node_id' => (int)$row['id'],
            'node_name' => $row['name'] ?: ('RPC #' . $row['id']),
            'group_id' => (int)($row['group_id'] ?? 0),
            'provider' => $provider,
            'rpc_url' => $rpcUrl,
            'api_key' => $apiKey,
            'api_key_secret' => $provider === 'infura' && !empty($row['use_api_key_secret']) ? $crypto->decrypt($row['api_key_secret_cipher'] ?? '') : '',
            'proxy_id' => $proxyId,
            'proxy' => $proxy,
        ]);
    }

    private function selectNodes(array $group, array $nodes): array
    {
        $nodes = array_values($nodes);
        if (!$nodes) {
            return [];
        }
        $maxNodes = min(count($nodes), max(1, (int)($group['max_nodes'] ?? count($nodes))));
        if (($group['rotation_mode'] ?? 'random') === 'random') {
            shuffle($nodes);
            return array_slice($nodes, 0, $maxNodes);
        }

        $groupId = (int)$group['id'];
        $count = count($nodes);
        $start = self::$sequenceIndexes[$groupId] ?? 0;
        self::$sequenceIndexes[$groupId] = ($start + 1) % $count;
        $ordered = [];
        for ($i = 0; $i < $count; $i++) {
            $ordered[] = $nodes[($start + $i) % $count];
        }
        return array_slice($ordered, 0, $maxNodes);
    }

    private function sendRpcHttp(array $cfg, string $method, array $params = []): array
    {
        if (empty($cfg['rpc_url']) || $this->hasApiKeyPlaceholder((string)$cfg['rpc_url'])) {
            throw new RuntimeException($cfg['network_code'] . ' 网络的节点接口地址未配置完整，请先在后台保存节点接口地址和接口密钥');
        }

        $options = [
            'http_errors' => false,
            'json' => [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => 1,
            ],
            'timeout' => 15,
            'connect_timeout' => 5,
        ];
        if (!empty($cfg['api_key_secret'])) {
            $options['auth'] = ['', $cfg['api_key_secret']];
        }
        if (($cfg['provider'] ?? '') === 'onfinality' && !empty($cfg['api_key'])) {
            $options['headers']['apikey'] = $cfg['api_key'];
        }
        $this->applyRpcProxy($options, $cfg);

        $response = $this->httpClient()->post($cfg['rpc_url'], $options);
        return [
            'status_code' => $response->getStatusCode(),
            'body' => json_decode($bodyText = (string)$response->getBody(), true),
            'raw_body' => $bodyText,
            'json_error' => json_last_error_msg(),
        ];
    }

    private function apiKeyPlaceholders(): array
    {
        return ['{api_key}', '{API_KEY}', '<API_KEY>', '<INFURA_API_KEY>', '<your-api-key>', '<YOUR_API_KEY>'];
    }

    private function hasApiKeyPlaceholder(string $rpcUrl): bool
    {
        foreach ($this->apiKeyPlaceholders() as $placeholder) {
            if (str_contains($rpcUrl, $placeholder)) {
                return true;
            }
        }
        return false;
    }

    private function applyRpcProxy(array &$options, array $cfg): void
    {
        if (empty($cfg['proxy_id'])) {
            return;
        }
        if (empty($cfg['proxy'])) {
            throw new RuntimeException('RPC 绑定的代理不存在，已按要求停止请求，未回退直连');
        }
        if (($cfg['proxy']['status'] ?? '') !== 'enabled') {
            throw new RuntimeException('RPC 绑定的代理已禁用，已按要求停止请求，未回退直连');
        }
        (new ProxyPoolService())->applyToOptions($options, $cfg['proxy']);
    }

    private function extractResult(array $raw): mixed
    {
        $statusCode = (int)$raw['status_code'];
        $body = $raw['body'];
        if ($statusCode >= 400) {
            $detail = $this->rpcErrorDetail($body, (string)($raw['raw_body'] ?? ''));
            throw new RuntimeException('节点接口请求失败，HTTP 状态码 ' . $statusCode . ($detail !== '' ? '，返回：' . $detail : ''));
        }
        if (!is_array($body)) {
            $rawBody = $this->shortError(trim((string)($raw['raw_body'] ?? '')));
            throw new RuntimeException('节点接口响应不是有效 JSON，JSON 错误：' . ($raw['json_error'] ?? '未知错误') . ($rawBody !== '' ? '，原始响应：' . $rawBody : ''));
        }
        if (isset($body['error'])) {
            throw new RuntimeException('节点接口返回错误：' . $this->rpcErrorDetail($body, (string)($raw['raw_body'] ?? '')));
        }
        if (!array_key_exists('result', $body)) {
            throw new RuntimeException('节点接口响应缺少 result 字段，原始响应：' . $this->shortError(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
        }
        return $body['result'] ?? null;
    }

    private function rpcErrorDetail(mixed $body, string $rawBody): string
    {
        if (is_array($body) && isset($body['error'])) {
            $error = $body['error'];
            if (is_array($error)) {
                $parts = [];
                if (isset($error['code'])) {
                    $parts[] = '错误码 ' . $error['code'];
                }
                if (isset($error['message'])) {
                    $parts[] = '错误信息 ' . $error['message'];
                }
                if (isset($error['data'])) {
                    $data = is_scalar($error['data'])
                        ? (string)$error['data']
                        : (json_encode($error['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                    if ($data !== '') {
                        $parts[] = '错误数据 ' . $data;
                    }
                }
                return $this->shortError(implode('，', $parts));
            }
            return $this->shortError((string)$error);
        }

        if (is_array($body)) {
            return $this->shortError(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }

        return $this->shortError(trim($rawBody));
    }

    private function isRetryableRpcFailure(Throwable $e, string $method): bool
    {
        if ($e instanceof GuzzleException) {
            return true;
        }

        $message = strtolower($e->getMessage());
        if (str_contains($message, 'invalid params') || str_contains($message, '错误码 -32602')) {
            return false;
        }
        if ($method === 'eth_sendRawTransaction') {
            foreach (['insufficient funds', 'nonce too low', 'already known', 'underpriced', 'intrinsic gas', 'exceeds block gas limit'] as $needle) {
                if (str_contains($message, $needle)) {
                    return false;
                }
            }
        }

        foreach (['http 状态码', '代理', '超时', 'timeout', 'timed out', 'connection', '有效 json', '缺少 result', '请求失败'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return str_contains($message, '节点接口返回错误');
    }

    private function nodeErrorMessage(array $node, int $attempt, Throwable $e): string
    {
        return $this->nodeLabel($node) . ' 第 ' . $attempt . ' 次请求失败：' . $this->shortError($e->getMessage());
    }

    private function appendProxyStep(array &$steps, array $node): void
    {
        if (!empty($node['proxy_id'])) {
            $proxy = $node['proxy'] ?? null;
            if (!$proxy) {
                $steps[] = $this->step('检查代理配置', 'error', 'RPC 绑定的代理不存在，已停止测试且未回退直连');
                return;
            }
            if (($proxy['status'] ?? '') !== 'enabled') {
                $steps[] = $this->step('检查代理配置', 'error', 'RPC 绑定的代理已禁用，已停止测试且未回退直连');
                return;
            }
            $steps[] = $this->step('检查代理配置', 'finish', '已绑定代理：' . (new ProxyPoolService())->proxyLabel($proxy));
            return;
        }
        $steps[] = $this->step('检查代理配置', 'finish', '当前为直连');
    }

    private function testResult(string $networkCode, array $cfg, array $steps, bool $ok, ?int $chainId, ?int $block, ?array $node = null, ?array $group = null): array
    {
        $error = '';
        if (!$ok) {
            foreach (array_reverse($steps) as $step) {
                if (($step['status'] ?? '') === 'error') {
                    $error = (string)($step['message'] ?? '');
                    break;
                }
            }
        }

        return [
            'network_code' => $networkCode,
            'expected_chain_id' => (int)($cfg['chain_id'] ?? 0),
            'chain_id' => $chainId,
            'block_number' => $block,
            'node_id' => (int)($node['node_id'] ?? 0),
            'node_name' => (string)($node['node_name'] ?? ''),
            'group_id' => (int)($group['id'] ?? 0),
            'group_name' => (string)($group['name'] ?? ''),
            'proxy_id' => (int)($node['proxy_id'] ?? 0),
            'proxy_name' => !empty($node['proxy']) ? ($node['proxy']['name'] ?? '') : '直连',
            'ok' => $ok,
            'error' => $error,
            'steps' => $steps,
        ];
    }

    private function step(string $title, string $status, string $message): array
    {
        return ['title' => $title, 'status' => $status, 'message' => $message];
    }

    private function nodeLabel(array $node): string
    {
        return ($node['node_name'] ?? ('RPC #' . ($node['node_id'] ?? 0))) . '（#' . (int)($node['node_id'] ?? 0) . '）';
    }

    private function rotationLabel(string $mode): string
    {
        return $mode === 'sequence' ? '顺序轮询' : '随机轮询';
    }

    private function shortError(string $message): string
    {
        $message = trim($message ?: '未知错误');
        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 500, 'UTF-8');
        }
        return substr($message, 0, 500);
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
