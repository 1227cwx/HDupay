<?php

namespace app\service;

use app\model\ProxyPool;
use app\model\NetworkToken;
use app\model\RpcConfig;
use app\model\RpcGroup;
use app\model\RpcNetworkSetting;
use app\model\Token;
use InvalidArgumentException;
use RuntimeException;

class RpcConfigService
{
    public function list(): array
    {
        (new RpcNetworkSettingSchemaService())->ensure();
        $this->ensureDefaults();
        $proxyNames = $this->proxyNames();
        $groups = RpcGroup::allList();
        $groupNames = [];
        $nodeCounts = [];
        foreach ($groups as $group) {
            $groupNames[(int)$group['id']] = $group['name'];
            $nodeCounts[(int)$group['id']] = 0;
        }

        $nodes = RpcConfig::allList();
        foreach ($nodes as $node) {
            $gid = (int)($node['group_id'] ?? 0);
            if ($gid > 0) {
                $nodeCounts[$gid] = ($nodeCounts[$gid] ?? 0) + 1;
            }
        }

        $settings = [];
        foreach (RpcNetworkSetting::allList() as $setting) {
            $activeGroupId = (int)($setting['active_group_id'] ?? 0);
            $defaults = config('chains.networks.' . $setting['network_code']) ?: [];
            $setting['network_name'] = $this->networkName((string)$setting['network_code']);
            $setting['contract_address'] = $this->networkTokenContract((string)$setting['network_code'], 'USDC') ?: (string)($setting['contract_address'] ?? '');
            $setting['usdc_contract_address'] = $setting['contract_address'];
            $setting['usdt_contract_address'] = $this->networkTokenContract((string)$setting['network_code'], 'USDT');
            $setting['min_confirm_blocks'] = (int)($setting['min_confirm_blocks'] ?? ($defaults['min_confirm_blocks'] ?? ($setting['confirm_blocks'] ?? ($defaults['confirm_blocks'] ?? 12))));
            $setting['confirm_blocks'] = (int)($setting['confirm_blocks'] ?? ($defaults['confirm_blocks'] ?? 12));
            $setting['large_amount_threshold'] = (string)($setting['large_amount_threshold'] ?? ($defaults['large_amount_threshold'] ?? '100'));
            $setting['active_group_name'] = $activeGroupId > 0 ? ($groupNames[$activeGroupId] ?? '分组不存在') : '未选择';
            $setting['enabled'] = (int)($setting['enabled'] ?? 0);
            $settings[] = $setting;
        }

        foreach ($groups as &$group) {
            $group['network_name'] = $this->networkName((string)$group['network_code']);
            $group['node_count'] = $nodeCounts[(int)$group['id']] ?? 0;
            $group['rotation_mode_label'] = $group['rotation_mode'] === 'sequence' ? '顺序轮询' : '随机轮询';
        }
        unset($group);

        foreach ($nodes as &$node) {
            $node = $this->sanitizeNode($node, $groupNames, $proxyNames);
        }
        unset($node);

        return [
            'network_settings' => $settings,
            'groups' => $groups,
            'nodes' => $nodes,
            'networks' => $this->networkOptions(),
            'providers' => $this->providerOptions(),
        ];
    }

    public function saveNetwork(array $input): array
    {
        (new RpcNetworkSettingSchemaService())->ensure();
        $networkCode = $this->validNetwork((string)($input['network_code'] ?? ''));
        $exists = RpcNetworkSetting::findByNetwork($networkCode) ?: [];
        $defaults = config('chains.networks.' . $networkCode) ?: [];
        $activeGroupId = (int)($input['active_group_id'] ?? ($exists['active_group_id'] ?? 0));
        if ($activeGroupId > 0) {
            $this->validGroupForNetwork($activeGroupId, $networkCode);
        }

        $currentUsdcContract = $this->networkTokenContract($networkCode, 'USDC') ?: (string)($exists['contract_address'] ?? ($defaults['usdc_contract'] ?? ''));
        $currentUsdtContract = $this->networkTokenContract($networkCode, 'USDT') ?: (string)($defaults['usdt_contract'] ?? '');
        $usdcContract = strtolower(trim((string)($input['contract_address'] ?? ($input['usdc_contract_address'] ?? $currentUsdcContract))));
        $usdtContract = strtolower(trim((string)($input['usdt_contract_address'] ?? $currentUsdtContract)));
        if ($usdcContract === '') {
            throw new InvalidArgumentException('USDC 合约地址不能为空');
        }
        if ($usdtContract === '') {
            throw new InvalidArgumentException('USDT 合约地址不能为空');
        }
        $this->assertEvmAddress($usdcContract, 'USDC 合约地址');
        $this->assertEvmAddress($usdtContract, 'USDT 合约地址');

        $minConfirmBlocks = max(1, (int)($input['min_confirm_blocks'] ?? ($exists['min_confirm_blocks'] ?? ($defaults['min_confirm_blocks'] ?? ($defaults['confirm_blocks'] ?? 12)))));
        $maxConfirmBlocks = max(1, (int)($input['confirm_blocks'] ?? ($exists['confirm_blocks'] ?? ($defaults['confirm_blocks'] ?? 12))));
        if ($minConfirmBlocks > $maxConfirmBlocks) {
            throw new InvalidArgumentException('最小确认区块数不能大于最大确认区块数');
        }
        $largeAmountThreshold = $this->normalizeLargeAmountThreshold((string)($input['large_amount_threshold'] ?? ($exists['large_amount_threshold'] ?? ($defaults['large_amount_threshold'] ?? '100'))));

        $data = [
            'contract_address' => $usdcContract,
            'decimals' => $this->tokenDecimals('USDC'),
            'monitor_interval_seconds' => max(2, (int)($input['monitor_interval_seconds'] ?? ($exists['monitor_interval_seconds'] ?? ($defaults['monitor_interval_seconds'] ?? 10)))),
            'min_confirm_blocks' => $minConfirmBlocks,
            'confirm_blocks' => $maxConfirmBlocks,
            'large_amount_threshold' => $largeAmountThreshold,
            'scan_step_blocks' => max(1, (int)($input['scan_step_blocks'] ?? ($exists['scan_step_blocks'] ?? ($defaults['scan_step_blocks'] ?? 500)))),
            'active_group_id' => $activeGroupId,
            'enabled' => !empty($input['enabled']) ? 1 : 0,
        ];

        $saved = RpcNetworkSetting::saveForNetwork($networkCode, $data);
        $this->saveNetworkToken($networkCode, 'USDC', $usdcContract);
        $this->saveNetworkToken($networkCode, 'USDT', $usdtContract);
        return $saved;
    }

    public function saveGroup(array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        $exists = $id > 0 ? RpcGroup::findById($id) : [];
        if ($id > 0 && !$exists) {
            throw new InvalidArgumentException('RPC 分组不存在');
        }

        $networkCode = $this->validNetwork((string)($input['network_code'] ?? ($exists['network_code'] ?? '')));
        if ($exists && $networkCode !== (string)$exists['network_code']) {
            throw new InvalidArgumentException('编辑分组时不允许修改所属网络');
        }

        $name = trim((string)($input['name'] ?? ($exists['name'] ?? '')));
        if ($name === '') {
            throw new InvalidArgumentException('分组名称不能为空');
        }

        $rotationMode = (string)($input['rotation_mode'] ?? ($exists['rotation_mode'] ?? 'random'));
        if (!in_array($rotationMode, ['random', 'sequence'], true)) {
            throw new InvalidArgumentException('轮询机制只能选择随机轮询或顺序轮询');
        }

        $data = [
            'network_code' => $networkCode,
            'name' => $name,
            'rotation_mode' => $rotationMode,
            'single_attempts' => max(1, min(10, (int)($input['single_attempts'] ?? ($exists['single_attempts'] ?? 2)))),
            'max_nodes' => max(1, min(50, (int)($input['max_nodes'] ?? ($exists['max_nodes'] ?? 3)))),
        ];

        if ($id > 0) {
            RpcGroup::updateById($id, $data);
            $saved = RpcGroup::findById($id) ?: [];
        } else {
            $saved = RpcGroup::createRecord($data);
        }

        $setting = RpcNetworkSetting::findByNetwork($networkCode);
        if (!$setting) {
            RpcNetworkSetting::saveForNetwork($networkCode, array_merge($this->defaultNetworkSetting($networkCode), [
                'active_group_id' => (int)$saved['id'],
            ]));
        } elseif ((int)($setting['active_group_id'] ?? 0) <= 0 || !empty($input['set_active'])) {
            RpcNetworkSetting::updateById((int)$setting['id'], ['active_group_id' => (int)$saved['id']]);
        }

        return $saved;
    }

    public function deleteGroup(int $id): bool
    {
        $group = RpcGroup::findById($id);
        if (!$group) {
            throw new InvalidArgumentException('RPC 分组不存在');
        }
        if (RpcConfig::countByGroupId($id) > 0) {
            throw new RuntimeException('该分组下还有 RPC 节点，请先移动或删除相关节点');
        }
        $setting = RpcNetworkSetting::findByNetwork((string)$group['network_code']);
        if ($setting && (int)($setting['active_group_id'] ?? 0) === $id) {
            throw new RuntimeException('当前分组正在被网络使用，不能删除');
        }
        return RpcGroup::deleteById($id) > 0;
    }

    public function saveNode(array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        $exists = $id > 0 ? RpcConfig::findById($id) : [];
        if ($id > 0 && !$exists) {
            throw new InvalidArgumentException('RPC 节点不存在');
        }

        $networkCode = $this->validNetwork((string)($input['network_code'] ?? ($exists['network_code'] ?? '')));
        $groupId = (int)($input['group_id'] ?? ($exists['group_id'] ?? 0));
        $this->validGroupForNetwork($groupId, $networkCode);

        $provider = $this->validProvider((string)($input['provider'] ?? ($exists['provider'] ?? 'infura')));

        $rpcUrl = trim((string)($input['rpc_url'] ?? ($exists['rpc_url'] ?? '')));
        if ($rpcUrl === '') {
            throw new InvalidArgumentException('RPC URL 不能为空');
        }

        $name = trim((string)($input['name'] ?? ($exists['name'] ?? '')));
        if ($name === '') {
            $name = strtoupper($networkCode) . ' ' . $this->providerLabel($provider) . ' RPC';
        }

        $crypto = new CryptoService();
        $apiKey = trim((string)($input['api_key'] ?? ''));
        $secret = trim((string)($input['api_key_secret'] ?? ''));
        $useApiKeySecret = $provider === 'infura' && !empty($input['use_api_key_secret']);
        $data = [
            'name' => $name,
            'network_code' => $networkCode,
            'group_id' => $groupId,
            'provider' => $provider,
            'rpc_url' => $rpcUrl,
            'use_api_key_secret' => $useApiKeySecret ? 1 : 0,
            'proxy_id' => $this->validProxyId((int)($input['proxy_id'] ?? ($exists['proxy_id'] ?? 0))),
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'sort_order' => max(0, (int)($input['sort_order'] ?? ($exists['sort_order'] ?? 0))),
        ];

        if ($apiKey !== '') {
            $data['api_key_cipher'] = $crypto->encrypt($apiKey);
            $data['api_key_masked'] = $crypto->mask($apiKey);
        } elseif (!$exists) {
            $data['api_key_cipher'] = '';
            $data['api_key_masked'] = '';
        }

        if (!$useApiKeySecret) {
            $data['api_key_secret_cipher'] = '';
        } elseif ($secret !== '') {
            $data['api_key_secret_cipher'] = $crypto->encrypt($secret);
        } elseif (!$exists) {
            $data['api_key_secret_cipher'] = '';
        }

        if ($id > 0) {
            RpcConfig::updateById($id, $data);
            return $this->sanitizeNode(RpcConfig::findById($id) ?: [], $this->groupNames(), $this->proxyNames());
        }

        return $this->sanitizeNode(RpcConfig::createRecord($data), $this->groupNames(), $this->proxyNames());
    }

    public function toggleNode(int $id, bool $enabled): array
    {
        $node = RpcConfig::findById($id);
        if (!$node) {
            throw new InvalidArgumentException('RPC 节点不存在');
        }
        RpcConfig::updateById($id, ['enabled' => $enabled ? 1 : 0]);
        return $this->sanitizeNode(RpcConfig::findById($id) ?: [], $this->groupNames(), $this->proxyNames());
    }

    public function deleteNode(int $id): bool
    {
        if (!RpcConfig::findById($id)) {
            throw new InvalidArgumentException('RPC 节点不存在');
        }
        return RpcConfig::deleteById($id) > 0;
    }

    public function test(string $networkCode): array
    {
        return (new EvmRpcService())->testConnectionWithSteps($networkCode);
    }

    public function testNode(int $id): array
    {
        return (new EvmRpcService())->testNodeWithSteps($id);
    }

    public function testGroup(int $id): array
    {
        return (new EvmRpcService())->testGroupWithSteps($id);
    }

    public function ensureDefaults(): void
    {
        (new RpcNetworkSettingSchemaService())->ensure();
        $this->ensureTokenDefaults();
        foreach (config('chains.networks') ?: [] as $networkCode => $cfg) {
            $setting = RpcNetworkSetting::findByNetwork((string)$networkCode);
            if (!$setting) {
                $setting = RpcNetworkSetting::saveForNetwork((string)$networkCode, $this->defaultNetworkSetting((string)$networkCode));
            }
            $defaultTokens = $this->defaultNetworkTokens((string)$networkCode);
            if (!empty($setting['contract_address'])) {
                $defaultTokens['USDC']['contract'] = (string)$setting['contract_address'];
            }
            foreach ($defaultTokens as $tokenCode => $token) {
                $contract = strtolower((string)($token['contract'] ?? ''));
                if ($contract !== '') {
                    $this->saveNetworkToken((string)$networkCode, (string)$tokenCode, $contract, false);
                }
            }
            $group = RpcGroup::firstByNetwork((string)$networkCode);
            if (!$group) {
                $group = RpcGroup::createRecord([
                    'network_code' => (string)$networkCode,
                    'name' => '默认分组',
                    'rotation_mode' => 'random',
                    'single_attempts' => 2,
                    'max_nodes' => 3,
                ]);
            }
            if ((int)($setting['active_group_id'] ?? 0) <= 0) {
                RpcNetworkSetting::updateById((int)$setting['id'], ['active_group_id' => (int)$group['id']]);
            }
        }
    }

    private function defaultNetworkSetting(string $networkCode): array
    {
        $cfg = config('chains.networks.' . $networkCode) ?: [];
        return [
            'contract_address' => strtolower((string)($cfg['usdc_contract'] ?? '')),
            'decimals' => $this->tokenDecimals('USDC'),
            'monitor_interval_seconds' => (int)($cfg['monitor_interval_seconds'] ?? 10),
            'min_confirm_blocks' => (int)($cfg['min_confirm_blocks'] ?? ($cfg['confirm_blocks'] ?? 12)),
            'confirm_blocks' => (int)($cfg['confirm_blocks'] ?? 12),
            'large_amount_threshold' => (string)($cfg['large_amount_threshold'] ?? '100'),
            'scan_step_blocks' => (int)($cfg['scan_step_blocks'] ?? 500),
            'active_group_id' => 0,
            'enabled' => 0,
        ];
    }

    private function ensureTokenDefaults(): void
    {
        foreach (config('chains.tokens') ?: [] as $tokenCode => $cfg) {
            Token::saveForCode((string)$tokenCode, [
                'name' => (string)($cfg['name'] ?? $tokenCode),
                'decimals' => $this->tokenDecimals((string)$tokenCode),
                'status' => 'enabled',
            ]);
        }
    }

    private function defaultNetworkTokens(string $networkCode): array
    {
        $cfg = config('chains.networks.' . $networkCode) ?: [];
        $tokens = $cfg['tokens'] ?? [];
        if (!$tokens) {
            foreach (['USDC', 'USDT'] as $tokenCode) {
                $key = strtolower($tokenCode) . '_contract';
                $tokens[$tokenCode] = [
                    'contract' => (string)($cfg[$key] ?? ''),
                    'decimals' => $this->tokenDecimals($tokenCode),
                ];
            }
        }
        return $tokens;
    }

    private function networkTokenContract(string $networkCode, string $tokenCode): string
    {
        $row = NetworkToken::findByNetworkToken($networkCode, $tokenCode);
        if ($row && trim((string)($row['contract_address'] ?? '')) !== '') {
            return strtolower((string)$row['contract_address']);
        }

        $defaults = $this->defaultNetworkTokens($networkCode);
        $tokenCode = strtoupper($tokenCode);
        return strtolower((string)($defaults[$tokenCode]['contract'] ?? ''));
    }

    private function saveNetworkToken(string $networkCode, string $tokenCode, string $contractAddress, bool $overwrite = true): array
    {
        $tokenCode = strtoupper($tokenCode);
        $exists = NetworkToken::findByNetworkToken($networkCode, $tokenCode);
        if ($exists && !$overwrite) {
            return $exists;
        }
        $this->assertEvmAddress($contractAddress, $tokenCode . ' 合约地址');
        return NetworkToken::saveForNetworkToken($networkCode, $tokenCode, [
            'contract_address' => strtolower($contractAddress),
            'decimals' => $this->tokenDecimals($tokenCode),
            'standard' => 'ERC20',
            'status' => 'enabled',
        ]);
    }

    private function tokenDecimals(string $tokenCode): int
    {
        $decimals = (int)(config('chains.tokens.' . strtoupper($tokenCode) . '.decimals') ?: 6);
        return $decimals > 0 ? $decimals : 6;
    }

    private function normalizeLargeAmountThreshold(string $threshold): string
    {
        $threshold = trim($threshold);
        if ($threshold === '') {
            throw new InvalidArgumentException('大额阈值不能为空');
        }

        $amountInt = (new TokenAmountService())->toInt($threshold, $this->tokenDecimals('USDC'));
        if ((new TokenAmountService())->compare($amountInt, '0') <= 0) {
            throw new InvalidArgumentException('大额阈值必须大于 0');
        }

        return (new TokenAmountService())->toDisplay($amountInt, $this->tokenDecimals('USDC'));
    }

    private function assertEvmAddress(string $address, string $label): void
    {
        if (!preg_match('/^0x[a-f0-9]{40}$/', strtolower($address))) {
            throw new InvalidArgumentException($label . '格式不正确');
        }
    }

    private function validNetwork(string $networkCode): string
    {
        $networkCode = trim($networkCode);
        if ($networkCode === '' || !config('chains.networks.' . $networkCode)) {
            throw new InvalidArgumentException('网络不存在');
        }
        return $networkCode;
    }

    private function validProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!array_key_exists($provider, $this->providerMap())) {
            throw new InvalidArgumentException('RPC 节点提供商只能选择 Infura、Dwellir 或 OnFinality');
        }
        return $provider;
    }

    private function validGroupForNetwork(int $groupId, string $networkCode): array
    {
        if ($groupId <= 0) {
            throw new InvalidArgumentException('请选择 RPC 分组');
        }
        $group = RpcGroup::findById($groupId);
        if (!$group || (string)$group['network_code'] !== $networkCode) {
            throw new InvalidArgumentException('RPC 分组不存在或不属于当前网络');
        }
        return $group;
    }

    private function validProxyId(int $proxyId): int
    {
        if ($proxyId <= 0) {
            return 0;
        }
        $proxy = ProxyPool::findById($proxyId);
        if (!$proxy || $proxy['status'] !== 'enabled') {
            throw new InvalidArgumentException('只能选择代理池中已启用的代理');
        }
        return $proxyId;
    }

    private function sanitizeNode(array $node, array $groupNames, array $proxyNames): array
    {
        if (!$node) {
            return [];
        }
        $node['api_key_cipher'] = '';
        $node['api_key_secret_cipher'] = '';
        $node['group_id'] = (int)($node['group_id'] ?? 0);
        $node['proxy_id'] = (int)($node['proxy_id'] ?? 0);
        $node['enabled'] = (int)($node['enabled'] ?? 0);
        $node['group_name'] = $node['group_id'] > 0 ? ($groupNames[$node['group_id']] ?? '分组不存在') : '未分组';
        $node['proxy_name'] = $node['proxy_id'] > 0 ? ($proxyNames[$node['proxy_id']] ?? '代理不存在或已删除') : '直连';
        $node['network_name'] = $this->networkName((string)$node['network_code']);
        return $node;
    }

    private function groupNames(): array
    {
        $result = [];
        foreach (RpcGroup::allList() as $group) {
            $result[(int)$group['id']] = $group['name'];
        }
        return $result;
    }

    private function proxyNames(): array
    {
        $result = [];
        foreach (ProxyPool::listPage([], 1, 100, 'id', 'asc')['items'] as $proxy) {
            $result[(int)$proxy['id']] = $proxy['name'];
        }
        return $result;
    }

    private function networkOptions(): array
    {
        $options = [];
        foreach (config('chains.networks') ?: [] as $code => $cfg) {
            $options[] = ['label' => ($cfg['name'] ?? $code), 'value' => $code];
        }
        return $options;
    }

    private function providerOptions(): array
    {
        $options = [];
        foreach ($this->providerMap() as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }
        return $options;
    }

    private function providerLabel(string $provider): string
    {
        return $this->providerMap()[$provider] ?? $provider;
    }

    private function providerMap(): array
    {
        return [
            'infura' => 'Infura',
            'dwellir' => 'Dwellir',
            'onfinality' => 'OnFinality',
        ];
    }

    private function networkName(string $networkCode): string
    {
        return (string)(config('chains.networks.' . $networkCode . '.name') ?: $networkCode);
    }
}
