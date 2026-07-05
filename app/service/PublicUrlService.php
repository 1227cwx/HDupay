<?php

namespace app\service;

use app\model\SystemSetting;
use InvalidArgumentException;

class PublicUrlService
{
    public function publicBaseUrl(string $host = '', string $forwardedProto = '', array $httpsHeaders = []): string
    {
        $configured = $this->normalizeBaseUrl(SystemSetting::getValue('site_public_base_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $host = trim($host);
        if ($host === '') {
            return '';
        }

        $proto = $this->requestScheme($forwardedProto, $httpsHeaders, $host);

        return $proto . '://' . $host;
    }

    public function settings(): array
    {
        return [
            'public_base_url' => SystemSetting::getValue('site_public_base_url', ''),
            'admin_allowed_domain' => (new AdminDomainAccessService())->allowedDomain(),
            'pay_public_enabled' => $this->payPublicEnabled() ? 1 : 0,
        ];
    }

    public function payPublicEnabled(): bool
    {
        return SystemSetting::getValue('site_pay_public_enabled', '1') === '1';
    }

    public function save(array $input): array
    {
        $publicBaseUrl = $this->normalizeBaseUrl((string)($input['public_base_url'] ?? ''));
        if ($publicBaseUrl !== '' && !filter_var($publicBaseUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('公开访问地址格式不正确');
        }
        if ($publicBaseUrl !== '') {
            $scheme = strtolower((string)parse_url($publicBaseUrl, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('公开访问地址必须是 http 或 https 地址');
            }
        }

        (new AdminDomainAccessService())->save((string)($input['admin_allowed_domain'] ?? ''), $publicBaseUrl);
        SystemSetting::saveValue('site_public_base_url', $publicBaseUrl);
        if (array_key_exists('pay_public_enabled', $input)) {
            SystemSetting::saveValue('site_pay_public_enabled', !empty($input['pay_public_enabled']) ? '1' : '0');
        }
        return $this->settings();
    }

    private function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return rtrim($url, '/');
    }

    private function requestScheme(string $forwardedProto, array $httpsHeaders, string $host): string
    {
        $proto = trim($forwardedProto);
        if ($proto !== '') {
            $proto = strtolower(explode(',', $proto)[0]);
        }

        foreach ($httpsHeaders as $value) {
            if (in_array(strtolower(trim($value)), ['on', '1', 'https'], true)) {
                return 'https';
            }
        }

        if ($proto === 'https') {
            return 'https';
        }

        if ($proto === 'http' && $this->isLocalHost($host)) {
            return 'http';
        }

        return 'https';
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(explode(':', $host)[0]);
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }
}
