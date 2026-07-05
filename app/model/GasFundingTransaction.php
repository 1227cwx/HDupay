<?php

namespace app\model;

class GasFundingTransaction extends BaseModel
{
    protected $table = 'gas_funding_transactions';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'network_code',
        'from_address',
        'to_address',
        'amount_wei',
        'tx_hash',
        'status',
        'created_at',
        'updated_at',
    ];

    public static function markByTxHash(string $txHash, string $status): bool
    {
        if (!in_array($status, ['sent', 'success', 'failed'], true)) {
            return false;
        }

        return self::query()
            ->where('tx_hash', strtolower($txHash))
            ->update([
                'status' => $status,
                'updated_at' => self::now(),
            ]) > 0;
    }

    public static function deleteByAddresses(array $addresses): int
    {
        $addresses = array_values(array_filter(array_unique(array_map(
            fn($address) => strtolower(trim((string)$address)),
            $addresses
        ))));
        if (!$addresses) {
            return 0;
        }
        return self::query()
            ->whereIn('from_address', $addresses)
            ->orWhereIn('to_address', $addresses)
            ->delete();
    }
}
