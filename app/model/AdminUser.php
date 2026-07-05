<?php

namespace app\model;

class AdminUser extends BaseModel
{
    protected $table = 'admin_users';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'username',
        'password_hash',
        'nickname',
        'status',
        'last_login_at',
        'created_at',
        'updated_at',
    ];

    public static function findByUsername(string $username): ?array
    {
        $row = self::query()->where('username', $username)->first();
        return $row ? $row->toArray() : null;
    }

    public static function findByUsernameExceptId(string $username, int $id): ?array
    {
        $row = self::query()
            ->where('username', $username)
            ->where('id', '<>', $id)
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function findActiveById(int $id): ?array
    {
        $row = self::query()->where('id', $id)->where('status', 'active')->first();
        return $row ? $row->toArray() : null;
    }

    public static function markLogin(int $id): bool
    {
        return self::updateById($id, ['last_login_at' => self::now()]);
    }

    public static function updateProfile(int $id, string $username, string $nickname): bool
    {
        return self::updateById($id, [
            'username' => $username,
            'nickname' => $nickname,
        ]);
    }

    public static function updatePasswordHash(int $id, string $passwordHash): bool
    {
        return self::updateById($id, ['password_hash' => $passwordHash]);
    }
}
