<?php

namespace app\model;

use LogicException;
use support\Model;

abstract class BaseModel extends Model
{
    protected $guarded = ['*'];
    protected static array $fields = [];
    protected static array $filterFields = [];
    public $timestamps = false;

    protected static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function createRecord(array $data): array
    {
        $now = static::now();
        $data = static::filterWriteData($data);
        if (static::isKnownField('created_at')) {
            $data['created_at'] = $data['created_at'] ?? $now;
        }
        if (static::isKnownField('updated_at')) {
            $data['updated_at'] = $data['updated_at'] ?? $now;
        }
        $id = static::query()->insertGetId($data);
        return static::findById((int)$id) ?? [];
    }

    public static function transaction(callable $callback): mixed
    {
        return static::query()->getModel()->getConnection()->transaction($callback);
    }

    public static function findById(int $id): ?array
    {
        $row = static::query()->where('id', $id)->first();
        return $row ? $row->toArray() : null;
    }

    public static function updateById(int $id, array $data): bool
    {
        $data = static::filterWriteData($data);
        if (!$data) {
            return false;
        }
        if (static::isKnownField('updated_at')) {
            $data['updated_at'] = $data['updated_at'] ?? static::now();
        }
        return static::query()->where('id', $id)->update($data) > 0;
    }


    public static function countAll(): int
    {
        return (int)static::query()->count();
    }

    public static function deleteAllRows(): int
    {
        $count = static::countAll();
        if ($count > 0) {
            static::query()->delete();
        }
        return $count;
    }

    public static function countByField(string $field, string|int $value): int
    {
        if (!static::isKnownField($field)) {
            return 0;
        }
        return (int)static::query()->where($field, $value)->count();
    }


    public static function listPage(array $filters = [], int $page = 1, int $perPage = 10, string $orderBy = 'id', string $direction = 'desc'): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $orderBy = static::isKnownField($orderBy) ? $orderBy : 'id';
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $query = static::query();
        $filterFields = static::filterFieldMap();
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (!isset($filterFields[(string)$field])) {
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

    protected static function filterWriteData(array $data): array
    {
        $fields = static::fieldMap();
        unset($data['id']);
        return array_intersect_key($data, $fields);
    }

    protected static function isKnownField(string $field): bool
    {
        return $field === 'id' || isset(static::fieldMap()[$field]);
    }

    private static function fieldMap(): array
    {
        if (static::$fields === []) {
            throw new LogicException(static::class . ' must define writable fields.');
        }
        return array_flip(static::$fields);
    }

    private static function filterFieldMap(): array
    {
        return array_flip(static::$filterFields);
    }
}
