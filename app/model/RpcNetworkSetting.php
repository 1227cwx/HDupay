<?php

namespace app\model;

class RpcNetworkSetting extends BaseModel
{
    protected $table = 'rpc_network_settings';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'network_code',
        'contract_address',
        'decimals',
        'monitor_interval_seconds',
        'min_confirm_blocks',
        'confirm_blocks',
        'large_amount_threshold',
        'scan_step_blocks',
        'active_group_id',
        'enabled',
        'last_monitor_at',
        'created_at',
        'updated_at',
    ];

    public static function findByNetwork(string $networkCode): ?array
    {
        $row = self::query()->where('network_code', $networkCode)->first();
        return $row ? $row->toArray() : null;
    }

    public static function saveForNetwork(string $networkCode, array $data): array
    {
        $exists = self::findByNetwork($networkCode);
        if ($exists) {
            self::updateById((int)$exists['id'], $data);
            return self::findByNetwork($networkCode) ?? [];
        }

        $data['network_code'] = $networkCode;
        return self::createRecord($data);
    }

    public static function enabledList(): array
    {
        return self::query()->where('enabled', 1)->orderBy('network_code')->get()->toArray();
    }

    public static function allList(): array
    {
        return self::query()->orderBy('network_code')->get()->toArray();
    }

    public static function markMonitorAt(string $networkCode): bool
    {
        return self::query()->where('network_code', $networkCode)->update([
            'last_monitor_at' => self::now(),
            'updated_at' => self::now(),
        ]) > 0;
    }
}
