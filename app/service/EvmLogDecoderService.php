<?php

namespace app\service;

class EvmLogDecoderService
{
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function decodeTransfer(array $log): ?array
    {
        $topics = $log['topics'] ?? [];
        if (count($topics) < 3 || strtolower($topics[0]) !== self::TRANSFER_TOPIC) {
            return null;
        }
        $hex = new EvmHexService();
        return [
            'tx_hash' => strtolower($log['transactionHash'] ?? ''),
            'log_index' => $hex->quantityToInt($log['logIndex'] ?? '0x0'),
            'block_number' => $hex->quantityToInt($log['blockNumber'] ?? '0x0'),
            'from_address' => $hex->topicToAddress($topics[1]),
            'to_address' => $hex->topicToAddress($topics[2]),
            'amount_int' => $hex->hexToDecimal($log['data'] ?? '0x0'),
            'raw_log' => json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    public function filterFor(string $contract, int $fromBlock, int $toBlock, array $addresses = []): array
    {
        $hex = new EvmHexService();
        $topics = [self::TRANSFER_TOPIC];
        if ($addresses) {
            $topics[] = null;
            $topics[] = array_values(array_map(fn($address) => $hex->addressToTopic($address), $addresses));
        }
        return [
            'fromBlock' => $hex->decimalToQuantity($fromBlock),
            'toBlock' => $hex->decimalToQuantity($toBlock),
            'address' => $contract,
            'topics' => $topics,
        ];
    }
}
