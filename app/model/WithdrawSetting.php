<?php

namespace app\model;

class WithdrawSetting extends BaseModel
{
    protected $table = 'withdraw_settings';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'wallet_account_id',
        'network_code',
        'token_code',
        'enabled',
        'target_address',
        'min_amount_int',
        'max_retry_count',
        'last_run_at',
        'status',
        'error_message',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'wallet_account_id',
        'network_code',
        'token_code',
        'enabled',
        'status',
    ];

    public static function findByAccountToken(int $walletAccountId, string $tokenCode): ?array
    {
        $row = self::query()
            ->where('wallet_account_id', $walletAccountId)
            ->where('token_code', strtoupper($tokenCode))
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function saveForAccountToken(int $walletAccountId, string $tokenCode, array $data): array
    {
        $tokenCode = strtoupper($tokenCode);
        $exists = self::findByAccountToken($walletAccountId, $tokenCode);
        if ($exists) {
            self::updateById((int)$exists['id'], $data);
            return self::findByAccountToken($walletAccountId, $tokenCode) ?? [];
        }

        $data['wallet_account_id'] = $walletAccountId;
        $data['token_code'] = $tokenCode;
        return self::createRecord($data);
    }

    public static function allList(): array
    {
        return self::query()->orderBy('network_code')->orderBy('token_code')->get()->toArray();
    }

    public static function enabledList(): array
    {
        return self::query()
            ->where('enabled', 1)
            ->where('target_address', '<>', '')
            ->orderBy('network_code')
            ->orderBy('token_code')
            ->get()
            ->toArray();
    }

    public static function deleteByAccountIds(array $accountIds): int
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) {
            return 0;
        }
        return self::query()->whereIn('wallet_account_id', $accountIds)->delete();
    }
}
