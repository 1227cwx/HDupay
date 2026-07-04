<?php

namespace app\model;

class GasFundingTransaction extends BaseModel
{
    protected $table = 'gas_funding_transactions';
    protected $primaryKey = 'id';

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
