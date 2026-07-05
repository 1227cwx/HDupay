<?php

namespace app\service;

use app\model\GasFundingTransaction;
use app\model\SystemSetting;
use app\model\WalletAccount;
use app\model\WalletCollectionAddress;
use app\model\WithdrawalTask;
use app\model\WithdrawSetting;
use InvalidArgumentException;
use RuntimeException;
use support\Log;
use Throwable;

class WithdrawalService
{
    private const AUTO_WITHDRAW_INTERVAL_KEY = 'withdraw.auto_withdraw_interval_seconds';
    private const PAYMENT_TOKEN_CODES = ['USDC', 'USDT'];

    public function list(array $filters, int $page, int $perPage): array
    {
        $result = WithdrawalTask::listPage($filters, $page, $perPage);
        foreach ($result['items'] as &$item) {
            $item['native_symbol'] = $this->nativeSymbol((string)$item['network_code']);
        }
        return $result;
    }

    public function config(): array
    {
        return [
            'auto_withdraw_interval_seconds' => $this->autoWithdrawIntervalSeconds(),
        ];
    }

    public function saveConfig(array $input): array
    {
        $interval = (int)($input['auto_withdraw_interval_seconds'] ?? 10);
        if ($interval < 1 || $interval > 3600) {
            throw new RuntimeException('自动转出任务间隔必须在 1 到 3600 秒之间');
        }

        SystemSetting::saveValue(self::AUTO_WITHDRAW_INTERVAL_KEY, (string)$interval);
        return $this->config();
    }

    public function settings(): array
    {
        $this->ensureDefaultSettings();
        $rows = WithdrawSetting::allList();
        foreach ($rows as &$row) {
            $row['network_name'] = config('chains.networks.' . $row['network_code'] . '.name') ?: $row['network_code'];
            $row['native_symbol'] = $this->nativeSymbol((string)$row['network_code']);
            $row['min_amount'] = $this->formatTokenAmount((string)($row['min_amount_int'] ?? '0'), 6);
            $row['max_retry_count'] = (int)($row['max_retry_count'] ?? 3);
        }
        unset($row);
        return [
            'settings' => $rows,
            'config' => $this->config(),
        ];
    }

    public function saveSetting(array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        $exists = $id > 0 ? WithdrawSetting::findById($id) : null;
        $walletAccountId = $exists ? (int)$exists['wallet_account_id'] : (int)($input['wallet_account_id'] ?? 0);
        $account = $this->taskAccount($walletAccountId);
        $tokenCode = strtoupper((string)($input['token_code'] ?? ($exists['token_code'] ?? 'USDC')));
        if (!in_array($tokenCode, self::PAYMENT_TOKEN_CODES, true)) {
            throw new InvalidArgumentException('转出代币只支持 USDC 或 USDT');
        }
        $targetAddress = strtolower(trim((string)($input['target_address'] ?? ($exists['target_address'] ?? ''))));
        if ($targetAddress !== '' && !$this->isEvmAddress($targetAddress)) {
            throw new InvalidArgumentException('请输入正确的转出接收地址');
        }
        $minAmount = trim((string)($input['min_amount'] ?? '0'));
        $minAmountInt = $minAmount === '' ? '0' : $this->parseAmountDisplay($minAmount, 6);
        $maxRetryCount = (int)($input['max_retry_count'] ?? ($exists['max_retry_count'] ?? 3));
        if ($maxRetryCount < 0 || $maxRetryCount > 100) {
            throw new InvalidArgumentException('自动转出最大重试次数必须在 0 到 100 次之间');
        }
        $enabled = !empty($input['enabled']) ? 1 : 0;
        if ($enabled && $targetAddress === '') {
            throw new InvalidArgumentException('启用自动转出前必须先设置转出接收地址');
        }

        WithdrawSetting::saveForAccountToken($walletAccountId, $tokenCode, [
            'network_code' => (string)$account['network_code'],
            'enabled' => $enabled,
            'target_address' => $targetAddress,
            'min_amount_int' => $minAmountInt,
            'max_retry_count' => $maxRetryCount,
            'status' => $enabled ? 'enabled' : 'disabled',
            'error_message' => '',
        ]);

        return $this->settings();
    }

    public function preview(array $input): array
    {
        $account = $this->localAccount((int)($input['wallet_account_id'] ?? 0));
        $networkCode = (string)$account['network_code'];
        $rpc = new EvmRpcService();
        $cfg = $rpc->runtimeConfig($networkCode);
        $tokenCode = strtoupper(trim((string)($input['token_code'] ?? $input['token'] ?? 'USDC')));
        $token = $rpc->tokenConfig($networkCode, $tokenCode);
        $tokenDecimals = (int)($token['decimals'] ?? 6);
        $contractAddress = (string)$token['contract_address'];

        $fromAddress = strtolower((string)$account['collection_address']);
        $toAddress = strtolower(trim((string)($input['to_address'] ?? '')));
        $tokenBalanceInt = $rpc->tokenBalanceOf($networkCode, $contractAddress, $fromAddress);
        $nativeBalanceWei = $rpc->getBalance($networkCode, $fromAddress);
        $gasFunderBalanceWei = !empty($account['gas_funder_address'])
            ? $rpc->getBalance($networkCode, (string)$account['gas_funder_address'])
            : '0';

        $amountDisplay = trim((string)($input['amount'] ?? $input['amount_display'] ?? ''));
        $amountInt = $amountDisplay === '' ? $tokenBalanceInt : $this->parseAmountDisplay($amountDisplay, $tokenDecimals);
        if (gmp_cmp(gmp_init($amountInt, 10), gmp_init($tokenBalanceInt, 10)) > 0) {
            throw new RuntimeException('转出数量不能大于当前归集钱包 ' . $tokenCode . ' 余额');
        }

        $gasPriceWei = $rpc->gasPrice($networkCode);
        $gasLimit = $this->erc20TransferGasLimit();
        $neededWei = gmp_strval(gmp_mul(gmp_init($gasLimit, 10), gmp_init($gasPriceWei, 10)), 10);
        $shortageWei = gmp_cmp(gmp_init($nativeBalanceWei, 10), gmp_init($neededWei, 10)) >= 0
            ? '0'
            : gmp_strval(gmp_sub(gmp_init($neededWei, 10), gmp_init($nativeBalanceWei, 10)), 10);

        return [
            'network_code' => $networkCode,
            'wallet_account_id' => (int)$account['id'],
            'from_address' => $fromAddress,
            'to_address' => $toAddress,
            'to_address_valid' => $toAddress !== '' && $this->isEvmAddress($toAddress),
            'token_code' => $tokenCode,
            'token_balance_int' => $tokenBalanceInt,
            'token_balance' => $this->formatTokenAmount($tokenBalanceInt, $tokenDecimals),
            'amount_int' => $amountInt,
            'amount' => $this->formatTokenAmount($amountInt, $tokenDecimals),
            'native_symbol' => $this->nativeSymbol($networkCode),
            'native_balance_wei' => $nativeBalanceWei,
            'native_balance' => $this->formatTokenAmount($nativeBalanceWei, 18),
            'gas_funder_address' => strtolower((string)($account['gas_funder_address'] ?? '')),
            'gas_funder_balance_wei' => $gasFunderBalanceWei,
            'gas_funder_balance' => $this->formatTokenAmount($gasFunderBalanceWei, 18),
            'gas_price_wei' => $gasPriceWei,
            'gas_limit' => $gasLimit,
            'needed_native_wei' => $neededWei,
            'needed_native' => $this->formatTokenAmount($neededWei, 18),
            'shortage_native_wei' => $shortageWei,
            'shortage_native' => $this->formatTokenAmount($shortageWei, 18),
        ];
    }

    public function create(array $input): array
    {
        $preview = $this->preview($input);
        if (!$preview['to_address_valid']) {
            throw new InvalidArgumentException('请输入正确的接收地址');
        }
        if ($preview['to_address'] === $preview['from_address']) {
            throw new InvalidArgumentException('接收地址不能和归集钱包地址相同');
        }
        if ($preview['amount_int'] === '0') {
            throw new InvalidArgumentException('转出数量必须大于 0');
        }

        if (WithdrawalTask::activeCountByWalletAccount((int)$preview['wallet_account_id']) > 0) {
            throw new RuntimeException('当前网络已有未完成的转出任务，请等待完成后再创建新的转出任务');
        }


        $requiredConfirmations = (int)((new EvmRpcService())->runtimeConfig((string)$preview['network_code'])['confirm_blocks'] ?? 0);
        return WithdrawalTask::createPending([
            'network_code' => $preview['network_code'],
            'token_code' => $preview['token_code'],
            'wallet_account_id' => $preview['wallet_account_id'],
            'from_address' => $preview['from_address'],
            'to_address' => $preview['to_address'],
            'amount_int' => $preview['amount_int'],
            'max_retry_count' => $this->maxRetryCountForAccountToken((int)$preview['wallet_account_id'], (string)$preview['token_code']),
            'required_confirmations' => $requiredConfirmations,
            'current_confirmations' => 0,
            'error_message' => '',
        ]);
    }

    public function processPending(int $limit = 10): array
    {
        return $this->processTasks(WithdrawalTask::findAutoProcessable($limit));
    }

    public function processAll(): array
    {
        $results = [];
        foreach (WithdrawalTask::pendingIds() as $id) {
            $task = WithdrawalTask::findPendingById((int)$id);
            if (!$task) {
                continue;
            }
            $results[] = $this->processWithFailureHandling($task, false, true);
        }
        return $results;
    }

    public function processSingle(int $id): array
    {
        $task = WithdrawalTask::findById($id);
        if (!$task) {
            throw new RuntimeException('转出记录不存在');
        }
        if (($task['status'] ?? '') !== 'withdraw_failed') {
            throw new RuntimeException('只有转出失败的记录才允许手动重新转出');
        }
        return $this->processWithFailureHandling($task, true, true);
    }

    private function processTasks(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $task) {
            $results[] = $this->processWithFailureHandling($task);
        }
        return $results;
    }

    private function processWithFailureHandling(array $task, bool $throwOnFailure = false, bool $manual = false): array
    {
        $taskId = (int)($task['id'] ?? 0);
        $originalStatus = (string)($task['status'] ?? '');
        $networkCode = (string)($task['network_code'] ?? '');
        $tokenCode = strtoupper((string)($task['token_code'] ?? ''));
        $lockAcquired = WithdrawalTask::acquireNetworkTokenLock($networkCode, $tokenCode);
        if (!$lockAcquired) {
            if ($throwOnFailure) {
                throw new RuntimeException('同网络同代币转出任务正在处理，请稍后再试');
            }
            return [
                'task_id' => $taskId,
                'ok' => true,
                'skipped' => true,
                'reason' => 'network_token_locked',
            ];
        }

        $claimed = false;
        try {
            if (WithdrawalTask::hasEarlierBlockingTask($task)) {
                if ($throwOnFailure) {
                    throw new RuntimeException('同网络同代币已有更早的转出任务未完成，请稍后再试');
                }
                return [
                    'task_id' => $taskId,
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'network_token_busy',
                ];
            }

            if (WithdrawalTask::isClaimableStatus($originalStatus)) {
                $claimed = WithdrawalTask::claimForProcessing($taskId, $originalStatus, $manual);
                if (!$claimed) {
                    if ($throwOnFailure) {
                        throw new RuntimeException('转出任务已被其他进程处理，请刷新后重试');
                    }
                    return [
                        'task_id' => $taskId,
                        'ok' => true,
                        'skipped' => true,
                        'reason' => 'claim_failed',
                    ];
                }
                if (WithdrawalTask::shouldCountRetry($originalStatus)) {
                    WithdrawalTask::markRetryAttempt($taskId);
                }
            }

            try {
                $result = $this->processOne($task);
                if ($claimed) {
                    WithdrawalTask::restoreProcessingStatus($taskId, $originalStatus);
                }
                return $result;
            } catch (Throwable $e) {
                WithdrawalTask::mark($taskId, 'withdraw_failed', ['error_message' => $e->getMessage()]);
                if ($throwOnFailure) {
                    throw $e;
                }
                return ['task_id' => $taskId, 'ok' => false, 'error' => $e->getMessage()];
            }
        } finally {
            WithdrawalTask::releaseNetworkTokenLock($networkCode, $tokenCode);
        }
    }

    private function processOne(array $task): array
    {
        $rpc = new EvmRpcService();
        if ($task['status'] === 'gas_funding') {
            return $this->checkGasFunding($task, $rpc);
        }
        if ($task['status'] === 'withdrawing') {
            return $this->checkWithdrawing($task, $rpc);
        }
        if (!empty($task['withdraw_tx_hash'])) {
            WithdrawalTask::mark((int)$task['id'], 'withdrawing');
            $task['status'] = 'withdrawing';
            return $this->checkWithdrawing($task, $rpc);
        }
        if (!empty($task['gas_funding_tx_hash'])) {
            WithdrawalTask::mark((int)$task['id'], 'gas_funding');
            $task['status'] = 'gas_funding';
            return $this->checkGasFunding($task, $rpc);
        }

        $account = $this->taskAccount((int)$task['wallet_account_id']);
        $networkCode = (string)$task['network_code'];
        $cfg = $rpc->runtimeConfig($networkCode);
        $tokenCode = strtoupper((string)($task['token_code'] ?? 'USDC'));
        $token = $rpc->tokenConfig($networkCode, $tokenCode);
        $contractAddress = (string)$token['contract_address'];
        $tokenDecimals = (int)($token['decimals'] ?? 6);
        $from = strtolower((string)$task['from_address']);
        $amount = (string)$task['amount_int'];
        $balance = $rpc->tokenBalanceOf($networkCode, $contractAddress, $from);
        if (gmp_cmp(gmp_init($balance, 10), gmp_init($amount, 10)) < 0) {
            throw new RuntimeException('归集钱包 ' . $tokenCode . ' 余额不足，当前余额 ' . $this->formatTokenAmount($balance, $tokenDecimals) . ' ' . $tokenCode);
        }

        $gasPrice = $rpc->gasPrice($networkCode);
        $gasLimit = $this->erc20TransferGasLimit();
        $neededWei = gmp_strval(gmp_mul(gmp_init($gasLimit, 10), gmp_init($gasPrice, 10)), 10);
        $nativeBalance = $rpc->getBalance($networkCode, $from);
        if (gmp_cmp(gmp_init($nativeBalance, 10), gmp_init($neededWei, 10)) < 0) {
            $fundingTxHash = $this->fundGasIfPossible($task, $account, $neededWei, $nativeBalance);
            WithdrawalTask::mark((int)$task['id'], 'gas_funding', [
                'error_message' => '归集钱包 ' . $this->nativeSymbol($networkCode) . ' 余额不足，已从 Gas 钱包补充手续费，等待 Gas 补充交易确认：' . $fundingTxHash,
            ]);
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'gas_funding'];
        }

        $privateKey = (new EvmWalletService())->privateKeyForWalletAccountPath($account, (string)$account['collection_derivation_path']);
        $nonce = $rpc->getTransactionCount($networkCode, $from);
        $raw = (new TransactionSignerService())->signLegacy([
            'nonce' => (new EvmHexService())->decimalToQuantity($nonce),
            'from' => $from,
            'to' => $contractAddress,
            'gas' => (new EvmHexService())->decimalToQuantity($gasLimit),
            'gasPrice' => (new EvmHexService())->decimalToQuantity($gasPrice),
            'value' => '0x0',
            'data' => (new TransactionSignerService())->erc20TransferData((string)$task['to_address'], $amount),
            'chainId' => (int)$cfg['chain_id'],
        ], $privateKey);
        $txHash = $rpc->sendRawTransaction($networkCode, $raw);
        WithdrawalTask::mark((int)$task['id'], 'withdrawing', [
            'withdraw_tx_hash' => strtolower($txHash),
            'required_confirmations' => (int)$cfg['confirm_blocks'],
            'current_confirmations' => 0,
            'error_message' => '',
        ]);
        return ['task_id' => $task['id'], 'ok' => true, 'status' => 'withdrawing', 'tx_hash' => $txHash];
    }

    private function checkGasFunding(array $task, EvmRpcService $rpc): array
    {
        if (empty($task['gas_funding_tx_hash'])) {
            WithdrawalTask::mark((int)$task['id'], 'pending_withdraw');
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'pending_withdraw'];
        }
        $receipt = $rpc->getTransactionReceipt($task['network_code'], $task['gas_funding_tx_hash']);
        if (!$receipt) {
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'gas_funding', 'note' => '等待交易回执'];
        }
        if (strtolower((string)($receipt['status'] ?? '0x0')) === '0x1') {
            WithdrawalTask::mark((int)$task['id'], 'pending_withdraw', ['error_message' => '']);
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'pending_withdraw'];
        }
        WithdrawalTask::mark((int)$task['id'], 'manual_required', [
            'error_message' => 'Gas 补充交易失败，交易哈希：' . $task['gas_funding_tx_hash'],
        ]);
        return ['task_id' => $task['id'], 'ok' => false, 'status' => 'manual_required'];
    }

    private function checkWithdrawing(array $task, EvmRpcService $rpc): array
    {
        if (empty($task['withdraw_tx_hash'])) {
            WithdrawalTask::mark((int)$task['id'], 'pending_withdraw');
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'pending_withdraw'];
        }
        $receipt = $rpc->getTransactionReceipt($task['network_code'], $task['withdraw_tx_hash']);
        if (!$receipt) {
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'withdrawing', 'note' => '等待交易回执'];
        }

        $hex = new EvmHexService();
        $cfg = $rpc->runtimeConfig($task['network_code']);
        $requiredConfirmations = (int)($task['required_confirmations'] ?? 0);
        if ($requiredConfirmations <= 0) {
            $requiredConfirmations = (int)$cfg['confirm_blocks'];
        }
        $blockNumber = $hex->quantityToInt((string)($receipt['blockNumber'] ?? '0x0'));
        $latest = $rpc->getBlockNumber($task['network_code']);
        $currentConfirmations = $this->currentConfirmations($latest, $blockNumber, $requiredConfirmations);
        $actualGasUsed = $hex->quantityToDecimalString((string)($receipt['gasUsed'] ?? '0x0'));
        $actualGasPrice = $hex->quantityToDecimalString((string)($receipt['effectiveGasPrice'] ?? '0x0'));
        $actualGasFee = gmp_strval(gmp_mul(gmp_init($actualGasUsed, 10), gmp_init($actualGasPrice, 10)), 10);
        WithdrawalTask::saveWithdrawConfirmation(
            (int)$task['id'],
            $blockNumber,
            $currentConfirmations,
            $requiredConfirmations,
            $actualGasUsed,
            $actualGasPrice,
            $actualGasFee
        );

        if (strtolower((string)($receipt['status'] ?? '0x0')) !== '0x1') {
            WithdrawalTask::mark((int)$task['id'], 'withdraw_failed', [
                'error_message' => '链上转出交易执行失败，交易哈希：' . $task['withdraw_tx_hash'] . '，回执状态：' . (string)($receipt['status'] ?? '未知'),
            ]);
            return ['task_id' => $task['id'], 'ok' => false, 'status' => 'withdraw_failed'];
        }
        if ($currentConfirmations < $requiredConfirmations) {
            return ['task_id' => $task['id'], 'ok' => true, 'status' => 'withdrawing', 'confirmations' => $currentConfirmations . '/' . $requiredConfirmations];
        }
        WithdrawalTask::mark((int)$task['id'], 'withdrawn', ['error_message' => '']);
        return ['task_id' => $task['id'], 'ok' => true, 'status' => 'withdrawn'];
    }

    private function fundGasIfPossible(array $task, array $account, string $neededWei, string $nativeBalance): string
    {
        $networkCode = (string)$task['network_code'];
        $symbol = $this->nativeSymbol($networkCode);
        if (empty($account['encrypted_gas_funder_private_key']) || empty($account['gas_funder_address'])) {
            throw new RuntimeException(
                '归集钱包 ' . $symbol . ' 余额不足，当前余额 '
                . $this->formatTokenAmount($nativeBalance, 18)
                . ' ' . $symbol . '，需要至少 '
                . $this->formatTokenAmount($neededWei, 18)
                . ' ' . $symbol . '；同时 Gas 钱包或 Gas 私钥未配置，无法自动补充手续费'
            );
        }

        $rpc = new EvmRpcService();
        $privateKey = (new CryptoService())->decrypt($account['encrypted_gas_funder_private_key']);
        $gasPrice = $rpc->gasPrice($networkCode);
        $gas = '21000';
        $transferCost = gmp_strval(gmp_mul(gmp_init($gasPrice, 10), gmp_init($gas, 10)), 10);
        $shortage = gmp_sub(gmp_init($neededWei, 10), gmp_init($nativeBalance, 10));
        $value = gmp_strval(gmp_add($shortage, gmp_init($transferCost, 10)), 10);
        $totalGasFunderCost = gmp_strval(gmp_add(gmp_init($value, 10), gmp_init($transferCost, 10)), 10);
        $gasFunderBalance = $rpc->getBalance($networkCode, $account['gas_funder_address']);
        if (gmp_cmp(gmp_init($gasFunderBalance, 10), gmp_init($totalGasFunderCost, 10)) < 0) {
            throw new RuntimeException(
                'Gas 钱包余额不足，当前余额 '
                . $this->formatTokenAmount($gasFunderBalance, 18)
                . ' ' . $symbol . '；归集钱包当前余额 '
                . $this->formatTokenAmount($nativeBalance, 18)
                . ' ' . $symbol . '，本次转出需要至少 '
                . $this->formatTokenAmount($neededWei, 18)
                . ' ' . $symbol . '，需要向归集钱包补充约 '
                . $this->formatTokenAmount($value, 18)
                . ' ' . $symbol . '，并支付 Gas 钱包转账手续费约 '
                . $this->formatTokenAmount($transferCost, 18)
                . ' ' . $symbol . '，合计需要约 '
                . $this->formatTokenAmount($totalGasFunderCost, 18)
                . ' ' . $symbol
            );
        }

        $nonce = $rpc->getTransactionCount($networkCode, $account['gas_funder_address']);
        $cfg = $rpc->runtimeConfig($networkCode);
        $raw = (new TransactionSignerService())->signLegacy([
            'nonce' => (new EvmHexService())->decimalToQuantity($nonce),
            'from' => $account['gas_funder_address'],
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
            'from_address' => strtolower($account['gas_funder_address']),
            'to_address' => strtolower($task['from_address']),
            'amount_wei' => $value,
            'tx_hash' => strtolower($txHash),
            'status' => 'sent',
        ]);
        WithdrawalTask::mark((int)$task['id'], 'gas_funding', ['gas_funding_tx_hash' => strtolower($txHash)]);
        return strtolower($txHash);
    }

    private function localAccount(int $walletAccountId): array
    {
        $account = $this->taskAccount($walletAccountId);
        if (($account['status'] ?? '') !== 'active') {
            throw new RuntimeException('网络账户已停用，请先启用后再创建转出任务');
        }
        $active = (new WalletAssetService())->activeCollectionAddressForAccount($account);
        if (!$active || ($active['address_type'] ?? '') !== 'system') {
            throw new RuntimeException('当前归集目标不是本地根钱包派生地址，不能创建本地转出任务');
        }
        $account['collection_address'] = strtolower((string)$active['address_lower']);
        return $account;
    }

    private function taskAccount(int $walletAccountId): array
    {
        if ($walletAccountId <= 0) {
            throw new InvalidArgumentException('网络账户 ID 无效');
        }
        $account = WalletAccount::findById($walletAccountId);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }
        if (empty($account['collection_address']) || empty($account['collection_derivation_path'])) {
            throw new RuntimeException('本地归集钱包地址未配置');
        }
        return $account;
    }

    private function ensureDefaultSettings(): void
    {
        foreach (WalletAccount::listPage([], 1, 100, 'id', 'asc')['items'] as $account) {
            foreach (self::PAYMENT_TOKEN_CODES as $tokenCode) {
                if (WithdrawSetting::findByAccountToken((int)$account['id'], $tokenCode)) {
                    continue;
                }
                WithdrawSetting::saveForAccountToken((int)$account['id'], $tokenCode, [
                    'network_code' => (string)$account['network_code'],
                    'enabled' => 0,
                    'target_address' => '',
                    'min_amount_int' => '0',
                    'max_retry_count' => 3,
                    'status' => 'disabled',
                    'error_message' => '',
                ]);
            }
        }
    }

    private function autoCreateFromSettings(): void
    {
        foreach (WithdrawSetting::enabledList() as $setting) {
            try {
                $account = $this->localAccount((int)$setting['wallet_account_id']);
                if (WithdrawalTask::activeCountByWalletAccount((int)$account['id']) > 0) {
                    continue;
                }
                if (WithdrawalTask::processableCountByWalletAccountToken((int)$account['id'], (string)$setting['token_code']) > 0) {
                    continue;
                }
                $preview = $this->preview([
                    'wallet_account_id' => (int)$account['id'],
                    'token_code' => (string)$setting['token_code'],
                    'to_address' => (string)$setting['target_address'],
                ]);
                $minAmountInt = (string)($setting['min_amount_int'] ?? '0');
                if ($minAmountInt !== '0' && gmp_cmp(gmp_init((string)$preview['amount_int'], 10), gmp_init($minAmountInt, 10)) < 0) {
                    continue;
                }
                if ($preview['amount_int'] !== '0') {
                    $this->create([
                        'wallet_account_id' => (int)$account['id'],
                        'token_code' => (string)$setting['token_code'],
                        'to_address' => (string)$setting['target_address'],
                    ]);
                    WithdrawSetting::updateById((int)$setting['id'], [
                        'last_run_at' => date('Y-m-d H:i:s'),
                        'status' => 'created',
                        'error_message' => '',
                    ]);
                }
            } catch (Throwable $e) {
                WithdrawSetting::updateById((int)$setting['id'], [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function maxRetryCountForAccountToken(int $walletAccountId, string $tokenCode): int
    {
        $setting = WithdrawSetting::findByAccountToken($walletAccountId, $tokenCode);
        return min(100, max(0, (int)($setting['max_retry_count'] ?? 3)));
    }

    private function parseAmountDisplay(string $amount, int $decimals): string
    {
        $amount = trim($amount);
        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('转出数量格式错误');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        if (strlen($fraction) > $decimals) {
            throw new InvalidArgumentException('转出数量小数位不能超过 ' . $decimals . ' 位');
        }
        $fraction = str_pad($fraction, $decimals, '0');
        $value = ltrim($whole . $fraction, '0');
        return $value === '' ? '0' : $value;
    }

    private function erc20TransferGasLimit(): string
    {
        return '100000';
    }

    private function currentConfirmations(int $latestBlock, int $txBlock, int $requiredConfirmations): int
    {
        if ($txBlock <= 0 || $latestBlock < $txBlock) {
            return 0;
        }
        return min($requiredConfirmations, $latestBlock - $txBlock + 1);
    }

    private function nativeSymbol(string $networkCode): string
    {
        $symbol = config('chains.networks.' . $networkCode . '.native_symbol');
        return $symbol ? (string)$symbol : 'ETH';
    }

    private function formatTokenAmount(string $amountInt, int $decimals): string
    {
        return (new TokenAmountService())->toDisplay($amountInt, $decimals);
    }

    private function isEvmAddress(string $address): bool
    {
        return preg_match('/^0x[a-f0-9]{40}$/', strtolower($address)) === 1;
    }

    public function loop(): void
    {
        while (true) {
            try {
                $this->autoCreateFromSettings();
                $result = $this->processPending(10);
                if ($result) {
                    Log::info('转出进程执行结果', $result);
                }
            } catch (Throwable $e) {
                Log::error('转出进程执行失败：' . $e->getMessage());
            }
            sleep($this->autoWithdrawIntervalSeconds());
        }
    }

    private function autoWithdrawIntervalSeconds(): int
    {
        try {
            $interval = (int)SystemSetting::getValue(self::AUTO_WITHDRAW_INTERVAL_KEY, '10');
            return min(3600, max(1, $interval));
        } catch (Throwable) {
            return 10;
        }
    }
}
