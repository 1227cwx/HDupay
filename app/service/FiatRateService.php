<?php

namespace app\service;

use app\model\FiatExchangeRate;
use app\model\SystemSetting;
use GuzzleHttp\Client;
use Hyperf\Guzzle\ClientFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use support\Log;
use Throwable;

class FiatRateService
{
    private const PROVIDER_COINGECKO = 'coingecko';
    private const RATE_SCALE = 20;
    private const DEFAULT_SYNC_INTERVAL_MINUTES = 60;
    private const COINGECKO_PRICE_URL = 'https://api.coingecko.com/api/v3/simple/price';

    private const FIAT_OPTIONS = [
        ['label' => '人民币 CNY', 'value' => 'CNY', 'symbol' => '¥'],
        ['label' => '美元 USD', 'value' => 'USD', 'symbol' => '$'],
        ['label' => '欧元 EUR', 'value' => 'EUR', 'symbol' => '€'],
        ['label' => '加元 CAD', 'value' => 'CAD', 'symbol' => 'C$'],
        ['label' => '澳元 AUD', 'value' => 'AUD', 'symbol' => 'A$'],
        ['label' => '日元 JPY', 'value' => 'JPY', 'symbol' => '¥'],
        ['label' => '港币 HKD', 'value' => 'HKD', 'symbol' => 'HK$'],
        ['label' => '英镑 GBP', 'value' => 'GBP', 'symbol' => '£'],
        ['label' => '新加坡元 SGD', 'value' => 'SGD', 'symbol' => 'S$'],
    ];

    private const RATE_TOKEN_OPTIONS = [
        ['label' => 'USDC', 'value' => 'USDC', 'coingecko_id' => 'usd-coin', 'decimals' => 6, 'atom' => '0.01'],
        ['label' => 'USDT', 'value' => 'USDT', 'coingecko_id' => 'tether', 'decimals' => 6, 'atom' => '0.01'],
    ];

    private const PAYMENT_TOKEN_CODES = ['USDC', 'USDT'];

    public function __construct()
    {
        $this->ensureDefaultRows();
        $this->ensureDefaultSettings();
    }

    public function options(): array
    {
        return [
            'tokens' => $this->paymentTokenOptions(),
            'fiat_currencies' => self::FIAT_OPTIONS,
            'default_token' => 'USDC',
            'default_fiat_currency' => 'CNY',
        ];
    }

    public function settings(): array
    {
        $provider = SystemSetting::getValue('fiat_rate_provider', self::PROVIDER_COINGECKO) ?: self::PROVIDER_COINGECKO;
        if ($provider !== self::PROVIDER_COINGECKO) {
            $provider = self::PROVIDER_COINGECKO;
        }

        $proxyMode = SystemSetting::getValue('fiat_rate_proxy_mode', 'direct') ?: 'direct';
        if (!in_array($proxyMode, ['direct', 'proxy'], true)) {
            $proxyMode = 'direct';
        }

        $proxyId = (int)SystemSetting::getValue('fiat_rate_proxy_id', '0');
        if ($proxyMode !== 'proxy') {
            $proxyId = 0;
        }

        return [
            'provider' => $provider,
            'proxy_mode' => $proxyMode,
            'proxy_id' => $proxyId,
            'sync_interval_minutes' => $this->syncIntervalMinutes(),
            'disable_cache' => (int)SystemSetting::getValue('fiat_rate_disable_cache', '0') === 1 ? 1 : 0,
            'last_refresh_at' => SystemSetting::getValue('fiat_rate_last_refresh_at', ''),
            'request_url' => $this->coingeckoUrl($this->tokenIdMap(), $this->defaultQuoteCurrencies()),
        ];
    }

    public function rates(): array
    {
        $this->ensureDefaultRows();
        $rows = FiatExchangeRate::allRates();
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$this->rateKey((string)$row['token_code'], (string)$row['fiat_currency'])] = $row;
        }

        $result = [];
        foreach (self::RATE_TOKEN_OPTIONS as $token) {
            foreach (self::FIAT_OPTIONS as $fiat) {
                $tokenCode = $token['value'];
                $currency = $fiat['value'];
                $row = $byKey[$this->rateKey($tokenCode, $currency)] ?? [];
                $result[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'token_code' => $tokenCode,
                    'token_label' => $token['label'],
                    'coingecko_id' => (string)($row['coingecko_id'] ?? $token['coingecko_id']),
                    'label' => $fiat['label'],
                    'symbol' => $fiat['symbol'],
                    'fiat_currency' => $currency,
                    'rate' => $this->cleanDecimalOutput((string)($row['rate'] ?? '0')),
                    'auto_update' => (int)($row['auto_update'] ?? 1),
                    'provider' => (string)($row['provider'] ?? self::PROVIDER_COINGECKO),
                    'source_date' => (string)($row['source_date'] ?? ''),
                    'status' => (string)($row['status'] ?? 'pending'),
                    'status_text' => $this->rateStatusText((string)($row['status'] ?? 'pending')),
                    'error_message' => (string)($row['error_message'] ?? ''),
                    'last_refresh_at' => (string)($row['last_refresh_at'] ?? ''),
                    'updated_at' => (string)($row['updated_at'] ?? ''),
                ];
            }
        }

        return $result;
    }

    public function saveSettings(array $input): array
    {
        $settings = $this->normalizeSettings($input);

        SystemSetting::saveValue('fiat_rate_provider', $settings['provider']);
        SystemSetting::saveValue('fiat_rate_proxy_mode', $settings['proxy_mode']);
        SystemSetting::saveValue('fiat_rate_proxy_id', (string)$settings['proxy_id']);
        SystemSetting::saveValue('fiat_rate_sync_interval_minutes', (string)$settings['sync_interval_minutes']);
        SystemSetting::saveValue('fiat_rate_disable_cache', (string)$settings['disable_cache']);

        return $this->settings();
    }

    public function test(array $input = []): array
    {
        $settings = $this->normalizeSettings($input ?: $this->settings());
        $tokenMap = $this->tokenIdMap();
        $quotes = $this->defaultQuoteCurrencies();
        $fetched = $this->fetchRates($tokenMap, $quotes, $settings);

        return [
            'ok' => true,
            'provider' => self::PROVIDER_COINGECKO,
            'request_url' => $this->coingeckoUrl($tokenMap, $quotes),
            'disable_cache' => (int)$settings['disable_cache'],
            'rates_count' => $this->countNestedRates($fetched['rates']),
            'source_date' => $fetched['source_date'],
            'sample_rates' => array_slice($this->flattenRates($fetched['rates']), 0, 8),
        ];
    }

    public function refreshNow(): array
    {
        return $this->refreshOnce(true);
    }

    public function toggleCurrency(string $tokenCode, string $fiatCurrency, bool $autoUpdate): array
    {
        $tokenCode = strtoupper(trim($tokenCode ?: 'USDC'));
        $fiatCurrency = strtoupper(trim($fiatCurrency));
        $this->assertRateToken($tokenCode);
        $this->assertFiatCurrency($fiatCurrency);

        if (!FiatExchangeRate::findByTokenCurrency($tokenCode, $fiatCurrency)) {
            $this->ensureDefaultRows();
        }
        FiatExchangeRate::updateAutoUpdate($tokenCode, $fiatCurrency, $autoUpdate);
        return [
            'rates' => $this->rates(),
        ];
    }

    public function quote(string $tokenCode, string $fiatCurrency, string $fiatAmount): array
    {
        $tokenCode = strtoupper(trim($tokenCode));
        $fiatCurrency = strtoupper(trim($fiatCurrency));
        $fiatAmount = $this->normalizeAmount($fiatAmount, '法币金额');
        $this->assertPaymentToken($tokenCode);
        $this->assertFiatCurrency($fiatCurrency);

        $rateRow = FiatExchangeRate::findByTokenCurrency($tokenCode, $fiatCurrency);
        if (!$rateRow) {
            throw new RuntimeException('当前 ' . $tokenCode . '/' . $fiatCurrency . ' 汇率不可用，请先在系统设置中同步汇率');
        }

        $rate = $this->normalizeRate((string)($rateRow['rate'] ?? '0'));
        if (bccomp($rate, '0', self::RATE_SCALE) <= 0 || ($rateRow['status'] ?? '') !== 'success') {
            throw new RuntimeException('当前 ' . $tokenCode . '/' . $fiatCurrency . ' 汇率不可用，请先在系统设置中同步汇率');
        }

        $atom = $this->tokenAtom($tokenCode);
        $rawTokenAmount = bcdiv($fiatAmount, $rate, self::RATE_SCALE);
        if (bccomp($rawTokenAmount, $atom, self::RATE_SCALE) < 0) {
            $minFiat = $this->ceilDecimal(bcmul($atom, $rate, self::RATE_SCALE), 2);
            throw new InvalidArgumentException('法币金额过小，当前 ' . $tokenCode . ' 最小支付颗粒度为 ' . $atom . '，' . $fiatCurrency . ' 金额至少约 ' . $minFiat);
        }

        $tokenAmount = $this->ceilToAtom($rawTokenAmount, $atom);
        $decimals = $this->tokenDecimals($tokenCode);
        $amountInt = (new TokenAmountService())->toInt($tokenAmount, $decimals);

        return [
            'token' => $tokenCode,
            'fiat_currency' => $fiatCurrency,
            'fiat_amount' => $fiatAmount,
            'exchange_rate' => $this->cleanDecimalOutput($rate),
            'rate_provider' => (string)($rateRow['provider'] ?? self::PROVIDER_COINGECKO),
            'rate_fetched_at' => (string)($rateRow['last_refresh_at'] ?? ''),
            'amount_int' => $amountInt,
            'token_amount' => $tokenAmount,
        ];
    }

    public function loop(): void
    {
        $lastSuccessLogAt = 0;
        while (true) {
            try {
                $result = $this->refreshOnce(false);
                if (empty($result['skipped']) && time() - $lastSuccessLogAt >= 60) {
                    $lastSuccessLogAt = time();
                    Log::info('汇率同步完成', [
                        'provider' => $result['provider'] ?? self::PROVIDER_COINGECKO,
                        'updated_count' => $result['updated_count'] ?? 0,
                        'source_date' => $result['source_date'] ?? '',
                        'last_refresh_at' => $result['last_refresh_at'] ?? '',
                    ]);
                }
            } catch (Throwable $e) {
                Log::error('汇率同步失败：' . $e->getMessage());
            }
            sleep($this->syncIntervalMinutes() * 60);
        }
    }

    private function refreshOnce(bool $throwIfLocked): array
    {
        $lockPath = runtime_path('fiat_rate_refresh.lock');
        $lock = fopen($lockPath, 'c');
        if (!$lock) {
            throw new RuntimeException('汇率同步锁创建失败，请检查 runtime 目录权限');
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            if ($throwIfLocked) {
                throw new RuntimeException('上一轮汇率同步尚未结束，请稍后再试');
            }
            return ['skipped' => true, 'message' => '上一轮汇率同步尚未结束，本轮已跳过'];
        }

        try {
            return $this->doRefresh();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function doRefresh(): array
    {
        $settings = $this->settings();
        $targets = FiatExchangeRate::enabledRates();
        $tokenMap = [];
        $quotes = [];
        foreach ($targets as $target) {
            $tokenCode = strtoupper((string)$target['token_code']);
            $fiatCurrency = strtoupper((string)$target['fiat_currency']);
            $token = $this->rateTokenOption($tokenCode);
            if (!$token || $fiatCurrency === '') {
                continue;
            }
            $tokenMap[$tokenCode] = (string)($target['coingecko_id'] ?: $token['coingecko_id']);
            $quotes[] = $fiatCurrency;
        }
        $quotes = array_values(array_unique($quotes));

        $now = date('Y-m-d H:i:s');
        if (!$tokenMap || !$quotes) {
            SystemSetting::saveValue('fiat_rate_last_refresh_at', $now);
            return [
                'ok' => true,
                'provider' => self::PROVIDER_COINGECKO,
                'updated_count' => 0,
                'last_refresh_at' => $now,
                'rates' => $this->rates(),
            ];
        }

        try {
            $fetched = $this->fetchRates($tokenMap, $quotes, $settings);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            foreach ($targets as $target) {
                FiatExchangeRate::markFailed((string)$target['token_code'], (string)$target['fiat_currency'], $message);
            }
            throw $e;
        }

        $updated = 0;
        foreach ($targets as $target) {
            $tokenCode = strtoupper((string)$target['token_code']);
            $fiatCurrency = strtoupper((string)$target['fiat_currency']);
            if (!isset($fetched['rates'][$tokenCode][$fiatCurrency])) {
                FiatExchangeRate::markFailed($tokenCode, $fiatCurrency, '汇率接口未返回 ' . $tokenCode . '/' . $fiatCurrency . ' 汇率');
                continue;
            }
            FiatExchangeRate::upsertRate($tokenCode, $fiatCurrency, [
                'coingecko_id' => $tokenMap[$tokenCode] ?? (string)($target['coingecko_id'] ?? ''),
                'rate' => $this->fixedScale($fetched['rates'][$tokenCode][$fiatCurrency], self::RATE_SCALE),
                'auto_update' => (int)($target['auto_update'] ?? 1),
                'provider' => self::PROVIDER_COINGECKO,
                'source_date' => $fetched['source_date'] ?: date('Y-m-d'),
                'status' => 'success',
                'error_message' => '',
                'last_refresh_at' => $now,
            ]);
            $updated++;
        }

        SystemSetting::saveValue('fiat_rate_last_refresh_at', $now);
        return [
            'ok' => true,
            'provider' => self::PROVIDER_COINGECKO,
            'updated_count' => $updated,
            'source_date' => $fetched['source_date'],
            'last_refresh_at' => $now,
            'rates' => $this->rates(),
        ];
    }

    private function fetchRates(array $tokenMap, array $quotes, array $settings): array
    {
        $tokenMap = array_filter($tokenMap, static fn($id) => trim((string)$id) !== '');
        $quotes = array_values(array_filter(array_unique(array_map(static fn($item) => strtoupper(trim((string)$item)), $quotes))));
        if (!$tokenMap || !$quotes) {
            return ['source_date' => date('Y-m-d'), 'rates' => []];
        }

        $options = [
            'http_errors' => false,
            'timeout' => 12,
            'connect_timeout' => 6,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'U-PAY/1.0',
            ],
            'query' => [
                'ids' => implode(',', array_values($tokenMap)),
                'vs_currencies' => strtolower(implode(',', $quotes)),
            ],
        ];

        if (!empty($settings['disable_cache'])) {
            $options['headers']['Cache-Control'] = 'no-cache';
            $options['headers']['Pragma'] = 'no-cache';
        }

        if (($settings['proxy_mode'] ?? 'direct') === 'proxy') {
            $proxyId = (int)($settings['proxy_id'] ?? 0);
            if ($proxyId <= 0) {
                throw new RuntimeException('已选择代理模式，请选择一个启用中的代理');
            }
            $proxyService = new ProxyPoolService();
            $proxy = $proxyService->runtimeById($proxyId, true, '汇率接口');
            $proxyService->applyToOptions($options, $proxy);
        }

        try {
            $response = $this->httpClient()->get(self::COINGECKO_PRICE_URL, $options);
        } catch (Throwable $e) {
            $modeText = (($settings['proxy_mode'] ?? 'direct') === 'proxy') ? '代理请求' : '直连请求';
            throw new RuntimeException('汇率接口' . $modeText . '失败：' . $this->safeError($e->getMessage()));
        }

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('汇率接口返回 HTTP 状态码 ' . $statusCode . '，返回：' . mb_substr($body, 0, 500));
        }

        return $this->parseCoingeckoResponse($body, $tokenMap, $quotes);
    }

    private function parseCoingeckoResponse(string $body, array $tokenMap, array $quotes): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('汇率接口响应格式错误');
        }

        $tokenById = [];
        foreach ($tokenMap as $tokenCode => $coingeckoId) {
            $tokenById[strtolower((string)$coingeckoId)] = strtoupper((string)$tokenCode);
        }

        $rates = [];
        foreach ($json as $coingeckoId => $items) {
            $tokenCode = $tokenById[strtolower((string)$coingeckoId)] ?? '';
            if ($tokenCode === '' || !is_array($items)) {
                continue;
            }
            foreach ($items as $quote => $rate) {
                $fiatCurrency = strtoupper((string)$quote);
                if (!in_array($fiatCurrency, $quotes, true)) {
                    continue;
                }
                $normalized = $this->normalizeRate((string)$rate);
                if (bccomp($normalized, '0', self::RATE_SCALE) <= 0) {
                    throw new RuntimeException('汇率接口返回的 ' . $tokenCode . '/' . $fiatCurrency . ' 汇率无效');
                }
                $rates[$tokenCode][$fiatCurrency] = $normalized;
            }
        }

        if (!$rates) {
            throw new RuntimeException('汇率接口未返回可用汇率数据');
        }

        return [
            'source_date' => date('Y-m-d'),
            'rates' => $rates,
        ];
    }

    private function normalizeSettings(array $input): array
    {
        $current = $this->settings();
        $provider = strtolower(trim((string)($input['provider'] ?? $current['provider'] ?? self::PROVIDER_COINGECKO)));
        if ($provider !== self::PROVIDER_COINGECKO) {
            throw new InvalidArgumentException('当前只支持 CoinGecko 汇率接口');
        }

        $proxyId = (int)($input['proxy_id'] ?? $current['proxy_id'] ?? 0);
        $proxyMode = (string)($input['proxy_mode'] ?? ($proxyId > 0 ? 'proxy' : ($current['proxy_mode'] ?? 'direct')));
        if (!in_array($proxyMode, ['direct', 'proxy'], true)) {
            throw new InvalidArgumentException('汇率接口请求方式无效');
        }
        if ($proxyMode === 'proxy') {
            (new ProxyPoolService())->runtimeById($proxyId, true, '汇率接口');
        } else {
            $proxyId = 0;
        }

        $interval = (int)($input['sync_interval_minutes'] ?? $current['sync_interval_minutes'] ?? self::DEFAULT_SYNC_INTERVAL_MINUTES);
        if ($interval < 1 || $interval > 1440) {
            throw new InvalidArgumentException('汇率同步间隔必须在 1 到 1440 分钟之间');
        }
        $disableCache = array_key_exists('disable_cache', $input)
            ? (!empty($input['disable_cache']) ? 1 : 0)
            : (!empty($current['disable_cache'] ?? 0) ? 1 : 0);

        return [
            'provider' => self::PROVIDER_COINGECKO,
            'proxy_mode' => $proxyMode,
            'proxy_id' => $proxyId,
            'sync_interval_minutes' => $interval,
            'disable_cache' => $disableCache,
        ];
    }

    private function ensureDefaultRows(): void
    {
        foreach (self::RATE_TOKEN_OPTIONS as $token) {
            foreach (self::FIAT_OPTIONS as $fiat) {
                $tokenCode = $token['value'];
                $currency = $fiat['value'];
                $exists = FiatExchangeRate::findByTokenCurrency($tokenCode, $currency);
                if ($exists) {
                    $updates = [];
                    if (($exists['coingecko_id'] ?? '') !== $token['coingecko_id']) {
                        $updates['coingecko_id'] = $token['coingecko_id'];
                    }
                    if (($exists['provider'] ?? '') !== self::PROVIDER_COINGECKO) {
                        $updates['provider'] = self::PROVIDER_COINGECKO;
                    }
                    if ($updates) {
                        FiatExchangeRate::updateById((int)$exists['id'], $updates);
                    }
                    continue;
                }
                FiatExchangeRate::upsertRate($tokenCode, $currency, [
                    'coingecko_id' => $token['coingecko_id'],
                    'rate' => $this->fixedScale('0', self::RATE_SCALE),
                    'auto_update' => 1,
                    'provider' => self::PROVIDER_COINGECKO,
                    'source_date' => null,
                    'status' => 'pending',
                    'error_message' => '',
                    'last_refresh_at' => null,
                ]);
            }
        }
    }

    private function ensureDefaultSettings(): void
    {
        if (SystemSetting::getValue('fiat_rate_provider', '') !== self::PROVIDER_COINGECKO) {
            SystemSetting::saveValue('fiat_rate_provider', self::PROVIDER_COINGECKO);
        }
        if (SystemSetting::getValue('fiat_rate_proxy_mode', '') === '') {
            SystemSetting::saveValue('fiat_rate_proxy_mode', 'direct');
        }
        if (SystemSetting::getValue('fiat_rate_proxy_id', '') === '') {
            SystemSetting::saveValue('fiat_rate_proxy_id', '0');
        }
        if (SystemSetting::getValue('fiat_rate_sync_interval_minutes', '') === '') {
            SystemSetting::saveValue('fiat_rate_sync_interval_minutes', (string)self::DEFAULT_SYNC_INTERVAL_MINUTES);
        }
        if (SystemSetting::getValue('fiat_rate_disable_cache', '') === '') {
            SystemSetting::saveValue('fiat_rate_disable_cache', '0');
        }
    }

    private function defaultQuoteCurrencies(): array
    {
        return array_values(array_map(static fn(array $option) => $option['value'], self::FIAT_OPTIONS));
    }

    private function syncIntervalMinutes(): int
    {
        try {
            $interval = (int)SystemSetting::getValue('fiat_rate_sync_interval_minutes', (string)self::DEFAULT_SYNC_INTERVAL_MINUTES);
            return min(1440, max(1, $interval));
        } catch (Throwable) {
            return self::DEFAULT_SYNC_INTERVAL_MINUTES;
        }
    }

    private function coingeckoUrl(array $tokenMap, array $quotes): string
    {
        return self::COINGECKO_PRICE_URL
            . '?ids=' . implode(',', array_values($tokenMap))
            . '&vs_currencies=' . strtolower(implode(',', $quotes));
    }

    private function ceilToAtom(string $value, string $atom): string
    {
        $atom = $this->normalizeDecimal($atom, '最小支付颗粒度', self::RATE_SCALE, false, true);
        $scale = $this->decimalScale($atom);
        $rounded = $this->ceilDecimal($value, $scale);
        $valueUnits = $this->decimalToUnits($rounded, $scale);
        $atomUnits = $this->decimalToUnits($atom, $scale);
        if (bccomp($atomUnits, '0', 0) <= 0) {
            throw new RuntimeException('最小支付颗粒度配置无效');
        }
        $quotient = bcdiv($valueUnits, $atomUnits, 0);
        if (bccomp(bcmul($quotient, $atomUnits, 0), $valueUnits, 0) < 0) {
            $quotient = bcadd($quotient, '1', 0);
        }
        return $this->unitsToDecimal(bcmul($quotient, $atomUnits, 0), $scale);
    }

    private function ceilDecimal(string $value, int $scale): string
    {
        $value = $this->normalizeDecimal($value, '计算金额', self::RATE_SCALE, true, true);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = preg_replace('/\D/', '', $fraction) ?? '';
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $rest = substr($fraction, $scale);
        $scaled = ltrim($whole . $kept, '0') ?: '0';
        if ($rest !== '' && trim($rest, '0') !== '') {
            $scaled = bcadd($scaled, '1', 0);
        }
        if ($scale <= 0) {
            return $scaled;
        }
        if (strlen($scaled) <= $scale) {
            $scaled = str_pad($scaled, $scale + 1, '0', STR_PAD_LEFT);
        }
        return substr($scaled, 0, -$scale) . '.' . substr($scaled, -$scale);
    }

    private function tokenDecimals(string $tokenCode): int
    {
        $token = $this->rateTokenOption($tokenCode);
        if ($token) {
            return (int)$token['decimals'];
        }
        return (int)(config('chains.token.decimals') ?: 6);
    }

    private function tokenAtom(string $tokenCode): string
    {
        $token = $this->rateTokenOption($tokenCode);
        if (!$token) {
            return '0.01';
        }
        return (string)$token['atom'];
    }

    private function assertPaymentToken(string $tokenCode): void
    {
        if (!in_array($tokenCode, self::PAYMENT_TOKEN_CODES, true)) {
            throw new InvalidArgumentException('当前只支持 USDC 或 USDT 收款');
        }
    }

    private function assertRateToken(string $tokenCode): void
    {
        if (!$this->rateTokenOption($tokenCode)) {
            throw new InvalidArgumentException('不支持的加密货币');
        }
    }

    private function assertFiatCurrency(string $fiatCurrency): void
    {
        foreach (self::FIAT_OPTIONS as $option) {
            if ($option['value'] === $fiatCurrency) {
                return;
            }
        }
        throw new InvalidArgumentException('不支持的法币币种');
    }

    private function normalizeAmount(string $amount, string $label): string
    {
        return $this->normalizeDecimal($amount, $label, 12, false, false);
    }

    private function normalizeRate(string $rate): string
    {
        return $this->normalizeDecimal($rate, '汇率', self::RATE_SCALE, false, true);
    }

    private function normalizeDecimal(string $value, string $label, int $maxScale, bool $allowZero, bool $truncate): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($label . '格式不正确');
        }
        if (preg_match('/e/i', $value)) {
            $value = rtrim(rtrim(sprintf('%.30F', (float)$value), '0'), '.');
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException($label . '格式不正确');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($fraction) > $maxScale) {
            if (!$truncate) {
                throw new InvalidArgumentException($label . '小数位不能超过 ' . $maxScale . ' 位');
            }
            $fraction = substr($fraction, 0, $maxScale);
        }
        $normalized = $fraction === '' ? $whole : $whole . '.' . rtrim($fraction, '0');
        if (str_ends_with($normalized, '.')) {
            $normalized = rtrim($normalized, '.');
        }
        if ($normalized === '') {
            $normalized = '0';
        }
        if (!$allowZero && bccomp($normalized, '0', $maxScale) <= 0) {
            throw new InvalidArgumentException($label . '必须大于 0');
        }
        return $normalized;
    }

    private function fixedScale(string $value, int $scale): string
    {
        $value = $this->normalizeDecimal($value, '汇率', $scale, true, true);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ($whole === '' ? '0' : $whole) . '.' . str_pad(substr($fraction, 0, $scale), $scale, '0');
    }

    private function cleanDecimalOutput(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '0';
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value === '' ? '0' : $value;
    }

    private function decimalScale(string $value): int
    {
        $value = $this->normalizeDecimal($value, '数值', self::RATE_SCALE, true, true);
        if (!str_contains($value, '.')) {
            return 0;
        }
        return strlen(rtrim(explode('.', $value, 2)[1], '0'));
    }

    private function decimalToUnits(string $value, int $scale): string
    {
        $value = $this->normalizeDecimal($value, '数值', self::RATE_SCALE, true, true);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fraction) > $scale) {
            throw new RuntimeException('数值精度超过可转换范围');
        }
        return ltrim(($whole === '' ? '0' : $whole) . str_pad($fraction, $scale, '0'), '0') ?: '0';
    }

    private function unitsToDecimal(string $units, int $scale): string
    {
        $units = ltrim($units, '0') ?: '0';
        if ($scale <= 0) {
            return $units;
        }
        if (strlen($units) <= $scale) {
            $units = str_pad($units, $scale + 1, '0', STR_PAD_LEFT);
        }
        return substr($units, 0, -$scale) . '.' . substr($units, -$scale);
    }

    private function rateStatusText(string $status): string
    {
        return match ($status) {
            'success' => '正常',
            'failed' => '失败',
            default => '待同步',
        };
    }

    private function safeError(string $message): string
    {
        $message = $message !== '' ? $message : '未知错误';
        if (str_contains($message, 'cURL error 35')) {
            return '连接被对方重置，请检查当前服务器或所选代理是否可以访问 CoinGecko';
        }
        if (str_contains($message, 'cURL error 7')) {
            return '连接失败，请检查当前服务器或所选代理是否可以连接 CoinGecko';
        }
        if (str_contains($message, 'cURL error 28') || str_contains($message, 'timed out')) {
            return '请求超时，请检查当前服务器或所选代理访问 CoinGecko 是否超时';
        }
        if (str_contains($message, 'Could not resolve')) {
            return '域名解析失败，请检查服务器 DNS 或代理网络';
        }
        return mb_substr($message, 0, 500);
    }

    private function tokenIdMap(): array
    {
        $map = [];
        foreach (self::RATE_TOKEN_OPTIONS as $token) {
            $map[$token['value']] = $token['coingecko_id'];
        }
        return $map;
    }

    private function rateTokenOption(string $tokenCode): ?array
    {
        $tokenCode = strtoupper($tokenCode);
        foreach (self::RATE_TOKEN_OPTIONS as $token) {
            if ($token['value'] === $tokenCode) {
                return $token;
            }
        }
        return null;
    }

    private function paymentTokenOptions(): array
    {
        return array_values(array_filter(self::RATE_TOKEN_OPTIONS, static fn(array $token) => in_array($token['value'], self::PAYMENT_TOKEN_CODES, true)));
    }

    private function rateKey(string $tokenCode, string $fiatCurrency): string
    {
        return strtoupper($tokenCode) . '|' . strtoupper($fiatCurrency);
    }

    private function countNestedRates(array $rates): int
    {
        $count = 0;
        foreach ($rates as $items) {
            $count += is_array($items) ? count($items) : 0;
        }
        return $count;
    }

    private function flattenRates(array $rates): array
    {
        $result = [];
        foreach ($rates as $tokenCode => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $fiatCurrency => $rate) {
                $result[] = [
                    'token_code' => (string)$tokenCode,
                    'fiat_currency' => (string)$fiatCurrency,
                    'rate' => $this->cleanDecimalOutput((string)$rate),
                ];
            }
        }
        return $result;
    }

    private function httpClient(): Client
    {
        static $factory = null;
        if ($factory === null) {
            $factory = new ClientFactory(new class implements ContainerInterface {
                public function get(string $id)
                {
                    throw new RuntimeException('容器未配置服务：' . $id);
                }

                public function has(string $id): bool
                {
                    return false;
                }
            });
        }

        return $factory->create([]);
    }
}
