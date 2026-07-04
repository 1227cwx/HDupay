<?php

namespace app\service;

use Web3p\EthereumTx\Transaction;

class TransactionSignerService
{
    public function signLegacy(array $tx, string $privateKey): string
    {
        $previousReporting = error_reporting();
        error_reporting($previousReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            class_exists(Transaction::class);
            $transaction = new Transaction($tx);
            $signed = $transaction->sign(ltrim($privateKey, '0x'));
            return str_starts_with($signed, '0x') ? $signed : '0x' . $signed;
        } finally {
            error_reporting($previousReporting);
        }
    }

    public function erc20TransferData(string $toAddress, string $amountInt): string
    {
        $hex = new EvmHexService();
        return '0xa9059cbb' . substr($hex->addressToTopic($toAddress), 2) . $hex->uint256Hex($amountInt);
    }
}
