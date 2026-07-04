<?php

namespace app\model;

class CollectionTask extends BaseModel
{
    protected $table = 'collection_tasks';
    protected $primaryKey = 'id';
    public const PROCESSABLE_STATUSES = ['pending_collect', 'collect_failed', 'gas_funding', 'collecting', 'manual_required'];
    public const RETRY_STATUSES = ['collect_failed', 'manual_required'];

    public static function findPending(int $limit = 20): array
    {
        return self::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function findAutoProcessable(int $limit = 20, int $maxRetryCount = 3): array
    {
        $maxRetryCount = max(0, $maxRetryCount);
        return self::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->where(function ($query) use ($maxRetryCount) {
                $query->whereNotIn('status', self::RETRY_STATUSES)
                    ->orWhere(function ($subQuery) use ($maxRetryCount) {
                        $subQuery->whereIn('status', self::RETRY_STATUSES)
                            ->where('retry_count', '<', $maxRetryCount);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function autoProcessableCount(int $maxRetryCount = 3): int
    {
        $maxRetryCount = max(0, $maxRetryCount);
        return (int)self::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->where(function ($query) use ($maxRetryCount) {
                $query->whereNotIn('status', self::RETRY_STATUSES)
                    ->orWhere(function ($subQuery) use ($maxRetryCount) {
                        $subQuery->whereIn('status', self::RETRY_STATUSES)
                            ->where('retry_count', '<', $maxRetryCount);
                    });
            })
            ->count();
    }

    public static function findPendingById(int $id): ?array
    {
        $row = self::query()
            ->where('id', $id)
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function pendingIds(): array
    {
        return self::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->orderBy('id')
            ->pluck('id')
            ->toArray();
    }

    public static function findByAddressId(int $addressId): ?array
    {
        $row = self::query()->where('address_id', $addressId)->orderByDesc('id')->first();
        return $row ? $row->toArray() : null;
    }

    public static function createPending(array $address, string $toAddress, string $amountInt, string $collectionType = 'local'): array
    {
        $exists = self::findByAddressId((int)$address['id']);
        if ($exists && !in_array($exists['status'], ['collect_failed', 'manual_required'], true)) {
            return $exists;
        }
        $collectionType = in_array($collectionType, ['local', 'exchange'], true) ? $collectionType : 'local';
        return self::createRecord([
            'network_code' => $address['network_code'],
            'token_code' => $address['token_code'],
            'address_id' => $address['id'],
            'from_address' => $address['address_lower'],
            'to_address' => strtolower($toAddress),
            'collection_type' => $collectionType,
            'amount_int' => $amountInt,
            'status' => 'pending_collect',
        ]);
    }

    public static function mark(int $id, string $status, array $extra = []): bool
    {
        $extra['status'] = $status;
        return self::updateById($id, $extra);
    }

    public static function markRetryAttempt(int $id): bool
    {
        $row = self::findById($id);
        if (!$row) {
            return false;
        }

        return self::updateById($id, [
            'retry_count' => (int)($row['retry_count'] ?? 0) + 1,
            'last_retry_at' => self::now(),
        ]);
    }

    public static function shouldCountRetry(string $status): bool
    {
        return in_array($status, self::RETRY_STATUSES, true);
    }

    public static function saveCollectConfirmation(
        int $id,
        int $blockNumber,
        int $currentConfirmations,
        int $requiredConfirmations,
        string $actualGasUsed,
        string $actualGasPriceWei,
        string $actualGasFeeWei
    ): bool {
        return self::updateById($id, [
            'collect_block_number' => $blockNumber,
            'current_confirmations' => $currentConfirmations,
            'required_confirmations' => $requiredConfirmations,
            'actual_gas_used' => (int)$actualGasUsed,
            'actual_gas_price_wei' => $actualGasPriceWei,
            'actual_gas_fee_wei' => $actualGasFeeWei,
        ]);
    }

    public static function statusStats(): array
    {
        return self::query()
            ->selectRaw('network_code, status, COUNT(*) as total')
            ->groupBy('network_code', 'status')
            ->get()
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
