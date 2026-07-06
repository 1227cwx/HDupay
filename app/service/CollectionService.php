<?php

namespace app\service;

use app\model\CollectionTask;
use app\model\DepositOrder;
use app\model\GasFundingTransaction;
use app\model\GlobalGasWallet;
use app\model\PaymentAddress;
use app\model\RpcConfig;
use app\model\SystemSetting;
use app\model\TaskQueue;
use app\model\WalletAccount;
use app\model\WalletMaster;
use RuntimeException;
use support\Log;
use Throwable;

class CollectionService
{
    private const AUTO_COLLECT_INTERVAL_KEY = 'collection.auto_collect_interval_seconds';
    private const AUTO_COLLECT_MAX_RETRY_KEY = 'collection.auto_collect_max_retry_count';

    public function list(array $filters, int $page, int $perPage): array
    {
        $result = CollectionTask::listPage($filters, $page, $perPage);
        $queues = TaskQueue::latestByBusinessIds('collection', array_column($result['items'], 'id'));
        foreach ($result['items'] as &$item) {
            $item['native_symbol'] = $this->nativeSymbol((string)$item['network_code']);
            $queue = $queues[(int)$item['id']] ?? null;
            $item['queue_id'] = $queue ? (int)$queue['id'] : null;
            $item['queue_process_status'] = $queue ? (string)$queue['process_status'] : '';
            $item['queue_is_invalid'] = $queue ? (int)$queue['is_invalid'] : null;
            $item['queue_source'] = $queue ? (string)$queue['source'] : '';
            $item['queue_last_error'] = $queue ? (string)($queue['last_error'] ?? '') : '';
        }
        unset($item);
        return $result;
    }

    public function config(): array
    {
        return [
            'auto_collect_interval_seconds' => $this->autoCollectIntervalSeconds(),
            'auto_collect_max_retry_count' => $this->autoCollectMaxRetryCount(),
        ];
    }

    public function saveConfig(array $input): array
    {
        $interval = (int)($input['auto_collect_interval_seconds'] ?? 10);
        if ($interval < 1 || $interval > 3600) {
            throw new RuntimeException('自动归集任务间隔必须在 1 到 3600 秒之间');
        }
        $maxRetryCount = (int)($input['auto_collect_max_retry_count'] ?? 3);
        if ($maxRetryCount < 0 || $maxRetryCount > 100) {
            throw new RuntimeException('自动归集最大重试次数必须在 0 到 100 次之间');
        }

        SystemSetting::saveValue(self::AUTO_COLLECT_INTERVAL_KEY, (string)$interval);
        SystemSetting::saveValue(self::AUTO_COLLECT_MAX_RETRY_KEY, (string)$maxRetryCount);
        return $this->config();
    }

    public function retry(int $id): bool
    {
        $task = CollectionTask::findById($id);
        if (!$task) {
            throw new RuntimeException('归集记录不存在');
        }
        if (!in_array((string)($task['status'] ?? ''), CollectionTask::RETRY_STATUSES, true)) {
            throw new RuntimeException('只有归集失败或需要手动处理的记录才允许重试');
        }
        (new TaskQueueService())->enqueueCollection($task, 'manual');
        return true;
    }

    public function manualCreate(int $addressId, string $amountInt = ''): array
    {
        $address = PaymentAddress::findById($addressId);
        if (!$address) {
            throw new RuntimeException('地址不存在');
        }
        $account = WalletAccount::findById((int)$address['wallet_account_id']);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }
        $activeCollection = (new WalletAssetService())->activeCollectionAddressForAccount($account);
        if (!$activeCollection || empty($activeCollection['address_lower'])) {
            throw new RuntimeException('归集地址未配置');
        }
        $task = CollectionTask::createPending(
            $address,
            (string)$activeCollection['address_lower'],
            $amountInt ?: '0',
            ($activeCollection['address_type'] ?? '') === 'third_party' ? 'exchange' : 'local',
            $this->requiredConfirmationsForAddress((int)$address['id'])
        );
        if (!TaskQueue::activeByBusiness('collection', (int)$task['id'])) {
            CollectionTask::markQueueActive((int)$task['id'], false);
        }
        (new TaskQueueService())->enqueueCollection($task, 'manual');
        return $task;
    }

    private function requiredConfirmationsForAddress(int $addressId): int
    {
        $order = DepositOrder::findLatestByAddressId($addressId);
        $requiredConfirmations = (int)($order['required_confirmations'] ?? 0);
        if ($requiredConfirmations <= 0) {
            throw new RuntimeException('来源订单缺少确认区块数，不能创建归集任务');
        }
        return $requiredConfirmations;
    }

    public function processPending(int $limit = 10): array
    {
        return (new TaskQueueService())->enqueueCollectionPending($limit, 'auto');
    }

    public function processAll(): array
    {
        return (new TaskQueueService())->enqueueCollectionPending(0, 'manual');
    }

    public function processSingle(int $id): array
    {
        $task = CollectionTask::findById($id);
        if (!$task) {
            throw new RuntimeException('Collection task does not exist');
        }
        if (!in_array((string)($task['status'] ?? ''), CollectionTask::RETRY_STATUSES, true)) {
            throw new RuntimeException('Only failed or manual-required collection tasks can be queued manually');
        }
        return (new TaskQueueService())->enqueueCollection($task, 'manual');
    }

    public function executeQueuedTask(int $id, bool $manual = false): array
    {
        $task = CollectionTask::findById($id);
        if (!$task) {
            throw new RuntimeException('Collection task does not exist');
        }
        return $this->processWithFailureHandling($task, false, $manual);
    }

    private function processWithFailureHandling(array $task, bool $throwOnFailure = false, bool $manual = false): array
    {
        $taskId = (int)($task['id'] ?? 0);
        $originalStatus = (string)($task['status'] ?? '');
        $networkCode = (string)($task['network_code'] ?? '');
        $maxRetryCount = $this->autoCollectMaxRetryCount();
        $lockAcquired = CollectionTask::acquireNetworkLock($networkCode);
        if (!$lockAcquired) {
            if ($throwOnFailure) {
                throw new RuntimeException('同网络归集任务正在处理，请稍后再试');
            }
            return [
                'task_id' => $taskId,
                'ok' => true,
                'skipped' => true,
                'reason' => 'network_locked',
            ];
        }

        $claimed = false;
        try {
            if (CollectionTask::hasEarlierBlockingTask($task, $maxRetryCount)) {
                if ($throwOnFailure) {
                    throw new RuntimeException('同网络已有更早的归集任务未完成，请稍后再试');
                }
                return [
                    'task_id' => $taskId,
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'network_busy',
                ];
            }

            if (CollectionTask::isClaimableStatus($originalStatus)) {
                $claimed = CollectionTask::claimForProcessing($taskId, $originalStatus, $manual, $maxRetryCount);
                if (!$claimed) {
                    if ($throwOnFailure) {
                        throw new RuntimeException('归集任务已被其他进程处理，请刷新后重试');
                    }
                    return [
                        'task_id' => $taskId,
                        'ok' => true,
                        'skipped' => true,
                        'reason' => 'claim_failed',
                    ];
                }
                if (CollectionTask::shouldCountRetry($originalStatus)) {
                    CollectionTask::markRetryAttempt($taskId);
                }
            }

            try {
                $result = $this->processOne($task);
                if ($claimed) {
                    CollectionTask::restoreProcessingStatus($taskId, $originalStatus);
                }
                return $result;
            } catch (Throwable $e) {
                CollectionTask::mark($taskId, 'collect_failed', ['error_message' => $e->getMessage()]);
                if ($throwOnFailure) {
                    throw $e;
                }
                return ['task_id' => $taskId, 'ok' => false, 'error' => $e->getMessage()];
            }
        } finally {
            CollectionTask::releaseNetworkLock($networkCode);
        }
    }

    private function processOne(array $task): array
    {
        $networkCode = $task['network_code'];
        $rpc = new EvmRpcService();
        if ($task['status'] === 'gas_funding') {
            return $this->checkGasFunding($task, $rpc);
        }
        if ($task['status'] === 'collecting') {
            return $this->checkCollecting($task, $rpc);
        }
        if (!empty($task['collect_tx_hash'])) {
            CollectionTask::mark((int)$task['id'], 'collecting');
            $task['status'] = 'collecting';
            return $this->checkCollecting($task, $rpc);
        }
        if (!empty($task['gas_funding_tx_hash'])) {
            CollectionTask::mark((int)$task['id'], 'gas_funding');
            $task['status'] = 'gas_funding';
            return $this->checkGasFunding($task, $rpc);
        }
        $cfg = $rpc->runtimeConfig($networkCode);
        $tokenCode = strtoupper((string)($task['token_code'] ?? 'USDC'));
        $token = $rpc->tokenConfig($networkCode, $tokenCode);
        $contractAddress = (string)$token['contract_address'];
        $address = PaymentAddress::findById((int)$task['address_id']);
        if (!$address) {
            throw new RuntimeException('归集任务对应的地址不存在');
        }
        if (strtoupper((string)($address['token_code'] ?? '')) !== $tokenCode) {
            throw new RuntimeException('归集任务代币和收款地址代币不一致');
        }
        $account = WalletAccount::findById((int)$address['wallet_account_id']);
        if (!$account) {
            throw new RuntimeException('钱包账号不存在');
        }
        $targetAddress = (string)($task['to_address'] ?? '');
        if ($targetAddress === '') {
            throw new RuntimeException('归集目标地址未配置');
        }
        $balance = $rpc->tokenBalanceOf($networkCode, $contractAddress, $task['from_address']);
        if ($balance === '0') {
            CollectionTask::mark((int)$task['id'], 'collected', ['amount_int' => '0']);
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'collected', 'note' => '余额为 0，无需归集'];
        }
        $amount = $task['amount_int'] !== '0' && (new TokenAmountService())->compare($task['amount_int'], $balance) <= 0 ? $task['amount_int'] : $balance;
        $requiredConfirmations = (int)($task['required_confirmations'] ?? 0);
        if ($requiredConfirmations <= 0) {
            throw new RuntimeException('归集任务缺少订单确认区块数');
        }
        $data = (new TransactionSignerService())->erc20TransferData($targetAddress, $amount);
        $gasPrice = $rpc->gasPrice($networkCode);
        $gasLimit = $this->erc20TransferGasLimit();
        $from = $task['from_address'];
        $neededWei = gmp_strval(gmp_mul(gmp_init($gasLimit, 10), gmp_init($gasPrice, 10)), 10);
        $nativeBalance = $rpc->getBalance($networkCode, $from);
        if (gmp_cmp(gmp_init($nativeBalance, 10), gmp_init($neededWei, 10)) < 0) {
            $fundingTxHash = $this->fundGasIfPossible($task, $neededWei, $nativeBalance);
            CollectionTask::mark((int)$task['id'], 'gas_funding', [
                'error_message' => '收款子地址 ' . $this->nativeSymbol($networkCode) . ' 余额不足，已从 Gas 钱包补充手续费，等待 Gas 补充交易确认：' . $fundingTxHash,
            ]);
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'gas_funding'];
        }

        $privateKey = (new EvmWalletService())->privateKeyForAddress($address);
        $nonce = $rpc->getTransactionCount($networkCode, $from);
        $raw = (new TransactionSignerService())->signLegacy([
            'nonce' => (new EvmHexService())->decimalToQuantity($nonce),
            'from' => $from,
            'to' => $contractAddress,
            'gas' => (new EvmHexService())->decimalToQuantity($gasLimit),
            'gasPrice' => (new EvmHexService())->decimalToQuantity($gasPrice),
            'value' => '0x0',
            'data' => $data,
            'chainId' => (int)$cfg['chain_id'],
        ], $privateKey);
        $txHash = $rpc->sendRawTransaction($networkCode, $raw);
        CollectionTask::mark((int)$task['id'], 'collecting', [
            'collect_tx_hash' => strtolower($txHash),
            'amount_int' => $amount,
            'required_confirmations' => $requiredConfirmations,
            'current_confirmations' => 0,
            'error_message' => '',
        ]);
        return ['task_id' => $task['id'], 'ok' => true, 'status' => 'collecting', 'tx_hash' => $txHash];
    }


    private function checkGasFunding(array $task, EvmRpcService $rpc): array
    {
        if (empty($task['gas_funding_tx_hash'])) {
            CollectionTask::mark((int)$task['id'], 'pending_collect', ['gas_funding_tx_hash' => '']);
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'pending_collect'];
        }
        $txHash = (string)$task['gas_funding_tx_hash'];
        $receipt = $rpc->getTransactionReceipt($task['network_code'], $txHash);
        if (!$receipt) {
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'gas_funding', 'note' => '等待交易回执'];
        }

        $status = strtolower((string)($receipt['status'] ?? '0x0'));
        if ($status !== '0x1') {
            $message = 'Gas 补充交易失败，交易哈希：' . $txHash;
            GasFundingTransaction::markFailedByTxHash($txHash, $message);
            CollectionTask::mark((int)$task['id'], 'manual_required', [
                'gas_funding_tx_hash' => '',
                'error_message' => $message,
            ]);
            return ['task_id' => $task['id'], 'ok' => false, 'status' => 'manual_required'];
        }

        $hex = new EvmHexService();
        $requiredConfirmations = $this->gasFundingRequiredConfirmations($task, $rpc);
        $blockNumber = $hex->quantityToInt((string)($receipt['blockNumber'] ?? '0x0'));
        $latest = $rpc->getBlockNumber($task['network_code']);
        $currentConfirmations = $this->currentConfirmations($latest, $blockNumber, $requiredConfirmations);
        if ($currentConfirmations < $requiredConfirmations) {
            GasFundingTransaction::markConfirmingByTxHash($txHash, $blockNumber, $currentConfirmations, $requiredConfirmations);
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'gas_funding', 'confirmations' => $currentConfirmations . '/' . $requiredConfirmations];
        }

        GasFundingTransaction::markSuccessByTxHash($txHash, $blockNumber, $currentConfirmations, $requiredConfirmations);
        CollectionTask::mark((int)$task['id'], 'pending_collect', [
            'gas_funding_tx_hash' => '',
            'error_message' => '',
        ]);
        return ['task_id' => $task['id'], 'ok' => true, 'status' => 'pending_collect'];
    }

    private function gasFundingRequiredConfirmations(array $task, EvmRpcService $rpc): int
    {
        $requiredConfirmations = (int)($task['required_confirmations'] ?? 0);
        if ($requiredConfirmations > 0) {
            return $requiredConfirmations;
        }
        $cfg = $rpc->runtimeConfig((string)$task['network_code']);
        return max(1, (int)($cfg['confirm_blocks'] ?? 1));
    }

    private function checkCollecting(array $task, EvmRpcService $rpc): array
    {
        if (empty($task['collect_tx_hash'])) {
            CollectionTask::mark((int)$task['id'], 'pending_collect');
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'pending_collect'];
        }
        $receipt = $rpc->getTransactionReceipt($task['network_code'], $task['collect_tx_hash']);
        if (!$receipt) {
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'collecting', 'note' => '等待交易回执'];
        }
        $hex = new EvmHexService();
        $requiredConfirmations = (int)($task['required_confirmations'] ?? 0);
        if ($requiredConfirmations <= 0) {
            throw new RuntimeException('归集任务缺少订单确认区块数');
        }
        $blockNumber = $hex->quantityToInt((string)($receipt['blockNumber'] ?? '0x0'));
        $latest = $rpc->getBlockNumber($task['network_code']);
        $currentConfirmations = $this->currentConfirmations($latest, $blockNumber, $requiredConfirmations);
        $actualGasUsed = $hex->quantityToDecimalString((string)($receipt['gasUsed'] ?? '0x0'));
        $actualGasPrice = $hex->quantityToDecimalString((string)($receipt['effectiveGasPrice'] ?? '0x0'));
        $actualGasFee = gmp_strval(gmp_mul(gmp_init($actualGasUsed, 10), gmp_init($actualGasPrice, 10)), 10);
        CollectionTask::saveCollectConfirmation(
            (int)$task['id'],
            $blockNumber,
            $currentConfirmations,
            $requiredConfirmations,
            $actualGasUsed,
            $actualGasPrice,
            $actualGasFee
        );

        if (strtolower((string)($receipt['status'] ?? '0x0')) !== '0x1') {
            CollectionTask::mark((int)$task['id'], 'collect_failed', [
                'collect_tx_hash' => '',
                'error_message' => '链上归集交易执行失败，交易哈希：' . $task['collect_tx_hash'] . '，回执状态：' . (string)($receipt['status'] ?? '未知'),
            ]);
            return ['task_id' => $task['id'], 'ok' => false, 'status' => 'collect_failed'];
        }
        if ($currentConfirmations < $requiredConfirmations) {
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'collecting', 'confirmations' => $currentConfirmations . '/' . $requiredConfirmations];
        }
        if (strtolower((string)($receipt['status'] ?? '0x0')) === '0x1') {
            CollectionTask::mark((int)$task['id'], 'collected', ['error_message' => '']);
            PaymentAddress::markStatus((int)$task['address_id'], 'collected');
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'collected'];
        }
        return ['task_id' => $task['id'], 'ok' => true, 'status' => 'collecting'];
    }

    private function erc20TransferGasLimit(): string
    {
        return '100000';
    }

    private function fundGasIfPossible(array $task, string $neededWei, string $nativeBalance): string
    {
        $networkCode = (string)$task['network_code'];
        $symbol = $this->nativeSymbol($networkCode);
        $gasWallet = $this->globalGasWallet();
        $gasWalletAddress = strtolower((string)$gasWallet['address_lower']);
        if ($gasWalletAddress === '' || empty($gasWallet['encrypted_private_key'])) {
            throw new RuntimeException(
                'Deposit address ' . $symbol . ' balance is insufficient; global Gas wallet is not configured'
            );
        }
        if (!GlobalGasWallet::acquireGasWalletLock($networkCode)) {
            throw new RuntimeException('Gas wallet is processing another transaction on this network, please retry later');
        }

        try {
            $rpc = new EvmRpcService();
            $privateKey = (new CryptoService())->decrypt((string)$gasWallet['encrypted_private_key']);
            $gasPrice = $rpc->gasPrice($networkCode);
            $gas = '21000';
            $transferCost = gmp_strval(gmp_mul(gmp_init($gasPrice, 10), gmp_init($gas, 10)), 10);
            $shortage = gmp_sub(gmp_init($neededWei, 10), gmp_init($nativeBalance, 10));
            $value = gmp_strval($shortage, 10);
            $totalGasFunderCost = gmp_strval(gmp_add(gmp_init($value, 10), gmp_init($transferCost, 10)), 10);
            $gasFunderBalance = $rpc->getBalance($networkCode, $gasWalletAddress);
            if (gmp_cmp(gmp_init($gasFunderBalance, 10), gmp_init($totalGasFunderCost, 10)) < 0) {
                throw new RuntimeException(
                    'Gas wallet balance is insufficient, current '
                    . $this->formatWeiToEth($gasFunderBalance)
                    . ' ' . $symbol . ', needs about '
                    . $this->formatWeiToEth($totalGasFunderCost)
                    . ' ' . $symbol
                );
            }
            $nonce = $rpc->getTransactionCount($networkCode, $gasWalletAddress);
            $cfg = $rpc->runtimeConfig($networkCode);
            $requiredConfirmations = (int)($task['required_confirmations'] ?? 0);
            if ($requiredConfirmations <= 0) {
                $requiredConfirmations = max(1, (int)($cfg['confirm_blocks'] ?? 1));
            }
            $raw = (new TransactionSignerService())->signLegacy([
                'nonce' => (new EvmHexService())->decimalToQuantity($nonce),
                'from' => $gasWalletAddress,
                'to' => $task['from_address'],
                'gas' => (new EvmHexService())->decimalToQuantity($gas),
                'gasPrice' => (new EvmHexService())->decimalToQuantity($gasPrice),
                'value' => (new EvmHexService())->decimalToQuantity($value),
                'data' => '0x',
                'chainId' => (int)$cfg['chain_id'],
            ], $privateKey);
            $txHash = $rpc->sendRawTransaction($networkCode, $raw);
            GasFundingTransaction::createRecord([
                'network_code' => $networkCode,
                'business_type' => 'collection',
                'business_id' => (int)$task['id'],
                'from_address' => $gasWalletAddress,
                'to_address' => strtolower($task['from_address']),
                'amount_wei' => $value,
                'tx_hash' => strtolower($txHash),
                'status' => 'sent',
                'required_confirmations' => $requiredConfirmations,
                'current_confirmations' => 0,
                'error_message' => '',
            ]);
            CollectionTask::mark((int)$task['id'], 'gas_funding', ['gas_funding_tx_hash' => strtolower($txHash)]);
            return strtolower($txHash);
        } finally {
            GlobalGasWallet::releaseGasWalletLock($networkCode);
        }
    }

    private function globalGasWallet(): array
    {
        $master = WalletMaster::latestActive();
        if (!$master) {
            throw new RuntimeException('Root wallet is not initialized');
        }
        $wallet = GlobalGasWallet::findByMasterId((int)$master['id']);
        if (!$wallet) {
            throw new RuntimeException('Global Gas wallet does not exist');
        }
        return $wallet;
    }

    private function recoverStaleProcessingTasks(): void
    {
        $maxRetryCount = $this->autoCollectMaxRetryCount();
        foreach (CollectionTask::processingList() as $task) {
            $updatedAt = strtotime((string)($task['updated_at'] ?? ''));
            if (!$updatedAt) {
                continue;
            }
            $address = PaymentAddress::findById((int)($task['address_id'] ?? 0));
            $account = $address ? WalletAccount::findById((int)($address['wallet_account_id'] ?? 0)) : null;
            $timeoutMinutes = min(1440, max(1, (int)($account['deposit_timeout_minutes'] ?? 10)));
            if (time() - $updatedAt < $timeoutMinutes * 60) {
                continue;
            }

            if (!empty($task['collect_tx_hash'])) {
                CollectionTask::mark((int)$task['id'], 'collecting', ['error_message' => '']);
                continue;
            }
            if (!empty($task['gas_funding_tx_hash'])) {
                CollectionTask::mark((int)$task['id'], 'gas_funding', ['error_message' => '']);
                continue;
            }

            CollectionTask::mark((int)$task['id'], 'manual_required', [
                'retry_count' => $maxRetryCount,
                'error_message' => '归集任务处理超时，未检测到链上交易哈希，请手动确认后重试',
            ]);
        }
    }

    private function formatWeiToEth(string $wei): string
    {
        if (!ctype_digit($wei) || $wei === '0') {
            return '0';
        }
        $padded = str_pad($wei, 19, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -18) ?: '0';
        $decimal = rtrim(substr($padded, -18), '0');
        if ($decimal === '') {
            return $whole;
        }
        return $whole . '.' . substr($decimal, 0, 8);
    }

    private function nativeSymbol(string $networkCode): string
    {
        $symbol = config('chains.networks.' . $networkCode . '.native_symbol');
        return $symbol ? (string)$symbol : 'ETH';
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
            try {
                $result = $this->processPending(10);
                if ($result) {
                    Log::info('归集进程执行结果', $result);
                }
            } catch (Throwable $e) {
                Log::error('归集进程执行失败：' . $e->getMessage());
            }
            sleep($this->autoCollectIntervalSeconds());
        }
    }

    private function autoCollectIntervalSeconds(): int
    {
        try {
            $interval = (int)SystemSetting::getValue(self::AUTO_COLLECT_INTERVAL_KEY, '10');
            return min(3600, max(1, $interval));
        } catch (Throwable) {
            return 10;
        }
    }

    private function autoCollectMaxRetryCount(): int
    {
        try {
            $count = (int)SystemSetting::getValue(self::AUTO_COLLECT_MAX_RETRY_KEY, '3');
            return min(100, max(0, $count));
        } catch (Throwable) {
            return 3;
        }
    }
}
