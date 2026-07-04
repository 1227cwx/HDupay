<?php

namespace app\service;

class EvmHexService
{
    public function quantityToInt(string $hex): int
    {
        $hex = $this->strip0x($hex);
        return $hex === '' ? 0 : (int)gmp_strval(gmp_init($hex, 16), 10);
    }

    public function quantityToDecimalString(string $hex): string
    {
        $hex = $this->strip0x($hex);
        return $hex === '' ? '0' : gmp_strval(gmp_init($hex, 16), 10);
    }

    public function decimalToQuantity(string|int $value): string
    {
        $value = (string)$value;
        if ($value === '' || $value === '0') {
            return '0x0';
        }
        return '0x' . gmp_strval(gmp_init($value, 10), 16);
    }

    public function hexToDecimal(string $hex): string
    {
        $hex = $this->strip0x($hex);
        return $hex === '' ? '0' : gmp_strval(gmp_init($hex, 16), 10);
    }

    public function strip0x(string $hex): string
    {
        return str_starts_with(strtolower($hex), '0x') ? substr($hex, 2) : $hex;
    }

    public function addressToTopic(string $address): string
    {
        $address = strtolower($this->strip0x($address));
        return '0x' . str_pad($address, 64, '0', STR_PAD_LEFT);
    }

    public function topicToAddress(string $topic): string
    {
        $topic = strtolower($this->strip0x($topic));
        return '0x' . substr($topic, -40);
    }

    public function uint256Hex(string $decimal): string
    {
        return str_pad(gmp_strval(gmp_init($decimal, 10), 16), 64, '0', STR_PAD_LEFT);
    }
}
