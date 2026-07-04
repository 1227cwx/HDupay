<?php

namespace app\model;

class EasyPayOrder extends BaseModel
{
    protected $table = 'easypay_orders';
    protected $primaryKey = 'id';

    public static function findByEpayOrderNo(string $epayOrderNo): ?array
    {
        $row = self::query()->where('epay_order_no', $epayOrderNo)->first();
        return $row ? $row->toArray() : null;
    }

    public static function findByClientAndOutTradeNo(int $clientId, string $outTradeNo): ?array
    {
        $row = self::query()
            ->where('api_client_id', $clientId)
            ->where('out_trade_no', $outTradeNo)
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function findByDepositOrderNo(string $depositOrderNo): ?array
    {
        $row = self::query()->where('deposit_order_no', $depositOrderNo)->first();
        return $row ? $row->toArray() : null;
    }

    public static function latestByDepositOrderNos(array $orderNos): array
    {
        $orderNos = array_values(array_filter(array_unique(array_map('strval', $orderNos))));
        if (!$orderNos) {
            return [];
        }
        $rows = self::query()
            ->whereIn('deposit_order_no', $orderNos)
            ->orderByDesc('id')
            ->get()
            ->toArray();
        $result = [];
        foreach ($rows as $row) {
            $orderNo = (string)$row['deposit_order_no'];
            if (!isset($result[$orderNo])) {
                $result[$orderNo] = $row;
            }
        }
        return $result;
    }

    public static function attachDepositOrder(int $id, string $depositOrderNo): bool
    {
        return self::updateById($id, [
            'deposit_order_no' => $depositOrderNo,
            'status' => 'paying',
        ]);
    }

    public static function markSuccess(int $id): bool
    {
        return self::updateById($id, ['status' => 'success']);
    }

    public static function updateNotifyResult(int $id, array $data): bool
    {
        return self::updateById($id, $data);
    }
}
