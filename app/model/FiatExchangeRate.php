<?php

namespace app\model;

use Throwable;

class FiatExchangeRate extends BaseModel
{
    protected $table = 'fiat_exchange_rates';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'token_code',
        'coingecko_id',
        'fiat_currency',
        'rate',
        'auto_update',
        'provider',
        'source_date',
        'status',
        'error_message',
        'last_refresh_at',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'token_code',
        'fiat_currency',
        'auto_update',
        'provider',
        'status',
    ];

    public static function findByTokenCurrency(string $tokenCode, string $fiatCurrency): ?array
    {
        $row = self::query()
            ->where('token_code', strtoupper($tokenCode))
            ->where('fiat_currency', strtoupper($fiatCurrency))
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function allRates(): array
    {
        return self::query()
            ->orderBy('token_code')
            ->orderBy('fiat_currency')
            ->get()
            ->toArray();
    }

    public static function enabledRates(): array
    {
        return self::query()
            ->where('auto_update', 1)
            ->orderBy('token_code')
            ->orderBy('fiat_currency')
            ->get()
            ->toArray();
    }

    public static function upsertRate(string $tokenCode, string $fiatCurrency, array $data): array
    {
        $tokenCode = strtoupper($tokenCode);
        $fiatCurrency = strtoupper($fiatCurrency);
        $exists = self::findByTokenCurrency($tokenCode, $fiatCurrency);
        $payload = array_merge($data, [
            'token_code' => $tokenCode,
            'fiat_currency' => $fiatCurrency,
        ]);

        if ($exists) {
            self::updateById((int)$exists['id'], $payload);
            return self::findByTokenCurrency($tokenCode, $fiatCurrency) ?: [];
        }

        try {
            return self::createRecord($payload);
        } catch (Throwable $e) {
            $fresh = self::findByTokenCurrency($tokenCode, $fiatCurrency);
            if ($fresh) {
                self::updateById((int)$fresh['id'], $data);
                return self::findByTokenCurrency($tokenCode, $fiatCurrency) ?: [];
            }
            throw $e;
        }
    }

    public static function updateAutoUpdate(string $tokenCode, string $fiatCurrency, bool $autoUpdate): bool
    {
        return self::query()
            ->where('token_code', strtoupper($tokenCode))
            ->where('fiat_currency', strtoupper($fiatCurrency))
            ->update([
                'auto_update' => $autoUpdate ? 1 : 0,
                'updated_at' => self::now(),
            ]) > 0;
    }

    public static function markFailed(string $tokenCode, string $fiatCurrency, string $message): bool
    {
        $row = self::findByTokenCurrency($tokenCode, $fiatCurrency);
        if (!$row) {
            return false;
        }

        $data = [
            'error_message' => substr($message, 0, 2000),
            'updated_at' => self::now(),
        ];
        if (self::hasValidRate((string)($row['rate'] ?? '0'))) {
            $data['status'] = 'success';
        } else {
            $data['status'] = 'failed';
            $data['last_refresh_at'] = self::now();
        }
        $data = self::filterWriteData($data);

        return self::query()
            ->where('token_code', strtoupper($tokenCode))
            ->where('fiat_currency', strtoupper($fiatCurrency))
            ->update($data) > 0;
    }

    private static function hasValidRate(string $rate): bool
    {
        $rate = trim($rate);
        if ($rate === '' || !preg_match('/^\d+(\.\d+)?$/', $rate)) {
            return false;
        }
        if (function_exists('bccomp')) {
            return bccomp($rate, '0', 20) > 0;
        }
        return (float)$rate > 0;
    }
}
