<?php

namespace app\model;

class WalletAccount extends BaseModel
{
    protected $table = 'wallet_accounts';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'wallet_master_id',
        'network_code',
        'derivation_path',
        'xpub',
        'encrypted_xprv',
        'next_index',
        'deposit_timeout_minutes',
        'collection_type',
        'collection_address',
        'collection_derivation_path',
        'gas_funder_address',
        'gas_funder_derivation_path',
        'encrypted_gas_funder_private_key',
        'gas_sync_enabled',
        'gas_native_balance_wei',
        'gas_sync_status',
        'gas_sync_error',
        'gas_last_balance_sync_at',
        'status',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'wallet_master_id',
        'network_code',
        'status',
        'gas_sync_enabled',
        'gas_sync_status',
    ];

    public static function findByNetwork(string $networkCode): ?array
    {
        $row = self::query()->where('network_code', $networkCode)->where('status', 'active')->orderByDesc('id')->first();
        return $row ? $row->toArray() : null;
    }

    public static function findByNetworkForUpdate(string $networkCode): ?array
    {
        $row = self::query()
            ->where('network_code', $networkCode)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function findAnyByNetwork(string $networkCode): ?array
    {
        $row = self::query()->where('network_code', $networkCode)->orderByDesc('id')->first();
        return $row ? $row->toArray() : null;
    }

    public static function incrementNextIndex(int $id): bool
    {
        return self::query()->where('id', $id)->increment('next_index') > 0;
    }

    public static function activeList(): array
    {
        return self::query()->where('status', 'active')->orderBy('id')->get()->toArray();
    }

    public static function usedNetworkCodes(): array
    {
        return self::query()
            ->pluck('network_code')
            ->map(fn($code) => (string)$code)
            ->toArray();
    }

    public static function idsByMasterId(int $masterId): array
    {
        return self::query()
            ->where('wallet_master_id', $masterId)
            ->pluck('id')
            ->map(fn($id) => (int)$id)
            ->toArray();
    }

    public static function listByMasterId(int $masterId): array
    {
        return self::query()
            ->where('wallet_master_id', $masterId)
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public static function deleteByMasterId(int $masterId): int
    {
        return self::query()->where('wallet_master_id', $masterId)->delete();
    }

    public static function latestGasBalanceSyncAt(): string
    {
        return (string)(self::query()->whereNotNull('gas_last_balance_sync_at')->max('gas_last_balance_sync_at') ?: '');
    }
}
