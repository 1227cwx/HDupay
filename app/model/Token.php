<?php

namespace app\model;

class Token extends BaseModel
{
    protected $table = 'tokens';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'code',
        'name',
        'decimals',
        'status',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'code',
        'status',
    ];

    public static function findByCode(string $code): ?array
    {
        $row = self::query()->where('code', strtoupper($code))->first();
        return $row ? $row->toArray() : null;
    }

    public static function saveForCode(string $code, array $data): array
    {
        $code = strtoupper($code);
        $exists = self::findByCode($code);
        if ($exists) {
            self::updateById((int)$exists['id'], $data);
            return self::findByCode($code) ?? [];
        }

        $data['code'] = $code;
        return self::createRecord($data);
    }

}
