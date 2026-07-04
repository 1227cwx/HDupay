<?php

namespace app\service;

use app\model\SystemSetting;
use InvalidArgumentException;
use Webman\Http\Request;

class AdminDomainAccessService
{
    private const SETTING_KEY = 'site_admin_allowed_domain';
    private const PUBLIC_BASE_URL_KEY = 'site_public_base_url';

    public function allowedDomain(): string
    {
        return $this->normalizeDomain(SystemSetting::getValue(self::SETTING_KEY, ''));
    }

    public function publicDomain(): string
    {
        return $this->normalizeDomain(SystemSetting::getValue(self::PUBLIC_BASE_URL_KEY, ''));
    }

    public function isAdminAllowed(Request $request): bool
    {
        $allowedDomain = $this->allowedDomain();
        if ($allowedDomain === '') {
            return true;
        }

        $requestHost = $this->requestHost($request);
        return $requestHost !== '' && hash_equals($allowedDomain, $requestHost);
    }

    public function isPublicAllowed(Request $request): bool
    {
        $allowedDomain = $this->allowedDomain();
        if ($allowedDomain === '') {
            return true;
        }

        $requestHost = $this->requestHost($request);
        if ($requestHost === '' || hash_equals($allowedDomain, $requestHost)) {
            return false;
        }

        $publicDomain = $this->publicDomain();
        if ($publicDomain === '') {
            return true;
        }

        return hash_equals($publicDomain, $requestHost);
    }

    public function save(string $domain, ?string $publicBaseUrl = null): string
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain !== '' && !$this->isValidDomain($domain)) {
            throw new InvalidArgumentException('后台访问域名格式不正确');
        }

        $publicDomain = $publicBaseUrl === null ? $this->publicDomain() : $this->normalizeDomain($publicBaseUrl);
        if ($domain !== '' && $publicDomain !== '' && hash_equals($domain, $publicDomain)) {
            throw new InvalidArgumentException('后台访问域名不能和公开访问地址使用同一个域名');
        }

        SystemSetting::saveValue(self::SETTING_KEY, $domain);
        return $domain;
    }

    private function requestHost(Request $request): string
    {
        $host = trim((string)$request->header('host'));
        if ($host === '') {
            $host = trim((string)$request->header('x-forwarded-host'));
        }
        return $this->normalizeDomain($host);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }

        $domain = trim(explode(',', $domain)[0]);
        if (str_contains($domain, '://')) {
            $host = parse_url($domain, PHP_URL_HOST);
            $domain = is_string($host) ? $host : '';
        }

        $domain = preg_replace('/[\/?#].*$/', '', $domain) ?? '';
        if (str_contains($domain, '@')) {
            $parts = explode('@', $domain);
            $domain = (string)end($parts);
        }

        if (str_starts_with($domain, '[')) {
            $end = strpos($domain, ']');
            $domain = $end === false ? '' : substr($domain, 1, $end - 1);
        } elseif (substr_count($domain, ':') === 1) {
            $domain = explode(':', $domain)[0];
        }

        return trim($domain, ". \t\n\r\0\x0B");
    }

    private function isValidDomain(string $domain): bool
    {
        if (in_array($domain, ['localhost'], true)) {
            return true;
        }

        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool)filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }
}
