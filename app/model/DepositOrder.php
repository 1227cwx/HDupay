<?php

namespace app\model;

class DepositOrder extends BaseModel
{
    protected $table = 'deposit_orders';
    protected $primaryKey = 'id';

    public static function findByOrderNo(string $orderNo): ?array
    {
        $row = self::query()->where('order_no', $orderNo)->first();
        return $row ? $row->toArray() : null;
    }

    public static function listPageWithFilters(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $query = self::query();

        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->where('order_no', 'like', '%' . $keyword . '%')
                    ->orWhere('address', 'like', '%' . strtolower($keyword) . '%')
                    ->orWhere('tx_hash', 'like', '%' . strtolower($keyword) . '%');
            });
        }

        foreach (['network_code', 'status', 'source', 'token_code', 'fiat_currency'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
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

    public static function findWaitingByAddress(string $networkCode, string $address): ?array
    {
        $row = self::query()
            ->where('network_code', $networkCode)
            ->where('address', strtolower($address))
            ->whereIn('status', ['waiting', 'confirming'])
            ->orderBy('id')
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function existsLog(string $networkCode, string $txHash, int $logIndex): bool
    {
        return self::query()
            ->where('network_code', $networkCode)
            ->where('tx_hash', strtolower($txHash))
            ->where('tx_log_index', $logIndex)
            ->exists();
    }

    public static function confirmingList(string $networkCode, int $limit = 200): array
    {
        return self::query()
            ->where('network_code', $networkCode)
            ->where('status', 'confirming')
            ->where('tx_hash', '<>', '')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function confirmingCount(string $networkCode): int
    {
        return (int)self::query()
            ->where('network_code', $networkCode)
            ->where('status', 'confirming')
            ->where('tx_hash', '<>', '')
            ->count();
    }

    public static function confirmingTotal(): int
    {
        return (int)self::query()
            ->where('status', 'confirming')
            ->where('tx_hash', '<>', '')
            ->count();
    }

    public static function markDetected(int $id, array $data): bool
    {
        $data['status'] = 'confirming';
        $data['updated_at'] = self::now();
        return self::query()->where('id', $id)->where('status', 'waiting')->update($data) > 0;
    }

    public static function markFailed(int $id, array $data): bool
    {
        $data['status'] = 'failed';
        $data['updated_at'] = self::now();
        return self::query()->where('id', $id)->whereIn('status', ['waiting', 'confirming'])->update($data) > 0;
    }

    public static function updateConfirmation(int $id, int $currentConfirmations, int $requiredConfirmations, ?string $confirmedAt = null): bool
    {
        $data = [
            'current_confirmations' => $currentConfirmations,
            'required_confirmations' => $requiredConfirmations,
        ];
        if ($confirmedAt !== null) {
            $data['confirmed_at'] = $confirmedAt;
        }
        return self::updateById($id, $data);
    }

    public static function confirmOrder(string $orderNo, string $paidAmountInt): bool
    {
        return self::query()->where('order_no', $orderNo)->update([
            'status' => 'success',
            'paid_amount_int' => $paidAmountInt,
            'confirmed_at' => self::now(),
            'updated_at' => self::now(),
        ]) > 0;
    }

    public static function expiredWaitingOrders(string $networkCode = '', int $limit = 100): array
    {
        $query = self::query()
            ->where('status', 'waiting')
            ->where('expire_at', '<=', self::now())
            ->orderBy('id')
            ->limit($limit);
        if ($networkCode !== '') {
            $query->where('network_code', $networkCode);
        }
        return $query->get()->toArray();
    }

    public static function markExpired(string $orderNo): bool
    {
        return self::query()
            ->where('order_no', $orderNo)
            ->where('status', 'waiting')
            ->update([
                'status' => 'expired',
                'updated_at' => self::now(),
            ]) > 0;
    }

    public static function orderNosByAddressIds(array $addressIds): array
    {
        $addressIds = array_values(array_filter(array_map('intval', $addressIds)));
        if (!$addressIds) {
            return [];
        }
        return self::query()
            ->whereIn('address_id', $addressIds)
            ->pluck('order_no')
            ->map(fn($orderNo) => (string)$orderNo)
            ->toArray();
    }

    public static function deleteByAddressIds(array $addressIds): int
    {
        $addressIds = array_values(array_filter(array_map('intval', $addressIds)));
        if (!$addressIds) {
            return 0;
        }
        return self::query()->whereIn('address_id', $addressIds)->delete();
    }
}
