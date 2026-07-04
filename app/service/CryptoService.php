<?php

namespace app\service;

use RuntimeException;

class CryptoService
{
    private const PREFIX = 'v1:';

    public function encrypt(?string $plain): string
    {
        if ($plain === null || $plain === '') {
            return '';
        }
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('当前 PHP 未启用 sodium 扩展，无法加密敏感信息');
        }
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(?string $cipherText): string
    {
        if ($cipherText === null || $cipherText === '') {
            return '';
        }
        if (!str_starts_with($cipherText, self::PREFIX)) {
            return '';
        }
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new RuntimeException('当前 PHP 未启用 sodium 扩展，无法解密敏感信息');
        }
        $raw = base64_decode(substr($cipherText, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('加密内容格式错误');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if ($plain === false) {
            throw new RuntimeException('敏感信息解密失败，请检查加密密钥是否正确');
        }
        return $plain;
    }

    public function mask(?string $value): string
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 10) {
            return substr($value, 0, 2) . '***' . substr($value, -2);
        }
        return substr($value, 0, 6) . '***' . substr($value, -4);
    }

    private function key(): string
    {
        $value = getenv('WALLET_ENCRYPTION_KEY') ?: $this->envFileValue('WALLET_ENCRYPTION_KEY');
        if ($value === '') {
            throw new RuntimeException('服务器未配置 WALLET_ENCRYPTION_KEY，请在服务器环境变量或 .env 文件中配置，禁止提交到 GitHub');
        }
        if (ctype_xdigit($value) && strlen($value) >= 64) {
            return substr(hex2bin(substr($value, 0, 64)), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }
        return hash('sha256', $value, true);
    }

    private function envFileValue(string $key): string
    {
        $file = base_path('.env');
        if (!is_file($file)) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return '';
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            if (trim($name) !== $key) {
                continue;
            }
            return trim(trim($value), "\"'");
        }

        return '';
    }
}
