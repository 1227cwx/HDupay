<?php

namespace app\service;

use app\model\CollectionTask;
use app\model\DepositOrder;
use app\model\GasFundingTransaction;
use app\model\OpenApiCallbackLog;
use app\model\PaymentAddress;
use app\model\WalletCollectionAddress;
use app\model\WithdrawalTask;
use app\model\WithdrawSetting;
use app\model\WalletAccount;
use app\model\WalletMaster;
use InvalidArgumentException;
use RuntimeException;

class WalletService
{
    public function initialize(array $input): array
    {
        return (new EvmWalletService())->initializeWallet((string)($input['name'] ?? 'default'), $input['mnemonic'] ?? null);
    }

    public function masters(): array
    {
        return WalletMaster::listPage([], 1, 100, 'id', 'desc')['items'];
    }

    public function accounts(): array
    {
        $items = WalletAccount::listPage([], 1, 100, 'id', 'asc')['items'];
        foreach ($items as &$item) {
            $item = (new EvmWalletService())->ensureAccountSystemAddresses($item);
            $item = $this->sanitizeAccount($item);
        }
        return $items;
    }

    public function overview(): array
    {
        $masters = $this->masters();
        $accounts = $this->accounts();
        $supportedNetworks = $this->supportedNetworkOptions();
        $availableNetworks = $this->availableNetworkOptions();
        $addressStats = $this->groupStats(PaymentAddress::statusStats());
        $collectionStats = $this->groupStats(CollectionTask::statusStats());

        foreach ($accounts as &$account) {
            $networkCode = (string)$account['network_code'];
            $account['network_name'] = config('chains.networks.' . $networkCode . '.name') ?: $networkCode;
            $account['chain_id'] = (int)(config('chains.networks.' . $networkCode . '.chain_id') ?: 0);
            $account['native_symbol'] = $this->nativeSymbol($networkCode);
            $account['address_stats'] = $addressStats[$networkCode] ?? $this->emptyAddressStats();
            $account['collection_stats'] = $collectionStats[$networkCode] ?? $this->emptyCollectionStats();
        }

        $totalAddressStats = $this->sumStats($addressStats, $this->emptyAddressStats());
        $totalCollectionStats = $this->sumStats($collectionStats, $this->emptyCollectionStats());

        return [
            'masters' => $masters,
            'accounts' => $accounts,
            'supported_networks' => $supportedNetworks,
            'available_networks' => $availableNetworks,
            'summary' => [
                'root_wallets' => count(array_filter($masters, fn($master) => ($master['status'] ?? '') === 'active')),
                'network_accounts' => count($accounts),
                'network_accounts_active' => count(array_filter($accounts, fn($account) => ($account['status'] ?? '') === 'active')),
                'addresses_total' => $totalAddressStats['total'],
                'addresses_available' => $totalAddressStats['available'],
                'addresses_assigned' => $totalAddressStats['assigned'],
                'addresses_frozen' => $totalAddressStats['frozen'] + $totalAddressStats['collected'],
                'collections_pending' => $totalCollectionStats['pending_collect'] + $totalCollectionStats['gas_funding'] + $totalCollectionStats['collecting'],
                'collections_done' => $totalCollectionStats['collected'],
            ],
        ];
    }

    public function accountBalance(array $input): array
    {
        $networkCode = trim((string)($input['network_code'] ?? ''));
        $type = trim((string)($input['type'] ?? ''));
        if ($networkCode === '') {
            throw new InvalidArgumentException('网络不能为空');
        }
        if (!in_array($type, ['collection', 'gas'], true)) {
            throw new InvalidArgumentException('余额类型无效');
        }

        $account = WalletAccount::findAnyByNetwork($networkCode);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }
        $this->assertAccountActive($account);

        if ($type === 'collection') {
            $activeCollection = (new WalletAssetService())->activeCollectionAddressForAccount($account);
            $address = (string)($activeCollection['address_lower'] ?? ($account['collection_address'] ?? ''));
        } else {
            $address = (string)($account['gas_funder_address'] ?? '');
        }
        if ($address === '') {
            throw new RuntimeException($type === 'collection' ? '归集钱包地址未配置' : 'Gas 钱包地址未配置');
        }

        $rpc = new EvmRpcService();
        $nativeBalanceWei = $rpc->getBalance($networkCode, $address);

        $result = [
            'network_code' => $networkCode,
            'type' => $type,
            'address' => strtolower($address),
            'native_symbol' => $this->nativeSymbol($networkCode),
            'native_balance_wei' => $nativeBalanceWei,
            'native_balance' => $this->formatTokenAmount($nativeBalanceWei, 18),
            'token_code' => 'USDC',
            'token_balance_int' => null,
            'token_balance' => null,
            'token_balances' => [],
        ];

        if ($type === 'collection') {
            foreach (['USDC', 'USDT'] as $tokenCode) {
                $token = $rpc->tokenConfig($networkCode, $tokenCode);
                $tokenBalanceInt = $rpc->tokenBalanceOf($networkCode, (string)$token['contract_address'], $address);
                $balance = [
                    'token_code' => $tokenCode,
                    'token_balance_int' => $tokenBalanceInt,
                    'token_balance' => $this->formatTokenAmount($tokenBalanceInt, (int)($token['decimals'] ?? 6)),
                ];
                $result['token_balances'][] = $balance;
                if ($tokenCode === 'USDC') {
                    $result['token_balance_int'] = $tokenBalanceInt;
                    $result['token_balance'] = $balance['token_balance'];
                }
            }
        }

        return $result;
    }

    public function saveCollectionTarget(array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('钱包账号 ID 无效');
        }
        $collectionType = (string)($input['collection_type'] ?? 'local');
        if (!in_array($collectionType, ['local', 'exchange'], true)) {
            throw new InvalidArgumentException('归集类型无效');
        }

        $account = WalletAccount::findById($id);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }
        $this->assertAccountActive($account);

        if ($collectionType === 'local') {
            $system = WalletCollectionAddress::systemByAccountId($id);
            if ($system) {
                (new WalletAssetService())->setCollectionActive((int)$system['id'], true);
            } else {
                $local = $this->localCollectionAddress($account);
                WalletAccount::updateById($id, [
                    'collection_type' => 'local',
                    'collection_address' => $local['address'],
                    'collection_derivation_path' => $local['path'],
                ]);
            }
        } else {
            $address = strtolower(trim((string)($input['collection_address'] ?? '')));
            $this->assertEvmAddress($address);
            $assetService = new WalletAssetService();
            if (!WalletCollectionAddress::findByAccountAddress($id, $address)) {
                $assetService->addCollectionAddress(['wallet_account_id' => $id, 'address' => $address]);
            }
            $row = WalletCollectionAddress::findByAccountAddress($id, $address);
            if ($row) {
                $assetService->setCollectionActive((int)$row['id'], true);
            }
        }

        return $this->sanitizeAccount(WalletAccount::findById($id) ?: []);
    }

    public function exportRootPrivateKey(array $input): array
    {
        return (new EvmWalletService())->exportRootPrivateKey(
            (int)($input['wallet_master_id'] ?? 0),
            (string)($input['mnemonic'] ?? '')
        );
    }

    public function deleteRootWallet(array $input): array
    {
        $masterId = (int)($input['wallet_master_id'] ?? 0);
        (new EvmWalletService())->verifyRootWalletMnemonic($masterId, (string)($input['mnemonic'] ?? ''));

        $accounts = WalletAccount::listByMasterId($masterId);
        $accountIds = WalletAccount::idsByMasterId($masterId);
        $addressIds = PaymentAddress::idsByWalletAccountIds($accountIds);
        $orderNos = DepositOrder::orderNosByAddressIds($addressIds);
        $relatedAddresses = PaymentAddress::addressLowersByWalletAccountIds($accountIds);
        foreach ($accounts as $account) {
            foreach (['collection_address', 'gas_funder_address'] as $field) {
                $address = strtolower(trim((string)($account[$field] ?? '')));
                if ($address !== '') {
                    $relatedAddresses[] = $address;
                }
            }
        }

        $deletedCallbackLogs = OpenApiCallbackLog::deleteByOrderNos($orderNos);
        $deletedOrders = DepositOrder::deleteByAddressIds($addressIds);
        $deletedCollections = CollectionTask::deleteByAddressIds($addressIds);
        $deletedWithdrawals = WithdrawalTask::deleteByWalletAccountIds($accountIds);
        $deletedGasFunding = GasFundingTransaction::deleteByAddresses($relatedAddresses);
        $deletedAddresses = PaymentAddress::deleteByWalletAccountIds($accountIds);
        $deletedSystemCollectionWallets = WalletCollectionAddress::deleteSystemByAccountIds($accountIds);
        $deletedWithdrawSettings = WithdrawSetting::deleteByAccountIds($accountIds);
        $deletedAccounts = WalletAccount::deleteByMasterId($masterId);
        $deletedMasters = WalletMaster::deleteById($masterId);

        if ($deletedMasters <= 0) {
            throw new InvalidArgumentException('根钱包删除失败');
        }

        return [
            'wallet_master_id' => $masterId,
            'deleted_orders' => $deletedOrders,
            'deleted_callback_logs' => $deletedCallbackLogs,
            'deleted_collection_tasks' => $deletedCollections,
            'deleted_withdrawal_tasks' => $deletedWithdrawals,
            'deleted_gas_funding_transactions' => $deletedGasFunding,
            'deleted_payment_addresses' => $deletedAddresses,
            'deleted_system_collection_wallets' => $deletedSystemCollectionWallets,
            'deleted_withdraw_settings' => $deletedWithdrawSettings,
            'deleted_network_accounts' => $deletedAccounts,
            'deleted_network_account_ids' => $accountIds,
        ];
    }

    public function createAccount(array $input): array
    {
        $networkCode = trim((string)($input['network_code'] ?? ''));
        if ($networkCode === '') {
            throw new InvalidArgumentException('请选择要添加的网络');
        }

        $master = WalletMaster::latestActive();
        if (!$master) {
            throw new RuntimeException('请先初始化根钱包');
        }

        $account = (new EvmWalletService())->createNetworkAccount($master, $networkCode);
        (new WalletAssetService())->activeCollectionAddressForAccount($account);
        (new RpcConfigService())->ensureDefaults();
        return $this->sanitizeAccount($account);
    }

    public function toggleAccount(array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('网络账户 ID 无效');
        }

        $account = WalletAccount::findById($id);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }

        WalletAccount::updateById($id, ['status' => !empty($input['enabled']) ? 'active' : 'disabled']);
        return $this->sanitizeAccount(WalletAccount::findById($id) ?: []);
    }

    public function updateAccount(array $input): array
    {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('钱包账号 ID 无效');
        }
        $account = WalletAccount::findById($id);
        if (!$account) {
            throw new RuntimeException('网络账户不存在');
        }
        $this->assertAccountActive($account);

        $data = [];
        if (array_key_exists('collection_address', $input)) {
            $data['collection_address'] = strtolower(trim((string)$input['collection_address']));
        }
        if (array_key_exists('gas_funder_address', $input)) {
            $data['gas_funder_address'] = strtolower(trim((string)$input['gas_funder_address']));
        }
        if (array_key_exists('deposit_timeout_minutes', $input)) {
            $data['deposit_timeout_minutes'] = min(1440, max(1, (int)$input['deposit_timeout_minutes']));
        }
        if (!empty($input['gas_funder_private_key'])) {
            $data['encrypted_gas_funder_private_key'] = (new CryptoService())->encrypt(trim((string)$input['gas_funder_private_key']));
        }
        if ($data) {
            WalletAccount::updateById($id, $data);
        }
        $row = WalletAccount::findById($id) ?: [];
        if ($row) {
            $row = $this->sanitizeAccount($row);
        }
        return $row;
    }

    public function depositNetworkOptions(): array
    {
        return (new DepositNetworkAvailabilityService())->options();
    }

    private function supportedNetworkOptions(): array
    {
        $options = [];
        foreach (config('chains.networks') ?: [] as $networkCode => $_) {
            $options[] = $this->networkOption((string)$networkCode);
        }
        return $options;
    }

    private function availableNetworkOptions(): array
    {
        $used = array_flip(WalletAccount::usedNetworkCodes());
        return array_values(array_filter(
            $this->supportedNetworkOptions(),
            fn($option) => !isset($used[$option['value']])
        ));
    }

    private function networkOption(string $networkCode): array
    {
        return [
            'label' => (string)(config('chains.networks.' . $networkCode . '.name') ?: $networkCode),
            'value' => $networkCode,
            'chain_id' => (int)(config('chains.networks.' . $networkCode . '.chain_id') ?: 0),
            'native_symbol' => $this->nativeSymbol($networkCode),
        ];
    }

    private function sanitizeAccount(array $account): array
    {
        $account['encrypted_xprv'] = '';
        $account['encrypted_gas_funder_private_key'] = '';
        $account['deposit_timeout_minutes'] = min(1440, max(1, (int)($account['deposit_timeout_minutes'] ?? 10)));
        $account['collection_type'] = in_array(($account['collection_type'] ?? 'local'), ['local', 'exchange'], true) ? $account['collection_type'] : 'local';
        return $account;
    }

    private function assertAccountActive(array $account): void
    {
        if (($account['status'] ?? '') !== 'active') {
            throw new RuntimeException('网络账户已停用，请先启用后再操作');
        }
    }

    private function localCollectionAddress(array $account): array
    {
        $seedHex = (new CryptoService())->decrypt((string)($account['encrypted_xprv'] ?? ''));
        $path = $this->localCollectionPath($account);
        return (new EvmWalletService())->deriveAddressFromSeed($seedHex, $path);
    }

    private function localCollectionPath(array $account): string
    {
        $collectionPath = trim((string)($account['collection_derivation_path'] ?? ''));
        if (preg_match("#^m/44'/60'/\d+'/1/0$#", $collectionPath)) {
            return $collectionPath;
        }

        $accountPath = rtrim(trim((string)($account['derivation_path'] ?? '')), '/');
        if (!preg_match("#^m/44'/60'/\d+'/0$#", $accountPath)) {
            throw new RuntimeException('本地归集钱包派生路径无效，无法恢复本地归集地址');
        }

        return substr($accountPath, 0, -2) . '/1/0';
    }

    private function assertEvmAddress(string $address): void
    {
        if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            throw new InvalidArgumentException('请输入正确的当前网络 EVM 地址，格式必须是 0x 开头的 40 位十六进制地址');
        }
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

    private function groupStats(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $networkCode = (string)$row['network_code'];
            if (!isset($result[$networkCode])) {
                $result[$networkCode] = [];
            }
            $status = (string)$row['status'];
            $count = (int)$row['total'];
            $result[$networkCode][$status] = $count;
            $result[$networkCode]['total'] = ($result[$networkCode]['total'] ?? 0) + $count;
        }
        foreach ($result as $networkCode => $stats) {
            $result[$networkCode] = array_merge($this->emptyAddressStats(), $this->emptyCollectionStats(), $stats);
        }
        return $result;
    }

    private function sumStats(array $groupedStats, array $defaults): array
    {
        $total = $defaults;
        foreach ($groupedStats as $stats) {
            foreach ($stats as $key => $value) {
                $total[$key] = ($total[$key] ?? 0) + (int)$value;
            }
        }
        return $total;
    }

    private function emptyAddressStats(): array
    {
        return [
            'total' => 0,
            'available' => 0,
            'assigned' => 0,
            'paid_detected' => 0,
            'frozen' => 0,
            'collected' => 0,
            'expired' => 0,
        ];
    }

    private function emptyCollectionStats(): array
    {
        return [
            'pending_collect' => 0,
            'gas_funding' => 0,
            'collecting' => 0,
            'collected' => 0,
            'collect_failed' => 0,
            'manual_required' => 0,
        ];
    }
}
