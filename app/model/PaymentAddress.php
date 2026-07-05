<?php

namespace app\model;

class PaymentAddress extends BaseModel
{
    protected $table = 'payment_addresses';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'network_code',
        'token_code',
        'wallet_account_id',
        'address',
        'address_lower',
        'derivation_path',
        'address_index',
        'status',
        'assigned_order_no',
        'created_at',
        'assigned_at',
        'expired_at',
        'frozen_at',
        'updated_at',
    ];

    public static function findAvailable(string $networkCode, string $tokenCode): ?array
    {
        $row = self::query()
            ->where('network_code', $networkCode)
            ->where('token_code', $tokenCode)
            ->where('status', 'available')
            ->orderBy('id')
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function findByAddress(string $networkCode, string $address): ?array
    {
        $row = self::query()->where('network_code', $networkCode)->where('address_lower', strtolower($address))->first();
        return $row ? $row->toArray() : null;
    }

    public static function findAssignedByAddress(string $networkCode, string $address, string $tokenCode = ''): ?array
    {
        $query = self::query()
            ->where('network_code', $networkCode)
            ->where('address_lower', strtolower($address))
            ->whereIn('status', ['assigned', 'paid_detected']);
        if ($tokenCode !== '') {
            $query->where('token_code', strtoupper($tokenCode));
        }
        $row = $query->first();
        return $row ? $row->toArray() : null;
    }

    public static function assignedAddresses(string $networkCode, int $limit = 100): array
    {
        return self::query()
            ->where('network_code', $networkCode)
            ->whereIn('status', ['assigned', 'paid_detected'])
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function assignToOrder(int $id, string $orderNo): bool
    {
        return self::updateById($id, [
            'status' => 'assigned',
            'assigned_order_no' => $orderNo,
            'assigned_at' => self::now(),
        ]);
    }

    public static function markStatus(int $id, string $status): bool
    {
        $data = ['status' => $status];
        if ($status === 'frozen') {
            $data['frozen_at'] = self::now();
        }
        if ($status === 'expired') {
            $data['expired_at'] = self::now();
        }
        return self::updateById($id, $data);
    }

    public static function freezeExpired(int $id): bool
    {
        return self::query()
            ->where('id', $id)
            ->whereIn('status', ['assigned', 'paid_detected'])
            ->update([
                'status' => 'frozen',
                'expired_at' => self::now(),
                'frozen_at' => self::now(),
                'updated_at' => self::now(),
            ]) > 0;
    }

    public static function statusStats(): array
    {
        return self::query()
            ->selectRaw('network_code, status, COUNT(*) as total')
            ->groupBy('network_code', 'status')
            ->get()
            ->toArray();
    }

    public static function assignedAddressesByToken(string $networkCode, string $tokenCode, int $limit = 100): array
    {
        return self::query()
            ->where('network_code', $networkCode)
            ->where('token_code', strtoupper($tokenCode))
            ->whereIn('status', ['assigned', 'paid_detected'])
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function activeTokenCodes(string $networkCode): array
    {
        return self::query()
            ->where('network_code', $networkCode)
            ->whereIn('status', ['assigned', 'paid_detected'])
            ->groupBy('token_code')
            ->pluck('token_code')
            ->toArray();
    }

    public static function activeMonitorCount(string $networkCode): int
    {
        return (int)self::query()
            ->where('network_code', $networkCode)
            ->whereIn('status', ['assigned', 'paid_detected'])
            ->count();
    }

    public static function activeMonitorTotal(): int
    {
        return (int)self::query()
            ->whereIn('status', ['assigned', 'paid_detected'])
            ->count();
    }

    public static function idsByWalletAccountIds(array $accountIds): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) {
            return [];
        }
        return self::query()
            ->whereIn('wallet_account_id', $accountIds)
            ->pluck('id')
            ->map(fn($id) => (int)$id)
            ->toArray();
    }

    public static function addressLowersByWalletAccountIds(array $accountIds): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) {
            return [];
        }
        return self::query()
            ->whereIn('wallet_account_id', $accountIds)
            ->pluck('address_lower')
            ->map(fn($address) => strtolower((string)$address))
            ->toArray();
    }

    public static function deleteByWalletAccountIds(array $accountIds): int
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) {
            return 0;
        }
        return self::query()->whereIn('wallet_account_id', $accountIds)->delete();
    }
}
