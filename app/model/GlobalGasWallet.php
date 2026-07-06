<?php

namespace app\model;

class GlobalGasWallet extends BaseModel
{
    protected $table = 'global_gas_wallets';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'wallet_master_id',
        'address',
        'address_lower',
        'derivation_path',
        'encrypted_private_key',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'wallet_master_id',
        'address_lower',
    ];

    public static function findByMasterId(int $masterId): ?array
    {
        $row = self::query()
            ->where('wallet_master_id', $masterId)
            ->orderByDesc('id')
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function deleteByMasterId(int $masterId): int
    {
        return self::query()->where('wallet_master_id', $masterId)->delete();
    }

    public static function acquireGasWalletLock(string $networkCode): bool
    {
        $row = self::query()
            ->getConnection()
            ->selectOne('SELECT GET_LOCK(?, 0) AS locked', [self::gasWalletLockName($networkCode)]);
        return (int)self::dbValue($row, 'locked') === 1;
    }

    public static function releaseGasWalletLock(string $networkCode): void
    {
        try {
            self::query()
                ->getConnection()
                ->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::gasWalletLockName($networkCode)]);
        } catch (\Throwable) {
        }
    }

    private static function gasWalletLockName(string $networkCode): string
    {
        return 'hdupay:gas-wallet:' . sha1(strtolower($networkCode));
    }

    private static function dbValue(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }
        if (is_object($row)) {
            return $row->{$key} ?? null;
        }
        return null;
    }
}
