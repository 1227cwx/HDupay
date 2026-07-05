<?php

namespace app\model;

class EasyPayOrder extends BaseModel
{
    protected $table = 'easypay_orders';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'api_client_id',
        'api_secret_encrypted',
        'epay_order_no',
        'out_trade_no',
        'deposit_order_no',
        'name',
        'money',
        'notify_url',
        'return_url',
        'request_params',
        'status',
        'notify_status',
        'notify_count',
        'notify_response',
        'notify_error',
        'last_notified_at',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'api_client_id',
        'epay_order_no',
        'out_trade_no',
        'deposit_order_no',
        'status',
        'notify_status',
    ];

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
        return self::query()
            ->where('id', $id)
            ->where('deposit_order_no', '')
            ->update([
                'deposit_order_no' => $depositOrderNo,
                'status' => 'paying',
                'updated_at' => self::now(),
            ]) > 0;
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
