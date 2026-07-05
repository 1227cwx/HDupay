<?php

namespace app\model;

class OpenApiClient extends BaseModel
{
    protected $table = 'open_api_clients';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'name',
        'api_key',
        'api_secret_hash',
        'api_secret_encrypted',
        'callback_url',
        'ip_whitelist',
        'status',
        'last_used_ip',
        'last_used_at',
        'created_at',
        'updated_at',
    ];

    public static function findByApiKey(string $apiKey): ?array
    {
        $row = self::query()->where('api_key', $apiKey)->first();
        return $row ? $row->toArray() : null;
    }

    public static function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        return self::query()->whereIn('id', $ids)->get()->toArray();
    }

    public static function searchPage(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $query = self::query();

        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->where('name', 'like', '%' . $keyword . '%')
                    ->orWhere('api_key', 'like', '%' . $keyword . '%');
            });
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')
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

    public static function markStatus(int $id, string $status): bool
    {
        return self::updateById($id, ['status' => $status]);
    }

    public static function updateLastUsed(int $id, string $ip): bool
    {
        return self::updateById($id, [
            'last_used_ip' => $ip,
            'last_used_at' => self::now(),
        ]);
    }

    public static function updateSecret(int $id, string $secretHash, string $secretEncrypted): bool
    {
        return self::updateById($id, [
            'api_secret_hash' => $secretHash,
            'api_secret_encrypted' => $secretEncrypted,
        ]);
    }

    public static function deleteById(int $id): int
    {
        return self::query()->where('id', $id)->delete();
    }
}
