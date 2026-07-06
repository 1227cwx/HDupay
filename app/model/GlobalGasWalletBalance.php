<?php

namespace app\model;

class GlobalGasWalletBalance extends BaseModel
{
    protected $table = 'global_gas_wallet_balances';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'wallet_master_id',
        'network_code',
        'native_symbol',
        'balance_wei',
        'sync_enabled',
        'sync_status',
        'sync_error',
        'last_balance_sync_at',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'wallet_master_id',
        'network_code',
        'sync_enabled',
        'sync_status',
    ];

    public static function listByMasterId(int $masterId): array
    {
        return self::query()
            ->where('wallet_master_id', $masterId)
            ->orderBy('network_code')
            ->get()
            ->toArray();
    }

    public static function enabledListByMasterId(int $masterId): array
    {
        return self::query()
            ->where('wallet_master_id', $masterId)
            ->where('sync_enabled', 1)
            ->orderBy('network_code')
            ->get()
            ->toArray();
    }

    public static function findByMasterNetwork(int $masterId, string $networkCode): ?array
    {
        $row = self::query()
            ->where('wallet_master_id', $masterId)
            ->where('network_code', $networkCode)
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function saveForMasterNetwork(int $masterId, string $networkCode, array $data): array
    {
        $row = self::findByMasterNetwork($masterId, $networkCode);
        $data['wallet_master_id'] = $masterId;
        $data['network_code'] = $networkCode;
        if ($row) {
            self::updateById((int)$row['id'], $data);
            return self::findById((int)$row['id']) ?: $row;
        }
        return self::createRecord($data);
    }

    public static function updateByMasterNetwork(int $masterId, string $networkCode, array $data): bool
    {
        $row = self::findByMasterNetwork($masterId, $networkCode);
        if (!$row) {
            return false;
        }
        return self::updateById((int)$row['id'], $data);
    }

    public static function latestBalanceSyncAt(): string
    {
        return (string)(self::query()->whereNotNull('last_balance_sync_at')->max('last_balance_sync_at') ?: '');
    }

    public static function deleteByMasterId(int $masterId): int
    {
        return self::query()->where('wallet_master_id', $masterId)->delete();
    }
}
