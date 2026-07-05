<?php

namespace app\model;

class GasFundingTransaction extends BaseModel
{
    protected $table = 'gas_funding_transactions';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'network_code',
        'business_type',
        'business_id',
        'from_address',
        'to_address',
        'amount_wei',
        'tx_hash',
        'status',
        'tx_block_number',
        'current_confirmations',
        'required_confirmations',
        'error_message',
        'confirmed_at',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'network_code',
        'business_type',
        'business_id',
        'from_address',
        'to_address',
        'status',
        'tx_hash',
    ];

    public static function markByTxHash(string $txHash, string $status): bool
    {
        if (!in_array($status, ['sent', 'confirming', 'success', 'failed'], true)) {
            return false;
        }

        return self::updateByTxHash($txHash, ['status' => $status]);
    }

    public static function markConfirmingByTxHash(string $txHash, int $blockNumber, int $currentConfirmations, int $requiredConfirmations): bool
    {
        return self::updateByTxHash($txHash, [
            'status' => 'confirming',
            'tx_block_number' => $blockNumber,
            'current_confirmations' => $currentConfirmations,
            'required_confirmations' => $requiredConfirmations,
            'error_message' => '',
        ]);
    }

    public static function markSuccessByTxHash(string $txHash, int $blockNumber, int $currentConfirmations, int $requiredConfirmations): bool
    {
        return self::updateByTxHash($txHash, [
            'status' => 'success',
            'tx_block_number' => $blockNumber,
            'current_confirmations' => $currentConfirmations,
            'required_confirmations' => $requiredConfirmations,
            'error_message' => '',
            'confirmed_at' => self::now(),
        ]);
    }

    public static function markFailedByTxHash(string $txHash, string $errorMessage): bool
    {
        return self::updateByTxHash($txHash, [
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    private static function updateByTxHash(string $txHash, array $data): bool
    {
        $data = self::filterWriteData($data);
        if (!$data) {
            return false;
        }
        $data['updated_at'] = self::now();
        return self::query()
            ->where('tx_hash', strtolower($txHash))
            ->update($data) > 0;
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
