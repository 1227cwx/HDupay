<?php

namespace app\model;

class ProxyPool extends BaseModel
{
    protected $table = 'proxy_pools';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'name',
        'proxy_type',
        'host',
        'port',
        'username',
        'password_cipher',
        'password_masked',
        'status',
        'last_test_status',
        'last_test_message',
        'last_test_at',
        'created_at',
        'updated_at',
    ];

    public static function enabledList(): array
    {
        return self::query()
            ->where('status', 'enabled')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public static function deleteById(int $id): int
    {
        return self::query()->where('id', $id)->delete();
    }

    public static function markStatus(int $id, string $status): bool
    {
        return self::updateById($id, ['status' => $status]);
    }

    public static function updateTestResult(int $id, string $status, string $message): bool
    {
        return self::updateById($id, [
            'last_test_status' => $status,
            'last_test_message' => substr($message, 0, 500),
            'last_test_at' => self::now(),
        ]);
    }
}
