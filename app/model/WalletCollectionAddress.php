<?php

namespace app\model;

class WalletCollectionAddress extends BaseModel
{
    protected $table = 'wallet_collection_addresses';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'wallet_account_id',
        'network_code',
        'address',
        'address_lower',
        'address_type',
        'is_active',
        'sync_enabled',
        'usdc_balance_int',
        'usdt_balance_int',
        'native_balance_wei',
        'sync_status',
        'sync_error',
        'last_balance_sync_at',
        'created_at',
        'updated_at',
    ];

    public static function listAll(): array
    {
        return self::query()->orderBy('network_code')->orderBy('wallet_account_id')->orderBy('id')->get()->toArray();
    }

    public static function listByAccountId(int $walletAccountId): array
    {
        return self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public static function activeByAccountId(int $walletAccountId): ?array
    {
        $row = self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->where('is_active', 1)
            ->orderBy('id')
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function systemByAccountId(int $walletAccountId): ?array
    {
        $row = self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->where('address_type', 'system')
            ->orderBy('id')
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function findByAccountAddress(int $walletAccountId, string $address): ?array
    {
        $row = self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->where('address_lower', strtolower($address))
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function deactivateByAccountId(int $walletAccountId): int
    {
        return self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->update([
                'is_active' => 0,
                'updated_at' => self::now(),
            ]);
    }

    public static function syncableList(): array
    {
        return self::query()
            ->where('sync_enabled', 1)
            ->orderBy('network_code')
            ->orderBy('wallet_account_id')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public static function latestBalanceSyncAt(): string
    {
        return (string)(self::query()->whereNotNull('last_balance_sync_at')->max('last_balance_sync_at') ?: '');
    }

    public static function deleteByAccountIds(array $accountIds): int
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) {
            return 0;
        }
        return self::query()->whereIn('wallet_account_id', $accountIds)->delete();
    }

    public static function deleteSystemByAccountIds(array $accountIds): int
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) {
            return 0;
        }
        return self::query()
            ->whereIn('wallet_account_id', $accountIds)
            ->where('address_type', 'system')
            ->delete();
    }

    public static function deleteById(int $id): int
    {
        return self::query()->where('id', $id)->delete();
    }
}
