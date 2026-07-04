<?php

namespace app\model;

use support\Model;

abstract class BaseModel extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function createRecord(array $data): array
    {
        $now = static::now();
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $id = static::query()->insertGetId($data);
        return static::findById((int)$id) ?? [];
    }

    public static function findById(int $id): ?array
    {
        $row = static::query()->where('id', $id)->first();
        return $row ? $row->toArray() : null;
    }

    public static function updateById(int $id, array $data): bool
    {
        $data['updated_at'] = $data['updated_at'] ?? static::now();
        return static::query()->where('id', $id)->update($data) > 0;
    }


    public static function countAll(): int
    {
        return (int)static::query()->count();
    }

    public static function countByField(string $field, string|int $value): int
    {
        return (int)static::query()->where($field, $value)->count();
    }


    public static function listPage(array $filters = [], int $page = 1, int $perPage = 10, string $orderBy = 'id', string $direction = 'desc'): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $query = static::query();
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query->where($field, $value);
        }
        $total = (clone $query)->count();
        $items = $query->orderBy($orderBy, $direction)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->toArray();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
