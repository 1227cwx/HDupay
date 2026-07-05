<?php

namespace app\model;

class NetworkToken extends BaseModel
{
    protected $table = 'network_tokens';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'network_code',
        'token_code',
        'contract_address',
        'decimals',
        'standard',
        'status',
        'created_at',
        'updated_at',
    ];

    public static function findByNetworkToken(string $networkCode, string $tokenCode): ?array
    {
        $row = self::query()
            ->where('network_code', $networkCode)
            ->where('token_code', strtoupper($tokenCode))
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function enabledByNetworkToken(string $networkCode, string $tokenCode): ?array
    {
        $row = self::query()
            ->where('network_code', $networkCode)
            ->where('token_code', strtoupper($tokenCode))
            ->where('status', 'enabled')
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function enabledByNetwork(string $networkCode): array
    {
        return self::query()
            ->where('network_code', $networkCode)
            ->where('status', 'enabled')
            ->orderBy('token_code')
            ->get()
            ->toArray();
    }

    public static function saveForNetworkToken(string $networkCode, string $tokenCode, array $data): array
    {
        $tokenCode = strtoupper($tokenCode);
        $exists = self::findByNetworkToken($networkCode, $tokenCode);
        if ($exists) {
            self::updateById((int)$exists['id'], $data);
            return self::findByNetworkToken($networkCode, $tokenCode) ?? [];
        }

        $data['network_code'] = $networkCode;
        $data['token_code'] = $tokenCode;
        return self::createRecord($data);
    }

}
