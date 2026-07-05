<?php

namespace app\model;

class RpcGroup extends BaseModel
{
    protected $table = 'rpc_groups';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'network_code',
        'name',
        'rotation_mode',
        'single_attempts',
        'max_nodes',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'network_code',
        'rotation_mode',
    ];

    public static function allList(): array
    {
        return self::query()->orderBy('network_code')->orderBy('id')->get()->toArray();
    }

    public static function listByNetwork(string $networkCode): array
    {
        return self::query()->where('network_code', $networkCode)->orderBy('id')->get()->toArray();
    }

    public static function firstByNetwork(string $networkCode): ?array
    {
        $row = self::query()->where('network_code', $networkCode)->orderBy('id')->first();
        return $row ? $row->toArray() : null;
    }

    public static function findByNetworkName(string $networkCode, string $name): ?array
    {
        $row = self::query()->where('network_code', $networkCode)->where('name', $name)->first();
        return $row ? $row->toArray() : null;
    }

    public static function countByNetwork(string $networkCode): int
    {
        return (int)self::query()->where('network_code', $networkCode)->count();
    }

    public static function deleteById(int $id): int
    {
        return self::query()->where('id', $id)->delete();
    }
}
