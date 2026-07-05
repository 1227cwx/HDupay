<?php

namespace app\model;

class Network extends BaseModel
{
    protected $table = 'networks';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'code',
        'name',
        'chain_id',
        'status',
        'created_at',
        'updated_at',
    ];

    public static function findByCode(string $code): ?array
    {
        $row = self::query()->where('code', $code)->first();
        return $row ? $row->toArray() : null;
    }

    public static function enabledList(): array
    {
        return self::query()->where('status', 'enabled')->orderBy('id')->get()->toArray();
    }

}
