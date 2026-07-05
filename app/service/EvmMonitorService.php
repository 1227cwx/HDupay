<?php

namespace app\service;

use app\model\CollectionTask;
use app\model\DepositOrder;
use app\model\PaymentAddress;
use app\model\RpcNetworkSetting;
use app\model\WalletAccount;
use RuntimeException;
use support\Log;
use Throwable;

class EvmMonitorService
{
    private const LISTEN_REWIND_BLOCKS = 2;
    private const LOG_ADDRESS_CHUNK_SIZE = 50;

    public function runOnceAll(): array
    {
        $results = [];
        $configs = RpcNetworkSetting::enabledList();
        foreach ($configs as $config) {
            $results[$config['network_code']] = $this->runOnce($config['network_code']);
        }
        return $results;
    }

    public function runOnce(string $networkCode): array
    {
        $expired = $this->freezeExpiredOrders($networkCode);
        $rpc = new EvmRpcService();
        $cfg = $rpc->runtimeConfig($networkCode);
        if (empty($cfg['enabled'])) {
            return ['network' => $networkCode, 'skipped' => '监听未启用', 'expired_frozen' => $expired];
        }

        $waitingCount = DepositOrder::waitingCount($networkCode);
        $confirmingCount = DepositOrder::confirmingCount($networkCode);
        if ($waitingCount <= 0 && $confirmingCount <= 0) {
            return ['network' => $networkCode, 'skipped' => '当前网络没有待监听订单', 'expired_frozen' => $expired, 'confirmed' => 0, 'idle' => true];
        }

        $latest = $rpc->getBlockNumber($networkCode);
        $confirmed = $this->updateConfirmingOrders($networkCode, $latest);
        $waitingCount = DepositOrder::waitingCount($networkCode);
        if ($waitingCount <= 0) {
            RpcNetworkSetting::markMonitorAt($networkCode);
            return ['network' => $networkCode, 'skipped' => '当前网络没有待监听订单', 'expired_frozen' => $expired, 'confirmed' => $confirmed];
        }

        $tokenCodes = array_values(array_unique(array_map(
            static fn($tokenCode) => strtoupper((string)$tokenCode),
            DepositOrder::waitingTokenCodes($networkCode)
        )));
        if (!$tokenCodes) {
            RpcNetworkSetting::markMonitorAt($networkCode);
            return ['network' => $networkCode, 'skipped' => '当前网络没有待监听代币', 'expired_frozen' => $expired, 'confirmed' => $confirmed];
        }

        $summary = [
            'network' => $networkCode,
            'latest' => $latest,
            'logs' => 0,
            'matched' => 0,
            'expired_frozen' => $expired,
            'confirmed' => $confirmed,
            'tokens' => [],
        ];
        $hasTokenError = false;
        foreach ($tokenCodes as $tokenCode) {
            try {
                $tokenResult = $this->scanToken($rpc, $networkCode, $tokenCode, $latest, $cfg);
                $summary['logs'] += (int)($tokenResult['logs'] ?? 0);
                $summary['matched'] += (int)($tokenResult['matched'] ?? 0);
                $summary['tokens'][$tokenCode] = $tokenResult;
            } catch (Throwable $e) {
                $hasTokenError = true;
                $summary['tokens'][$tokenCode] = ['ok' => false, 'error' => $e->getMessage()];
                Log::error('代币监听失败', [
                    'network' => $networkCode,
                    'token' => $tokenCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($this->isScanCaughtUp($summary['tokens'], $latest)) {
            $summary['expired_frozen'] += $this->freezeExpiredOrders($networkCode);
        } else {
            $summary['cursor_lag'] = true;
        }
        if (!$hasTokenError) {
            RpcNetworkSetting::markMonitorAt($networkCode);
        }
        return $summary;
    }

    public function prepareCursorForOrder(string $networkCode, string $tokenCode): array
    {
        $rpc = new EvmRpcService();
        $cfg = $rpc->runtimeConfig($networkCode);
        if (empty($cfg['enabled'])) {
            throw new RuntimeException('当前网络未启用自动监听，不能创建收款订单');
        }

        $latest = $rpc->getBlockNumber($networkCode);
        $listenFromBlock = max(0, $latest - self::LISTEN_REWIND_BLOCKS);
        $listenScannedBlock = max(0, $listenFromBlock - 1);
        $tokenCode = strtoupper($tokenCode);
        $rpc->tokenConfig($networkCode, $tokenCode);

        return [
            'network' => $networkCode,
            'token' => $tokenCode,
            'latest' => $latest,
            'listen_from_block' => $listenFromBlock,
            'listen_scanned_block' => $listenScannedBlock,
        ];
    }

    private function scanToken(EvmRpcService $rpc, string $networkCode, string $tokenCode, int $latest, array $cfg): array
    {
        $token = $rpc->tokenConfig($networkCode, $tokenCode);
        $contractAddress = (string)$token['contract_address'];
        $orders = DepositOrder::waitingScanOrders($networkCode, $tokenCode);
        if (!$orders) {
            return ['token' => $tokenCode, 'latest' => $latest, 'scanned' => 0, 'logs' => 0, 'matched' => 0, 'note' => '未找到待监听订单'];
        }

        $nextBlocks = array_map(fn(array $order) => $this->nextScanBlock($order), $orders);
        $from = min($nextBlocks);
        if ($from > $latest) {
            return ['token' => $tokenCode, 'latest' => $latest, 'scanned' => 0, 'logs' => 0, 'matched' => 0];
        }
        $to = min($from + (int)$cfg['scan_step_blocks'] - 1, $latest);
        $scanOrders = array_values(array_filter(
            $orders,
            fn(array $order) => $this->nextScanBlock($order) <= $to
        ));
        if (!$scanOrders) {
            return ['token' => $tokenCode, 'latest' => $latest, 'scanned' => 0, 'logs' => 0, 'matched' => 0];
        }

        $decoder = new EvmLogDecoderService();
        $ordersByAddress = $this->ordersByAddress($scanOrders);
        if (!$ordersByAddress) {
            return ['token' => $tokenCode, 'latest' => $latest, 'from' => $from, 'to' => $to, 'logs' => 0, 'matched' => 0, 'note' => '待监听订单缺少收款地址'];
        }
        $matched = 0;
        $logsCount = 0;
        foreach (array_chunk(array_keys($ordersByAddress), self::LOG_ADDRESS_CHUNK_SIZE) as $addresses) {
            $filter = $decoder->filterFor($contractAddress, $from, $to, $addresses);
            $logs = $rpc->getLogs($networkCode, $filter);
            $logsCount += count($logs);
            foreach ($logs as $log) {
                $matched += $this->handleLog($rpc, $networkCode, $tokenCode, $log, $latest, $ordersByAddress) ? 1 : 0;
            }
        }
        $updated = DepositOrder::markScannedByIds(array_column($scanOrders, 'id'), $to);
        return [
            'token' => $tokenCode,
            'latest' => $latest,
            'from' => $from,
            'to' => $to,
            'logs' => $logsCount,
            'matched' => $matched,
            'orders' => count($scanOrders),
            'scan_progress_updated' => $updated,
        ];
    }

    private function nextScanBlock(array $order): int
    {
        $listenFromBlock = max(0, (int)($order['listen_from_block'] ?? 0));
        $listenScannedBlock = max(0, (int)($order['listen_scanned_block'] ?? 0));
        if ($listenScannedBlock <= 0) {
            return $listenFromBlock;
        }
        return max($listenFromBlock, $listenScannedBlock + 1);
    }

    private function ordersByAddress(array $orders): array
    {
        $result = [];
        foreach ($orders as $order) {
            $address = strtolower(trim((string)($order['address'] ?? '')));
            if ($address === '') {
                continue;
            }
            $result[$address] = $order;
        }
        return $result;
    }

    private function isScanCaughtUp(array $tokenResults, int $latest): bool
    {
        foreach ($tokenResults as $result) {
            if (($result['ok'] ?? true) === false) {
                return false;
            }
            if ((int)($result['to'] ?? $latest) < $latest) {
                return false;
            }
        }
        return true;
    }

    private function freezeExpiredOrders(string $networkCode): int
    {
        $expired = 0;
        foreach (DepositOrder::expiredWaitingOrders($networkCode, 200) as $order) {
            if (!DepositOrder::markExpired((string)$order['order_no'])) {
                continue;
            }
            if (!empty($order['address_id'])) {
                PaymentAddress::freezeExpired((int)$order['address_id']);
            }
            $expired++;
        }
        return $expired;
    }

    private function updateConfirmingOrders(string $networkCode, int $latestBlock): int
    {
        $confirmed = 0;
        foreach (DepositOrder::confirmingList($networkCode, 200) as $order) {
            $required = (int)($order['required_confirmations'] ?? 0);
            if ($required <= 0) {
                continue;
            }
            $current = $this->currentConfirmations($latestBlock, (int)$order['tx_block_number'], $required);
            if ($current < $required) {
                DepositOrder::updateConfirmation((int)$order['id'], $current, $required);
                continue;
            }

            DepositOrder::updateConfirmation((int)$order['id'], $current, $required, date('Y-m-d H:i:s'));
            $order = DepositOrder::findByOrderNo((string)$order['order_no']) ?: $order;
            $this->confirmDepositOrder($networkCode, $order);
            $confirmed++;
        }
        return $confirmed;
    }

    private function handleLog(EvmRpcService $rpc, string $networkCode, string $tokenCode, array $log, int $latestBlock, array $ordersByAddress): bool
    {
        $decoded = (new EvmLogDecoderService())->decodeTransfer($log);
        if (!$decoded || DepositOrder::existsLog($networkCode, $decoded['tx_hash'], (int)$decoded['log_index'])) {
            return false;
        }
        $order = $ordersByAddress[strtolower((string)$decoded['to_address'])] ?? null;
        if (!$order) {
            return false;
        }
        if ((int)$decoded['block_number'] < (int)($order['listen_from_block'] ?? 0)) {
            return false;
        }
        $address = !empty($order['address_id']) ? PaymentAddress::findById((int)$order['address_id']) : null;
        if (!$address) {
            return false;
        }
        if (strtoupper((string)($order['token_code'] ?? '')) !== $tokenCode || strtoupper((string)($address['token_code'] ?? '')) !== $tokenCode) {
            return false;
        }
        if (strtolower((string)($order['address'] ?? '')) !== strtolower((string)$decoded['to_address'])) {
            return false;
        }
        if (!$this->isPaidBeforeExpire($rpc, $networkCode, (int)$decoded['block_number'], $order)) {
            DepositOrder::markExpired((string)$order['order_no']);
            PaymentAddress::freezeExpired((int)$address['id']);
            return false;
        }

        $requiredConfirmations = (int)($order['required_confirmations'] ?? 0);
        if ($requiredConfirmations <= 0) {
            return false;
        }
        $currentConfirmations = $this->currentConfirmations($latestBlock, (int)$decoded['block_number'], $requiredConfirmations);
        $txData = [
            'paid_amount_int' => $decoded['amount_int'],
            'tx_hash' => strtolower((string)$decoded['tx_hash']),
            'tx_log_index' => (int)$decoded['log_index'],
            'tx_block_number' => (int)$decoded['block_number'],
            'from_address' => strtolower((string)$decoded['from_address']),
            'to_address' => strtolower((string)$decoded['to_address']),
            'required_confirmations' => $requiredConfirmations,
            'current_confirmations' => $currentConfirmations,
        ];

        if (!(new TokenAmountService())->gte($decoded['amount_int'], $order['amount_int'])) {
            DepositOrder::markFailed((int)$order['id'], $txData);
            PaymentAddress::freezeExpired((int)$address['id']);
            return true;
        }

        if (!DepositOrder::markDetected((int)$order['id'], $txData)) {
            return false;
        }

        $updatedOrder = DepositOrder::findByOrderNo((string)$order['order_no']) ?: array_merge($order, $txData);
        if ($currentConfirmations >= $requiredConfirmations) {
            $this->confirmDepositOrder($networkCode, $updatedOrder);
        } else {
            PaymentAddress::markStatus((int)$address['id'], 'paid_detected');
        }
        return true;
    }

    private function isPaidBeforeExpire(EvmRpcService $rpc, string $networkCode, int $blockNumber, array $order): bool
    {
        $expireAt = trim((string)($order['expire_at'] ?? ''));
        if ($expireAt === '') {
            return true;
        }

        $expireTimestamp = strtotime($expireAt);
        if ($expireTimestamp === false) {
            return true;
        }

        if (time() <= $expireTimestamp) {
            return true;
        }

        $blockTimestamp = $rpc->getBlockTimestamp($networkCode, $blockNumber);
        return $blockTimestamp > 0 && $blockTimestamp <= $expireTimestamp;
    }

    private function confirmDepositOrder(string $networkCode, array $order): void
    {
        if (($order['status'] ?? '') === 'success') {
            (new OpenApiService())->sendCallbackForOrder($order);
            return;
        }
        DepositOrder::confirmOrder((string)$order['order_no'], (string)$order['paid_amount_int']);
        $confirmedOrder = DepositOrder::findByOrderNo((string)$order['order_no']) ?: $order;
        $address = !empty($confirmedOrder['address_id'])
            ? PaymentAddress::findById((int)$confirmedOrder['address_id'])
            : PaymentAddress::findByAddress($networkCode, (string)$confirmedOrder['to_address']);
        if (!$address) {
            (new OpenApiService())->sendCallbackForOrder($confirmedOrder);
            return;
        }
        if ((string)($address['network_code'] ?? '') !== $networkCode || strtoupper((string)($address['token_code'] ?? '')) !== strtoupper((string)($confirmedOrder['token_code'] ?? ''))) {
            (new OpenApiService())->sendCallbackForOrder($confirmedOrder);
            return;
        }
        PaymentAddress::markStatus((int)$address['id'], 'frozen');

        $account = WalletAccount::findById((int)$address['wallet_account_id']);
        if ($account) {
            $activeCollection = (new WalletAssetService())->activeCollectionAddressForAccount($account);
        } else {
            $activeCollection = null;
        }
        if ($account && $activeCollection && !empty($activeCollection['address_lower'])) {
            CollectionTask::createPending(
                $address,
                (string)$activeCollection['address_lower'],
                (string)$confirmedOrder['paid_amount_int'],
                ($activeCollection['address_type'] ?? '') === 'third_party' ? 'exchange' : 'local',
                (int)$confirmedOrder['required_confirmations']
            );
        }
        (new OpenApiService())->sendCallbackForOrder($confirmedOrder);
    }

    private function currentConfirmations(int $latestBlock, int $txBlock, int $requiredConfirmations): int
    {
        if ($txBlock <= 0 || $latestBlock < $txBlock) {
            return 0;
        }
        return min($requiredConfirmations, $latestBlock - $txBlock + 1);
    }

    public function loop(): void
    {
        while (true) {
            $sleep = 10;
            foreach (RpcNetworkSetting::enabledList() as $cfg) {
                $sleep = min($sleep, max(2, (int)$cfg['monitor_interval_seconds']));
                try {
                    $result = $this->runOnce($cfg['network_code']);
                    if (empty($result['idle'])) {
                        Log::info('区块监听完成', $result);
                    }
                } catch (Throwable $e) {
                    Log::error('区块监听失败', [
                        'network' => $cfg['network_code'] ?? '',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            sleep($sleep);
        }
    }
}
