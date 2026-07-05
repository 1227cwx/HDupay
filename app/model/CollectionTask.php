<?php

namespace app\model;

class CollectionTask extends BaseModel
{
    protected $table = 'collection_tasks';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'network_code',
        'token_code',
        'address_id',
        'from_address',
        'to_address',
        'collection_type',
        'amount_int',
        'status',
        'gas_funding_tx_hash',
        'collect_tx_hash',
        'actual_gas_used',
        'actual_gas_price_wei',
        'actual_gas_fee_wei',
        'collect_block_number',
        'current_confirmations',
        'required_confirmations',
        'error_message',
        'retry_count',
        'last_retry_at',
        'created_at',
        'updated_at',
    ];
    public const PROCESSABLE_STATUSES = ['pending_collect', 'collect_failed', 'gas_funding', 'collecting', 'manual_required'];
    public const RETRY_STATUSES = ['collect_failed', 'manual_required'];
    public const CLAIMABLE_STATUSES = ['pending_collect', 'collect_failed', 'manual_required'];
    public const BLOCKING_STATUSES = ['pending_collect', 'gas_funding', 'collecting', 'processing'];

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

    public static function createPending(array $address, string $toAddress, string $amountInt, string $collectionType = 'local', int $requiredConfirmations = 0): array
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
            'required_confirmations' => $requiredConfirmations,
            'current_confirmations' => 0,
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

    public static function isClaimableStatus(string $status): bool
    {
        return in_array($status, self::CLAIMABLE_STATUSES, true);
    }

    public static function claimForProcessing(int $id, string $status, bool $manual, int $maxRetryCount = 3): bool
    {
        if (!self::isClaimableStatus($status)) {
            return true;
        }

        $query = self::query()
            ->where('id', $id)
            ->where('status', $status);
        if (!$manual && self::shouldCountRetry($status)) {
            $query->where('retry_count', '<', max(0, $maxRetryCount));
        }

        return $query->update([
            'status' => 'processing',
            'updated_at' => self::now(),
        ]) > 0;
    }

    public static function restoreProcessingStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::PROCESSABLE_STATUSES, true)) {
            return false;
        }

        return self::query()
            ->where('id', $id)
            ->where('status', 'processing')
            ->update([
                'status' => $status,
                'updated_at' => self::now(),
            ]) > 0;
    }

    public static function hasEarlierBlockingTask(array $task, int $maxRetryCount = 3): bool
    {
        $id = (int)($task['id'] ?? 0);
        $networkCode = (string)($task['network_code'] ?? '');
        $tokenCode = strtoupper((string)($task['token_code'] ?? ''));
        if ($id <= 0 || $networkCode === '' || $tokenCode === '') {
            return false;
        }

        $maxRetryCount = max(0, $maxRetryCount);
        return self::query()
            ->where('network_code', $networkCode)
            ->where('token_code', $tokenCode)
            ->where('id', '<', $id)
            ->where(function ($query) use ($maxRetryCount) {
                $query->whereIn('status', self::BLOCKING_STATUSES)
                    ->orWhere(function ($subQuery) use ($maxRetryCount) {
                        $subQuery->whereIn('status', self::RETRY_STATUSES)
                            ->where('retry_count', '<', $maxRetryCount);
                    });
            })
            ->exists();
    }

    public static function acquireNetworkTokenLock(string $networkCode, string $tokenCode): bool
    {
        $row = self::query()
            ->getConnection()
            ->selectOne('SELECT GET_LOCK(?, 0) AS locked', [self::networkTokenLockName($networkCode, $tokenCode)]);
        return (int)self::dbValue($row, 'locked') === 1;
    }

    public static function releaseNetworkTokenLock(string $networkCode, string $tokenCode): void
    {
        try {
            self::query()
                ->getConnection()
                ->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::networkTokenLockName($networkCode, $tokenCode)]);
        } catch (\Throwable) {
        }
    }

    private static function networkTokenLockName(string $networkCode, string $tokenCode): string
    {
        return 'hdupay:collection:' . sha1(strtolower($networkCode) . ':' . strtoupper($tokenCode));
    }

    private static function dbValue(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }
        if (is_object($row)) {
            return $row->{$key} ?? null;
        }
        return null;
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
