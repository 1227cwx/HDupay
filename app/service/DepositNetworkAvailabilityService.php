<?php

namespace app\service;

use app\model\NetworkToken;
use app\model\RpcConfig;
use app\model\RpcGroup;
use app\model\RpcNetworkSetting;
use app\model\WalletAccount;
use InvalidArgumentException;

class DepositNetworkAvailabilityService
{
    public function options(): array
    {
        (new RpcConfigService())->ensureDefaults();

        $options = [];
        foreach (WalletAccount::activeList() as $account) {
            $networkCode = (string)$account['network_code'];
            if (!$this->isAvailable($networkCode)) {
                continue;
            }
            $options[] = $this->networkOption($networkCode);
        }

        return $options;
    }

    public function assertAvailable(string $networkCode, string $tokenCode): void
    {
        $reason = '';
        if (!$this->isAvailable($networkCode, $tokenCode, $reason)) {
            throw new InvalidArgumentException($reason ?: '当前网络暂不可创建收款订单');
        }
    }

    public function isAvailable(string $networkCode, string $tokenCode = '', ?string &$reason = null): bool
    {
        $networkCode = trim($networkCode);
        $tokenCode = strtoupper(trim($tokenCode));

        if ($networkCode === '' || !config('chains.networks.' . $networkCode)) {
            $reason = '网络不存在';
            return false;
        }

        if (!WalletAccount::findByNetwork($networkCode)) {
            $reason = '当前网络账户不存在或未启用';
            return false;
        }

        $setting = RpcNetworkSetting::findByNetwork($networkCode);
        if (!$setting || (int)($setting['enabled'] ?? 0) !== 1) {
            $reason = '当前网络未启用自动监听，不能创建收款订单';
            return false;
        }

        $groupId = (int)($setting['active_group_id'] ?? 0);
        if ($groupId <= 0) {
            $reason = '当前网络未选择 RPC 分组';
            return false;
        }

        $group = RpcGroup::findById($groupId);
        if (!$group || (string)$group['network_code'] !== $networkCode) {
            $reason = '当前网络选择的 RPC 分组不存在';
            return false;
        }

        if (!RpcConfig::enabledByGroup($groupId)) {
            $reason = '当前网络 RPC 分组下没有启用的 RPC 节点';
            return false;
        }

        if ($tokenCode !== '') {
            if (!NetworkToken::enabledByNetworkToken($networkCode, $tokenCode)) {
                $reason = '当前网络未启用 ' . $tokenCode . ' 合约';
                return false;
            }
            return true;
        }

        if (!NetworkToken::enabledByNetwork($networkCode)) {
            $reason = '当前网络没有启用的收款代币';
            return false;
        }

        return true;
    }

    private function networkOption(string $networkCode): array
    {
        return [
            'label' => (string)(config('chains.networks.' . $networkCode . '.name') ?: $networkCode),
            'value' => $networkCode,
            'chain_id' => (int)(config('chains.networks.' . $networkCode . '.chain_id') ?: 0),
            'native_symbol' => (string)(config('chains.networks.' . $networkCode . '.native_symbol') ?: 'ETH'),
            'tokens' => array_values(array_map(
                static fn(array $token) => (string)$token['token_code'],
                NetworkToken::enabledByNetwork($networkCode)
            )),
        ];
    }
}
