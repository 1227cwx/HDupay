<?php

namespace app\model;

class WithdrawalTask extends BaseModel
{
    protected $table = 'withdrawal_tasks';
    protected $primaryKey = 'id';

    public const PROCESSABLE_STATUSES = ['pending_withdraw', 'withdraw_failed', 'gas_funding', 'withdrawing', 'manual_required'];
    public const RETRY_STATUSES = ['withdraw_failed', 'manual_required'];

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
            ->whereIn('status', ['pending_withdraw', 'gas_funding', 'withdrawing'])
            ->count();
    }

    public static function processableCountByWalletAccountToken(int $walletAccountId, string $tokenCode): int
    {
        return (int)self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->where('token_code', strtoupper($tokenCode))
            ->whereIn('status', self::PROCESSABLE_STATUSES)
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
