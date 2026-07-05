<?php

namespace app\model;

class AdminLoginAttempt extends BaseModel
{
    protected $table = 'admin_login_attempts';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'username',
        'ip',
        'failed_count',
        'locked_until',
        'last_failed_at',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'username',
        'ip',
    ];

    public static function findByUsernameIp(string $username, string $ip): ?array
    {
        $row = self::query()->where('username', $username)->where('ip', $ip)->first();
        return $row ? $row->toArray() : null;
    }

    public static function saveFailure(string $username, string $ip, int $failedCount, string $lockedUntil): array
    {
        $exists = self::findByUsernameIp($username, $ip);
        $data = [
            'username' => $username,
            'ip' => $ip,
            'failed_count' => $failedCount,
            'locked_until' => $lockedUntil !== '' ? $lockedUntil : null,
            'last_failed_at' => self::now(),
        ];
        if ($exists) {
            self::updateById((int)$exists['id'], $data);
            return self::findById((int)$exists['id']) ?: [];
        }
        return self::createRecord($data);
    }

    public static function clearByUsernameIp(string $username, string $ip): int
    {
        return self::query()->where('username', $username)->where('ip', $ip)->delete();
    }
}
