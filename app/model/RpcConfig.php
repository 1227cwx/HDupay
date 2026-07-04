<?php

namespace app\model;

class RpcConfig extends BaseModel
{
    protected $table = 'rpc_configs';
    protected $primaryKey = 'id';

    public static function allList(): array
    {
        return self::query()->orderBy('network_code')->orderBy('group_id')->orderBy('sort_order')->orderBy('id')->get()->toArray();
    }

    public static function listByNetwork(string $networkCode): array
    {
        return self::query()->where('network_code', $networkCode)->orderBy('group_id')->orderBy('sort_order')->orderBy('id')->get()->toArray();
    }

    public static function enabledByGroup(int $groupId): array
    {
        return self::query()
            ->where('group_id', $groupId)
            ->where('enabled', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public static function countByGroupId(int $groupId): int
    {
        return (int)self::query()->where('group_id', $groupId)->count();
    }

    public static function countByProxyId(int $proxyId): int
    {
        return (int)self::query()->where('proxy_id', $proxyId)->count();
    }

    public static function deleteById(int $id): int
    {
        return self::query()->where('id', $id)->delete();
    }
}
