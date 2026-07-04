<?php

namespace app\model;

class OpenApiCallbackLog extends BaseModel
{
    protected $table = 'open_api_callback_logs';
    protected $primaryKey = 'id';

    public static function findByClientAndOrder(int $clientId, string $orderNo): ?array
    {
        $row = self::query()
            ->where('client_id', $clientId)
            ->where('order_no', $orderNo)
            ->orderByDesc('id')
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function latestByOrderNos(array $orderNos): array
    {
        $orderNos = array_values(array_filter(array_unique($orderNos)));
        if (!$orderNos) {
            return [];
        }
        $rows = self::query()
            ->whereIn('order_no', $orderNos)
            ->orderByDesc('id')
            ->get()
            ->toArray();
        $result = [];
        foreach ($rows as $row) {
            $orderNo = (string)$row['order_no'];
            if (!isset($result[$orderNo])) {
                $result[$orderNo] = $row;
            }
        }
        return $result;
    }

    public static function updateResult(int $id, array $data): bool
    {
        return self::updateById($id, $data);
    }

    public static function deleteByOrderNos(array $orderNos): int
    {
        $orderNos = array_values(array_filter(array_unique(array_map('strval', $orderNos))));
        if (!$orderNos) {
            return 0;
        }
        return self::query()->whereIn('order_no', $orderNos)->delete();
    }
}
