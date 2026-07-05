<?php

namespace app\model;

class SystemSetting extends BaseModel
{
    protected $table = 'system_settings';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'key_name',
        'value',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'key_name',
    ];

    public static function getValue(string $key, string $default = ''): string
    {
        $row = self::query()->where('key_name', $key)->first();
        if (!$row) {
            return $default;
        }
        return (string)($row->toArray()['value'] ?? $default);
    }

    public static function saveValue(string $key, string $value): array
    {
        $row = self::query()->where('key_name', $key)->first();
        if ($row) {
            self::updateById((int)$row->toArray()['id'], ['value' => $value]);
            return self::query()->where('key_name', $key)->first()?->toArray() ?? [];
        }

        return self::createRecord([
            'key_name' => $key,
            'value' => $value,
        ]);
    }
}
