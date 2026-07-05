<?php

namespace app\model;

class WalletMaster extends BaseModel
{
    protected $table = 'wallet_masters';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'name',
        'mnemonic_fingerprint',
        'encrypted_seed_or_xprv',
        'status',
        'created_at',
        'updated_at',
    ];

    public static function latestActive(): ?array
    {
        $row = self::query()->where('status', 'active')->orderByDesc('id')->first();
        return $row ? $row->toArray() : null;
    }

    public static function activeCount(): int
    {
        return (int)self::query()->where('status', 'active')->count();
    }

    public static function deleteById(int $id): int
    {
        return self::query()->where('id', $id)->delete();
    }
}
