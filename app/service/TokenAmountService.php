<?php

namespace app\service;

use InvalidArgumentException;

class TokenAmountService
{
    public function toInt(string $amount, int $decimals = 6): string
    {
        $amount = trim($amount);
        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('金额格式错误');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        if (strlen($fraction) > $decimals) {
            throw new InvalidArgumentException('金额小数位不能超过 ' . $decimals . ' 位');
        }
        $fraction = str_pad($fraction, $decimals, '0');
        $value = ltrim($whole . $fraction, '0');
        return $value === '' ? '0' : $value;
    }

    public function toDisplay(string $amountInt, int $decimals = 6): string
    {
        $amountInt = ltrim($amountInt, '0');
        if ($amountInt === '') {
            $amountInt = '0';
        }
        if (strlen($amountInt) <= $decimals) {
            $amountInt = str_pad($amountInt, $decimals + 1, '0', STR_PAD_LEFT);
        }
        $whole = substr($amountInt, 0, -$decimals);
        $fraction = rtrim(substr($amountInt, -$decimals), '0');
        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    public function gte(string $left, string $right): bool
    {
        return $this->compare($left, $right) >= 0;
    }

    public function compare(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }
        return $left <=> $right;
    }
}
