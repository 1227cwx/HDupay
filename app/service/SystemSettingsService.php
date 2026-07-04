<?php

namespace app\service;

class SystemSettingsService
{
    public function settings(): array
    {
        $fiatRate = new FiatRateService();
        return [
            'fiat_rate' => $fiatRate->settings(),
            'fiat_rates' => $fiatRate->rates(),
            'site' => (new PublicUrlService())->settings(),
            'proxies' => (new ProxyPoolService())->enabledList(),
        ];
    }

    public function saveFiatRate(array $input): array
    {
        $fiatRate = new FiatRateService();
        return [
            'fiat_rate' => $fiatRate->saveSettings($input),
            'fiat_rates' => $fiatRate->rates(),
            'site' => (new PublicUrlService())->settings(),
            'proxies' => (new ProxyPoolService())->enabledList(),
        ];
    }

    public function saveSite(array $input): array
    {
        return (new PublicUrlService())->save($input);
    }

    public function testFiatRate(array $input): array
    {
        return (new FiatRateService())->test($input);
    }

    public function refreshFiatRate(): array
    {
        return (new FiatRateService())->refreshNow();
    }

    public function toggleFiatCurrency(array $input): array
    {
        return (new FiatRateService())->toggleCurrency(
            (string)($input['token_code'] ?? 'USDC'),
            (string)($input['fiat_currency'] ?? ''),
            !empty($input['auto_update'])
        );
    }
}
