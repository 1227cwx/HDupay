<?php

namespace app\service;

use app\model\PaymentAddress;
use app\model\WalletAccount;
use app\model\WalletMaster;
use Elliptic\EC;
use InvalidArgumentException;
use RuntimeException;
use Web3p\EthereumUtil\Util;

class EvmWalletService
{
    private const CURVE_N = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public function generateMnemonic(): string
    {
        $entropy = random_bytes(16);
        $checksum = substr($this->bytesToBits(hash('sha256', $entropy, true)), 0, 4);
        $bits = $this->bytesToBits($entropy) . $checksum;
        $words = $this->bip39EnglishWords();
        $mnemonic = [];

        for ($i = 0; $i < strlen($bits); $i += 11) {
            $mnemonic[] = $words[bindec(substr($bits, $i, 11))];
        }

        return implode(' ', $mnemonic);
    }

    public function initializeWallet(string $name, ?string $mnemonic = null): array
    {
        if (WalletMaster::activeCount() > 0) {
            throw new RuntimeException('系统已存在根钱包，请先删除当前根钱包后再初始化');
        }

        $mnemonic = trim((string)($mnemonic ?: $this->generateMnemonic()));
        if ($mnemonic === '') {
            throw new InvalidArgumentException('助记词不能为空');
        }
        $seedHex = $this->mnemonicToSeedHex($mnemonic);
        $crypto = new CryptoService();
        $master = WalletMaster::createRecord([
            'name' => $name ?: 'default',
            'mnemonic_fingerprint' => substr(hash('sha256', $mnemonic), 0, 32),
            'encrypted_seed_or_xprv' => $crypto->encrypt($seedHex),
            'status' => 'active',
        ]);

        foreach (config('chains.networks') as $networkCode => $network) {
            WalletAccount::createRecord($this->networkAccountPayload(
                $master,
                (string)$networkCode,
                $network,
                $seedHex,
                $crypto
            ));
        }

        return [
            'master' => $master,
            'mnemonic' => $mnemonic,
            'warning' => '请立即离线备份助记词，禁止提交到 GitHub 或发送到聊天窗口',
        ];
    }

    public function createNetworkAccount(array $master, string $networkCode): array
    {
        $networkCode = trim($networkCode);
        $network = config('chains.networks.' . $networkCode);
        if (!$network) {
            throw new InvalidArgumentException('网络不存在');
        }
        if (($master['status'] ?? '') !== 'active') {
            throw new RuntimeException('根钱包未启用，不能添加网络账户');
        }
        if (WalletAccount::findAnyByNetwork($networkCode)) {
            throw new RuntimeException('该网络账户已经存在，不能重复添加');
        }

        $seedHex = (new CryptoService())->decrypt((string)($master['encrypted_seed_or_xprv'] ?? ''));
        return WalletAccount::createRecord($this->networkAccountPayload(
            $master,
            $networkCode,
            $network,
            $seedHex,
            new CryptoService()
        ));
    }

    private function networkAccountPayload(array $master, string $networkCode, array $network, string $seedHex, CryptoService $crypto): array
    {
        $accountIndex = (int)$network['account_index'];
        $accountPath = "m/44'/60'/{$accountIndex}'/0";
        $collectionPath = "m/44'/60'/{$accountIndex}'/1/0";
        $gasFunderPath = "m/44'/60'/{$accountIndex}'/2/0";
        $collection = $this->deriveAddressFromSeed($seedHex, $collectionPath);
        $gasFunder = $this->deriveAddressFromSeed($seedHex, $gasFunderPath);

        return [
            'wallet_master_id' => $master['id'],
            'network_code' => $networkCode,
            'derivation_path' => $accountPath,
            'xpub' => $this->deriveAccountPublicDescriptor($seedHex, $accountPath),
            'encrypted_xprv' => $crypto->encrypt($seedHex),
            'next_index' => 0,
            'deposit_timeout_minutes' => 10,
            'collection_type' => 'local',
            'collection_address' => $collection['address'],
            'collection_derivation_path' => $collectionPath,
            'gas_funder_address' => $gasFunder['address'],
            'gas_funder_derivation_path' => $gasFunderPath,
            'encrypted_gas_funder_private_key' => $crypto->encrypt($gasFunder['private_key']),
            'status' => 'active',
        ];
    }

    public function ensureAccountSystemAddresses(array $account): array
    {
        $needsCollection = empty($account['collection_address']) || empty($account['collection_derivation_path']);
        $needsGasFunder = empty($account['gas_funder_address'])
            || empty($account['gas_funder_derivation_path'])
            || empty($account['encrypted_gas_funder_private_key']);

        if (!$needsCollection && !$needsGasFunder) {
            return $account;
        }

        $seedHex = (new CryptoService())->decrypt($account['encrypted_xprv']);
        $accountPrefix = $this->accountPrefixFromDepositPath((string)$account['derivation_path']);
        $data = [];

        if ($needsCollection) {
            $collectionPath = $accountPrefix . '/1/0';
            $collection = $this->deriveAddressFromSeed($seedHex, $collectionPath);
            $data['collection_address'] = $collection['address'];
            $data['collection_derivation_path'] = $collectionPath;
        }

        if ($needsGasFunder) {
            $gasFunderPath = $accountPrefix . '/2/0';
            $gasFunder = $this->deriveAddressFromSeed($seedHex, $gasFunderPath);
            $data['gas_funder_address'] = $gasFunder['address'];
            $data['gas_funder_derivation_path'] = $gasFunderPath;
            $data['encrypted_gas_funder_private_key'] = (new CryptoService())->encrypt($gasFunder['private_key']);
        }

        if ($data) {
            WalletAccount::updateById((int)$account['id'], $data);
            return WalletAccount::findById((int)$account['id']) ?: $account;
        }

        return $account;
    }

    public function createNextAddress(string $networkCode, string $tokenCode = 'USDC'): array
    {
        $account = WalletAccount::findByNetwork($networkCode);
        if (!$account) {
            throw new RuntimeException('当前网络账户不存在或未启用');
        }
        $seedHex = (new CryptoService())->decrypt($account['encrypted_xprv']);
        $index = (int)$account['next_index'];
        $path = $account['derivation_path'] . '/' . $index;
        $derived = $this->deriveAddressFromSeed($seedHex, $path);
        $address = PaymentAddress::createRecord([
            'network_code' => $networkCode,
            'token_code' => $tokenCode,
            'wallet_account_id' => $account['id'],
            'address' => $derived['address'],
            'address_lower' => strtolower($derived['address']),
            'derivation_path' => $path,
            'address_index' => $index,
            'status' => 'available',
            'assigned_order_no' => '',
        ]);
        WalletAccount::incrementNextIndex((int)$account['id']);
        return $address;
    }

    public function privateKeyForAddress(array $address): string
    {
        $account = WalletAccount::findById((int)$address['wallet_account_id']);
        if (!$account) {
            throw new RuntimeException('钱包账号不存在');
        }
        $seedHex = (new CryptoService())->decrypt($account['encrypted_xprv']);
        return $this->deriveAddressFromSeed($seedHex, $address['derivation_path'])['private_key'];
    }

    public function privateKeyForWalletAccountPath(array $account, string $path): string
    {
        $seedHex = (new CryptoService())->decrypt($account['encrypted_xprv'] ?? '');
        return $this->deriveAddressFromSeed($seedHex, $path)['private_key'];
    }

    public function exportRootPrivateKey(int $masterId, string $mnemonic): array
    {
        $verified = $this->verifyRootWalletMnemonic($masterId, $mnemonic);
        $node = $this->derivePrivateNode($verified['seed_hex'], 'm');
        return [
            'wallet_master_id' => $masterId,
            'mnemonic_fingerprint' => $verified['fingerprint'],
            'root_private_key' => $node['private_key'],
            'chain_code' => $node['chain_code'],
            'root_extended_private_key' => $this->rootExtendedPrivateKey($node['private_key'], $node['chain_code']),
            'warning' => '根私钥、链码和根扩展私钥都可以控制整套 HD 钱包，请只在离线安全环境保存，禁止截图、转发或提交到 GitHub',
        ];
    }

    public function verifyRootWalletMnemonic(int $masterId, string $mnemonic): array
    {
        $mnemonic = trim($mnemonic);
        if ($masterId <= 0) {
            throw new InvalidArgumentException('根钱包 ID 无效');
        }
        if ($mnemonic === '') {
            throw new InvalidArgumentException('请输入创建钱包时备份的助记词');
        }

        $master = WalletMaster::findById($masterId);
        if (!$master || $master['status'] !== 'active') {
            throw new RuntimeException('根钱包不存在');
        }

        $fingerprint = substr(hash('sha256', $mnemonic), 0, 32);
        if (!hash_equals((string)$master['mnemonic_fingerprint'], $fingerprint)) {
            throw new InvalidArgumentException('助记词验证失败');
        }

        $seedHex = $this->mnemonicToSeedHex($mnemonic);
        $savedSeedHex = (new CryptoService())->decrypt($master['encrypted_seed_or_xprv']);
        if (!hash_equals($savedSeedHex, $seedHex)) {
            throw new InvalidArgumentException('助记词验证失败');
        }

        return [
            'master' => $master,
            'seed_hex' => $seedHex,
            'fingerprint' => $fingerprint,
        ];
    }

    public function deriveAddressFromSeed(string $seedHex, string $path): array
    {
        $node = $this->derivePrivateNode($seedHex, $path);
        $util = new Util();
        $publicKey = $util->privateKeyToPublicKey($node['private_key']);
        $address = $util->publicKeyToAddress($publicKey);
        return [
            'path' => $path,
            'private_key' => $node['private_key'],
            'public_key' => $publicKey,
            'address' => strtolower($address),
        ];
    }

    private function deriveAccountPublicDescriptor(string $seedHex, string $path): string
    {
        $node = $this->derivePrivateNode($seedHex, $path);
        return json_encode([
            'type' => 'evm-bip32-public-descriptor',
            'path' => $path,
            'public_key' => $this->compressedPublicKey($node['private_key']),
            'chain_code' => $node['chain_code'],
        ], JSON_UNESCAPED_SLASHES);
    }

    private function mnemonicToSeedHex(string $mnemonic, string $passphrase = ''): string
    {
        return bin2hex(hash_pbkdf2('sha512', $mnemonic, 'mnemonic' . $passphrase, 2048, 64, true));
    }

    private function bytesToBits(string $bytes): string
    {
        $bits = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        return $bits;
    }

    private function bip39EnglishWords(): array
    {
        static $words = null;
        if ($words !== null) {
            return $words;
        }

        $wordListFile = base_path('vendor/bitwasp/bitcoin/src/Mnemonic/Bip39/Wordlist/EnglishWordList.php');
        $content = is_file($wordListFile) ? file_get_contents($wordListFile) : false;
        if ($content === false || !preg_match_all("/'([a-z]+)'/", $content, $matches)) {
            throw new RuntimeException('BIP39 英文词库读取失败');
        }

        $words = $matches[1];
        if (count($words) !== 2048) {
            throw new RuntimeException('BIP39 英文词库数量错误');
        }

        return $words;
    }

    private function derivePrivateNode(string $seedHex, string $path): array
    {
        $seed = hex2bin($seedHex);
        if ($seed === false) {
            throw new InvalidArgumentException('种子格式错误');
        }
        $i = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
        $privateKey = bin2hex(substr($i, 0, 32));
        $chainCode = bin2hex(substr($i, 32, 32));
        $segments = $this->parsePath($path);
        foreach ($segments as $segment) {
            [$privateKey, $chainCode] = $this->deriveChild($privateKey, $chainCode, $segment);
        }
        return ['private_key' => $privateKey, 'chain_code' => $chainCode];
    }

    private function parsePath(string $path): array
    {
        $path = trim($path);
        if ($path === 'm' || $path === '') {
            return [];
        }
        if (str_starts_with($path, 'm/')) {
            $path = substr($path, 2);
        }
        $result = [];
        foreach (explode('/', $path) as $segment) {
            $hardened = str_ends_with($segment, "'");
            $num = (int)rtrim($segment, "'");
            if ($num < 0) {
                throw new InvalidArgumentException('派生路径格式错误');
            }
            $result[] = $hardened ? $num + 0x80000000 : $num;
        }
        return $result;
    }

    private function accountPrefixFromDepositPath(string $path): string
    {
        $path = rtrim(trim($path), '/');
        if (!preg_match("#^m/44'/60'/\\d+'/0$#", $path)) {
            throw new InvalidArgumentException('钱包账户派生路径格式错误');
        }
        return substr($path, 0, -2);
    }

    private function deriveChild(string $privateKey, string $chainCode, int $index): array
    {
        if ($index >= 0x80000000) {
            $dataHex = '00' . str_pad($privateKey, 64, '0', STR_PAD_LEFT) . $this->ser32($index);
        } else {
            $dataHex = $this->compressedPublicKey($privateKey) . $this->ser32($index);
        }
        $i = hash_hmac('sha512', hex2bin($dataHex), hex2bin($chainCode), true);
        $il = bin2hex(substr($i, 0, 32));
        $ir = bin2hex(substr($i, 32, 32));
        $n = gmp_init(self::CURVE_N, 16);
        $child = gmp_mod(gmp_add(gmp_init($il, 16), gmp_init($privateKey, 16)), $n);
        if (gmp_cmp($child, 0) === 0) {
            throw new RuntimeException('派生子私钥失败');
        }
        return [str_pad(gmp_strval($child, 16), 64, '0', STR_PAD_LEFT), $ir];
    }

    private function rootExtendedPrivateKey(string $privateKey, string $chainCode): string
    {
        $payloadHex = '0488ade4' // mainnet xprv
            . '00' // depth
            . '00000000' // parent fingerprint
            . '00000000' // child number
            . str_pad($chainCode, 64, '0', STR_PAD_LEFT)
            . '00' . str_pad($privateKey, 64, '0', STR_PAD_LEFT);
        $payload = hex2bin($payloadHex);
        if ($payload === false) {
            throw new RuntimeException('根扩展私钥序列化失败');
        }
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        return $this->base58Encode($payload . $checksum);
    }

    private function base58Encode(string $bytes): string
    {
        $num = gmp_init(bin2hex($bytes), 16);
        $encoded = '';
        while (gmp_cmp($num, 0) > 0) {
            [$num, $remainder] = gmp_div_qr($num, 58);
            $encoded = self::BASE58_ALPHABET[gmp_intval($remainder)] . $encoded;
        }

        $leadingZeroCount = 0;
        $length = strlen($bytes);
        while ($leadingZeroCount < $length && $bytes[$leadingZeroCount] === "\x00") {
            $encoded = '1' . $encoded;
            $leadingZeroCount++;
        }

        return $encoded !== '' ? $encoded : '1';
    }

    private function compressedPublicKey(string $privateKey): string
    {
        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($privateKey, 'hex');
        return $key->getPublic(true, 'hex');
    }

    private function ser32(int $index): string
    {
        return str_pad(dechex($index), 8, '0', STR_PAD_LEFT);
    }
}
