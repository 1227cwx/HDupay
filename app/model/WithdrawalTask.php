<?php

namespace app\model;

class WithdrawalTask extends BaseModel
{
    protected $table = 'withdrawal_tasks';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'network_code',
        'token_code',
        'wallet_account_id',
        'from_address',
        'to_address',
        'amount_int',
        'status',
        'gas_funding_tx_hash',
        'withdraw_tx_hash',
        'actual_gas_used',
        'actual_gas_price_wei',
        'actual_gas_fee_wei',
        'withdraw_block_number',
        'current_confirmations',
        'required_confirmations',
        'error_message',
        'retry_count',
        'max_retry_count',
        'last_retry_at',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'network_code',
        'token_code',
        'wallet_account_id',
        'status',
    ];

    public const PROCESSABLE_STATUSES = ['pending_withdraw', 'withdraw_failed', 'gas_funding', 'withdrawing', 'manual_required'];
    public const RETRY_STATUSES = ['withdraw_failed', 'manual_required'];
    public const CLAIMABLE_STATUSES = ['pending_withdraw', 'withdraw_failed', 'manual_required'];
    public const BLOCKING_STATUSES = ['pending_withdraw', 'gas_funding', 'withdrawing', 'processing'];

    public static function createPending(array $data): array
    {
        $data['status'] = 'pending_withdraw';
        $data['max_retry_count'] = max(0, (int)($data['max_retry_count'] ?? 3));
        return self::createRecord($data);
    }

    public static function findPending(int $limit = 20): array
    {
        return self::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function findAutoProcessable(int $limit = 20): array
    {
        return self::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->where(function ($query) {
                $query->whereNotIn('status', self::RETRY_STATUSES)
                    ->orWhere(function ($subQuery) {
                        $subQuery->whereIn('status', self::RETRY_STATUSES)
                            ->whereColumn('retry_count', '<', 'max_retry_count');
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function autoProcessableCount(): int
    {
        return (int)self::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->where(function ($query) {
                $query->whereNotIn('status', self::RETRY_STATUSES)
                    ->orWhere(function ($subQuery) {
                        $subQuery->whereIn('status', self::RETRY_STATUSES)
                            ->whereColumn('retry_count', '<', 'max_retry_count');
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

    public static function processingList(int $limit = 100): array
    {
        return self::query()
            ->where('status', 'processing')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function pendingIds(): array
    {
        return self::query()
            ->whereIn('status', self::PROCESSABLE_STATUSES)
            ->orderBy('id')
            ->pluck('id')
            ->toArray();
    }

    public static function activeCountByWalletAccount(int $walletAccountId): int
    {
        return (int)self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->whereIn('status', ['pending_withdraw', 'processing', 'gas_funding', 'withdrawing'])
            ->count();
    }

    public static function processableCountByWalletAccountToken(int $walletAccountId, string $tokenCode): int
    {
        return (int)self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->where('token_code', strtoupper($tokenCode))
            ->whereIn('status', array_merge(self::PROCESSABLE_STATUSES, ['processing']))
            ->count();
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

    public static function claimForProcessing(int $id, string $status, bool $manual): bool
    {
        if (!self::isClaimableStatus($status)) {
            return true;
        }

        $query = self::query()
            ->where('id', $id)
            ->where('status', $status);
        if (!$manual && self::shouldCountRetry($status)) {
            $query->whereColumn('retry_count', '<', 'max_retry_count');
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

    public static function hasEarlierBlockingTask(array $task): bool
    {
        $id = (int)($task['id'] ?? 0);
        $networkCode = (string)($task['network_code'] ?? '');
        if ($id <= 0 || $networkCode === '') {
            return false;
        }

        return self::query()
            ->where('network_code', $networkCode)
            ->where('id', '<', $id)
            ->where(function ($query) {
                $query->whereIn('status', self::BLOCKING_STATUSES)
                    ->orWhere(function ($subQuery) {
                        $subQuery->whereIn('status', self::RETRY_STATUSES)
                            ->whereColumn('retry_count', '<', 'max_retry_count');
                    });
            })
            ->exists();
    }

    public static function acquireNetworkLock(string $networkCode): bool
    {
        $row = self::query()
            ->getConnection()
            ->selectOne('SELECT GET_LOCK(?, 0) AS locked', [self::networkLockName($networkCode)]);
        return (int)self::dbValue($row, 'locked') === 1;
    }

    public static function releaseNetworkLock(string $networkCode): void
    {
        try {
            self::query()
                ->getConnection()
                ->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::networkLockName($networkCode)]);
        } catch (\Throwable) {
        }
    }

    private static function networkLockName(string $networkCode): string
    {
        return 'hdupay:withdrawal:' . sha1(strtolower($networkCode));
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

    public static function saveWithdrawConfirmation(
        int $id,
        int $blockNumber,
        int $currentConfirmations,
        int $requiredConfirmations,
        string $actualGasUsed,
        string $actualGasPriceWei,
        string $actualGasFeeWei
    ): bool {
        return self::updateById($id, [
            'withdraw_block_number' => $blockNumber,
            'current_confirmations' => $currentConfirmations,
            'required_confirmations' => $requiredConfirmations,
            'actual_gas_used' => (int)$actualGasUsed,
            'actual_gas_price_wei' => $actualGasPriceWei,
            'actual_gas_fee_wei' => $actualGasFeeWei,
        ]);
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
