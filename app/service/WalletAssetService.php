<?php

namespace app\service;

use app\model\SystemSetting;
use app\model\WalletAccount;
use app\model\WalletCollectionAddress;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use support\Log;

class WalletAssetService
{
    private const COLLECTION_ENABLED_KEY = 'wallet.collection_balance_sync_enabled';
    private const COLLECTION_INTERVAL_KEY = 'wallet.collection_balance_sync_interval_minutes';
    private const COLLECTION_LAST_SYNC_KEY = 'wallet.collection_balance_last_sync_at';
    private const GAS_ENABLED_KEY = 'wallet.gas_balance_sync_enabled';
    private const GAS_INTERVAL_KEY = 'wallet.gas_balance_sync_interval_minutes';
    private const GAS_LAST_SYNC_KEY = 'wallet.gas_balance_last_sync_at';

    public function __construct()
    {
        $this->ensureCollectionRows();
    }

    public function collectionWallets(): array
    {
        $accounts = $this->accountsWithNetworkMeta();
        $addresses = [];
        foreach (WalletCollectionAddress::listAll() as $row) {
            $addresses[(int)$row['wallet_account_id']][] = $this->sanitizeCollectionAddress($row);
        }

        foreach ($accounts as &$account) {
            $account['collection_addresses'] = $addresses[(int)$account['id']] ?? [];
        }
        unset($account);

        return [
            'settings' => [
                'balance_sync_enabled' => $this->collectionBalanceSyncEnabled() ? 1 : 0,
                'balance_sync_interval_minutes' => $this->collectionBalanceSyncIntervalMinutes(),
                'balance_last_sync_at' => $this->collectionBalanceLastSyncAt(),
            ],
            'accounts' => $accounts,
        ];
    }

    public function saveCollectionConfig(array $input): array
    {
        $interval = (int)($input['balance_sync_interval_minutes'] ?? 60);
        if ($interval < 1 || $interval > 1440) {
            throw new InvalidArgumentException('归集钱包余额同步间隔必须在 1 到 1440 分钟之间');
        }
        SystemSetting::saveValue(self::COLLECTION_ENABLED_KEY, $this->boolValue($input['balance_sync_enabled'] ?? $this->collectionBalanceSyncEnabled()) ? '1' : '0');
        SystemSetting::saveValue(self::COLLECTION_INTERVAL_KEY, (string)$interval);
        return $this->collectionWallets();
    }

    public function addCollectionAddress(array $input): array
    {
        $account = $this->activeAccount((int)($input['wallet_account_id'] ?? 0));
        $address = strtolower(trim((string)($input['address'] ?? '')));
        $this->assertEvmAddress($address);
        if (WalletCollectionAddress::findByAccountAddress((int)$account['id'], $address)) {
            throw new RuntimeException('该归集地址已经存在');
        }

        WalletCollectionAddress::createRecord([
            'wallet_account_id' => (int)$account['id'],
            'network_code' => (string)$account['network_code'],
            'address' => $address,
            'address_lower' => $address,
            'address_type' => 'third_party',
            'is_active' => 0,
            'sync_enabled' => 1,
            'usdc_balance_int' => '0',
            'usdt_balance_int' => '0',
            'native_balance_wei' => '0',
            'sync_status' => 'pending',
            'sync_error' => '',
        ]);

        return $this->collectionWallets();
    }

    public function setCollectionActive(int $id, bool $active): array
    {
        $row = WalletCollectionAddress::findById($id);
        if (!$row) {
            throw new RuntimeException('归集地址不存在');
        }
        $account = $this->activeAccount((int)$row['wallet_account_id']);

        if (!$active) {
            $system = WalletCollectionAddress::systemByAccountId((int)$account['id']);
            if (!$system) {
                throw new RuntimeException('系统生成归集地址不存在，不能关闭当前归集地址');
            }
            $row = $system;
        }

        WalletCollectionAddress::deactivateByAccountId((int)$account['id']);
        WalletCollectionAddress::updateById((int)$row['id'], ['is_active' => 1]);
        $this->syncWalletAccountCollectionTarget($account, WalletCollectionAddress::findById((int)$row['id']) ?: $row);

        return $this->collectionWallets();
    }

    public function setCollectionSyncEnabled(int $id, bool $enabled): array
    {
        if (!WalletCollectionAddress::findById($id)) {
            throw new RuntimeException('归集地址不存在');
        }
        WalletCollectionAddress::updateById($id, ['sync_enabled' => $enabled ? 1 : 0]);
        return $this->collectionWallets();
    }

    public function deleteCollectionAddress(int $id): array
    {
        $row = WalletCollectionAddress::findById($id);
        if (!$row) {
            throw new RuntimeException('归集地址不存在');
        }
        if (($row['address_type'] ?? '') === 'system') {
            throw new RuntimeException('系统生成归集地址不能删除');
        }
        if (!empty($row['is_active'])) {
            $system = WalletCollectionAddress::systemByAccountId((int)$row['wallet_account_id']);
            if ($system) {
                WalletCollectionAddress::deactivateByAccountId((int)$row['wallet_account_id']);
                WalletCollectionAddress::updateById((int)$system['id'], ['is_active' => 1]);
                $account = WalletAccount::findById((int)$row['wallet_account_id']);
                if ($account) {
                    $this->syncWalletAccountCollectionTarget($account, $system);
                }
            }
        }
        WalletCollectionAddress::deleteById($id);
        return $this->collectionWallets();
    }

    public function syncCollectionAddress(int $id): array
    {
        $row = WalletCollectionAddress::findById($id);
        if (!$row) {
            throw new RuntimeException('归集地址不存在');
        }
        $this->syncOneCollectionAddress($row, true);
        return $this->collectionWallets();
    }

    public function syncAllCollectionBalances(): array
    {
        $count = 0;
        foreach (WalletCollectionAddress::syncableList() as $row) {
            try {
                $this->syncOneCollectionAddress($row, false);
                $count++;
            } catch (Throwable $e) {
                Log::error('归集钱包余额同步失败', [
                    'address_id' => $row['id'] ?? 0,
                    'network' => $row['network_code'] ?? '',
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $lastSyncAt = date('Y-m-d H:i:s');
        SystemSetting::saveValue(self::COLLECTION_LAST_SYNC_KEY, $lastSyncAt);
        return ['synced' => $count, 'last_sync_at' => $lastSyncAt];
    }

    public function gasWallets(): array
    {
        return [
            'settings' => [
                'balance_sync_enabled' => $this->gasBalanceSyncEnabled() ? 1 : 0,
                'balance_sync_interval_minutes' => $this->gasBalanceSyncIntervalMinutes(),
                'balance_last_sync_at' => $this->gasBalanceLastSyncAt(),
            ],
            'accounts' => $this->accountsWithNetworkMeta(true),
        ];
    }

    public function saveGasConfig(array $input): array
    {
        $interval = (int)($input['balance_sync_interval_minutes'] ?? 60);
        if ($interval < 1 || $interval > 1440) {
            throw new InvalidArgumentException('Gas 钱包余额同步间隔必须在 1 到 1440 分钟之间');
        }
        SystemSetting::saveValue(self::GAS_ENABLED_KEY, $this->boolValue($input['balance_sync_enabled'] ?? $this->gasBalanceSyncEnabled()) ? '1' : '0');
        SystemSetting::saveValue(self::GAS_INTERVAL_KEY, (string)$interval);
        return $this->gasWallets();
    }

    public function setGasSyncEnabled(int $walletAccountId, bool $enabled): array
    {
        $account = WalletAccount::findById($walletAccountId);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }
        WalletAccount::updateById($walletAccountId, ['gas_sync_enabled' => $enabled ? 1 : 0]);
        return $this->gasWallets();
    }

    public function syncGasWallet(int $walletAccountId): array
    {
        $account = WalletAccount::findById($walletAccountId);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }
        $this->syncOneGasWallet($account, true);
        return $this->gasWallets();
    }

    public function syncAllGasBalances(): array
    {
        $count = 0;
        foreach (WalletAccount::listPage([], 1, 100, 'id', 'asc')['items'] as $account) {
            if ((int)($account['gas_sync_enabled'] ?? 1) !== 1) {
                continue;
            }
            try {
                $this->syncOneGasWallet($account, false);
                $count++;
            } catch (Throwable $e) {
                Log::error('Gas 钱包余额同步失败', [
                    'wallet_account_id' => $account['id'] ?? 0,
                    'network' => $account['network_code'] ?? '',
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $lastSyncAt = date('Y-m-d H:i:s');
        SystemSetting::saveValue(self::GAS_LAST_SYNC_KEY, $lastSyncAt);
        return ['synced' => $count, 'last_sync_at' => $lastSyncAt];
    }

    public function activeCollectionAddressForAccount(array $account): ?array
    {
        $this->ensureCollectionRow($account);
        $active = WalletCollectionAddress::activeByAccountId((int)$account['id']);
        if ($active) {
            return $active;
        }
        $system = WalletCollectionAddress::systemByAccountId((int)$account['id']);
        if ($system) {
            WalletCollectionAddress::deactivateByAccountId((int)$account['id']);
            WalletCollectionAddress::updateById((int)$system['id'], ['is_active' => 1]);
            $this->syncWalletAccountCollectionTarget($account, $system);
            return WalletCollectionAddress::findById((int)$system['id']) ?: $system;
        }
        return null;
    }

    public function loop(): void
    {
        while (true) {
            try {
                if ($this->collectionBalanceSyncEnabled() && $this->balanceSyncDue($this->collectionBalanceLastSyncAt(), $this->collectionBalanceSyncIntervalMinutes())) {
                    Log::info('归集钱包余额同步完成', $this->syncAllCollectionBalances());
                }

                if ($this->gasBalanceSyncEnabled() && $this->balanceSyncDue($this->gasBalanceLastSyncAt(), $this->gasBalanceSyncIntervalMinutes())) {
                    Log::info('Gas 钱包余额同步完成', $this->syncAllGasBalances());
                }
            } catch (Throwable $e) {
                Log::error('钱包余额同步进程失败：' . $e->getMessage());
            }
            sleep(60);
        }
    }

    private function ensureCollectionRows(): void
    {
        foreach (WalletAccount::listPage([], 1, 100, 'id', 'asc')['items'] as $account) {
            $this->ensureCollectionRow($account);
        }
    }

    private function ensureCollectionRow(array $account): void
    {
        $account = (new EvmWalletService())->ensureAccountSystemAddresses($account);
        $accountId = (int)($account['id'] ?? 0);
        $systemAddress = strtolower((string)($account['collection_address'] ?? ''));
        if (($account['collection_type'] ?? 'local') === 'exchange' && !empty($account['collection_derivation_path'])) {
            $seedHex = (new CryptoService())->decrypt((string)($account['encrypted_xprv'] ?? ''));
            $local = (new EvmWalletService())->deriveAddressFromSeed($seedHex, (string)$account['collection_derivation_path']);
            $systemAddress = strtolower((string)$local['address']);
        }
        if ($accountId <= 0 || $systemAddress === '') {
            return;
        }
        if (WalletCollectionAddress::systemByAccountId($accountId)) {
            return;
        }
        $hasActive = WalletCollectionAddress::activeByAccountId($accountId) !== null;
        WalletCollectionAddress::createRecord([
            'wallet_account_id' => $accountId,
            'network_code' => (string)$account['network_code'],
            'address' => $systemAddress,
            'address_lower' => $systemAddress,
            'address_type' => 'system',
            'is_active' => $hasActive ? 0 : 1,
            'sync_enabled' => 1,
            'usdc_balance_int' => '0',
            'usdt_balance_int' => '0',
            'native_balance_wei' => '0',
            'sync_status' => 'pending',
            'sync_error' => '',
        ]);
    }

    private function activeAccount(int $walletAccountId): array
    {
        if ($walletAccountId <= 0) {
            throw new InvalidArgumentException('网络账户 ID 无效');
        }
        $account = WalletAccount::findById($walletAccountId);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }
        if (($account['status'] ?? '') !== 'active') {
            throw new RuntimeException('网络账户已停用，请先启用后再操作');
        }
        return $account;
    }

    private function syncWalletAccountCollectionTarget(array $account, array $address): void
    {
        $type = ($address['address_type'] ?? '') === 'third_party' ? 'exchange' : 'local';
        WalletAccount::updateById((int)$account['id'], [
            'collection_type' => $type,
            'collection_address' => strtolower((string)$address['address_lower']),
        ]);
    }

    private function syncOneCollectionAddress(array $row, bool $throw): void
    {
        try {
            $rpc = new EvmRpcService();
            $networkCode = (string)$row['network_code'];
            $address = (string)$row['address_lower'];
            $usdc = $rpc->tokenConfig($networkCode, 'USDC');
            $usdt = $rpc->tokenConfig($networkCode, 'USDT');
            WalletCollectionAddress::updateById((int)$row['id'], [
                'usdc_balance_int' => $rpc->tokenBalanceOf($networkCode, (string)$usdc['contract_address'], $address),
                'usdt_balance_int' => $rpc->tokenBalanceOf($networkCode, (string)$usdt['contract_address'], $address),
                'native_balance_wei' => $rpc->getBalance($networkCode, $address),
                'sync_status' => 'success',
                'sync_error' => '',
                'last_balance_sync_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            WalletCollectionAddress::updateById((int)$row['id'], [
                'sync_status' => 'failed',
                'sync_error' => $e->getMessage(),
                'last_balance_sync_at' => date('Y-m-d H:i:s'),
            ]);
            if ($throw) {
                throw $e;
            }
        }
    }

    private function syncOneGasWallet(array $account, bool $throw): void
    {
        try {
            if (empty($account['gas_funder_address'])) {
                throw new RuntimeException('Gas 钱包地址未配置');
            }
            $balance = (new EvmRpcService())->getBalance((string)$account['network_code'], (string)$account['gas_funder_address']);
            WalletAccount::updateById((int)$account['id'], [
                'gas_native_balance_wei' => $balance,
                'gas_sync_status' => 'success',
                'gas_sync_error' => '',
                'gas_last_balance_sync_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            WalletAccount::updateById((int)$account['id'], [
                'gas_sync_status' => 'failed',
                'gas_sync_error' => $e->getMessage(),
                'gas_last_balance_sync_at' => date('Y-m-d H:i:s'),
            ]);
            if ($throw) {
                throw $e;
            }
        }
    }

    private function accountsWithNetworkMeta(bool $includeGasMeta = false): array
    {
        $accounts = WalletAccount::listPage([], 1, 100, 'id', 'asc')['items'];
        foreach ($accounts as &$account) {
            $networkCode = (string)$account['network_code'];
            $account['network_name'] = config('chains.networks.' . $networkCode . '.name') ?: $networkCode;
            $account['chain_id'] = (int)(config('chains.networks.' . $networkCode . '.chain_id') ?: 0);
            $account['native_symbol'] = $this->nativeSymbol($networkCode);
            $account['encrypted_xprv'] = '';
            $account['encrypted_gas_funder_private_key'] = '';
            if ($includeGasMeta) {
                $account['gas_native_balance'] = $this->formatTokenAmount((string)($account['gas_native_balance_wei'] ?? '0'), 18);
            }
        }
        unset($account);
        return $accounts;
    }

    private function sanitizeCollectionAddress(array $row): array
    {
        $row['usdc_balance'] = $this->formatTokenAmount((string)($row['usdc_balance_int'] ?? '0'), 6);
        $row['usdt_balance'] = $this->formatTokenAmount((string)($row['usdt_balance_int'] ?? '0'), 6);
        $row['native_symbol'] = $this->nativeSymbol((string)$row['network_code']);
        $row['native_balance'] = $this->formatTokenAmount((string)($row['native_balance_wei'] ?? '0'), 18);
        return $row;
    }

    private function collectionBalanceSyncIntervalMinutes(): int
    {
        return min(1440, max(1, (int)SystemSetting::getValue(self::COLLECTION_INTERVAL_KEY, '60')));
    }

    private function collectionBalanceSyncEnabled(): bool
    {
        return SystemSetting::getValue(self::COLLECTION_ENABLED_KEY, '1') === '1';
    }

    private function collectionBalanceLastSyncAt(): string
    {
        $lastSyncAt = trim(SystemSetting::getValue(self::COLLECTION_LAST_SYNC_KEY, ''));
        return $lastSyncAt !== '' ? $lastSyncAt : WalletCollectionAddress::latestBalanceSyncAt();
    }

    private function gasBalanceSyncIntervalMinutes(): int
    {
        return min(1440, max(1, (int)SystemSetting::getValue(self::GAS_INTERVAL_KEY, '60')));
    }

    private function gasBalanceSyncEnabled(): bool
    {
        return SystemSetting::getValue(self::GAS_ENABLED_KEY, '1') === '1';
    }

    private function gasBalanceLastSyncAt(): string
    {
        $lastSyncAt = trim(SystemSetting::getValue(self::GAS_LAST_SYNC_KEY, ''));
        return $lastSyncAt !== '' ? $lastSyncAt : WalletAccount::latestGasBalanceSyncAt();
    }

    private function balanceSyncDue(string $lastSyncAt, int $intervalMinutes): bool
    {
        if ($lastSyncAt === '') {
            return true;
        }
        $timestamp = strtotime($lastSyncAt);
        if (!$timestamp) {
            return true;
        }
        return time() - $timestamp >= $intervalMinutes * 60;
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function assertEvmAddress(string $address): void
    {
        if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            throw new InvalidArgumentException('请输入正确的当前网络 EVM 地址，格式必须是 0x 开头的 40 位十六进制地址');
        }
    }

    private function nativeSymbol(string $networkCode): string
    {
        $symbol = config('chains.networks.' . $networkCode . '.native_symbol');
        return $symbol ? (string)$symbol : 'ETH';
    }

    private function formatTokenAmount(string $amountInt, int $decimals): string
    {
        return (new TokenAmountService())->toDisplay($amountInt, $decimals);
    }
}
