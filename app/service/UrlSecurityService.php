<?php

namespace app\service;

use InvalidArgumentException;

class UrlSecurityService
{
    public function normalizeRequired(string $url, string $label): string
    {
        $url = $this->normalize($url, $label, true);
        if ($url === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }
        return $url;
    }

    public function normalizeOptional(string $url, string $label, bool $throw = false): string
    {
        try {
            return $this->normalize($url, $label, false);
        } catch (InvalidArgumentException $e) {
            if ($throw) {
                throw $e;
            }
            return '';
        }
    }

    private function normalize(string $url, string $label, bool $required): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strlen($url) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new InvalidArgumentException($label . '格式不正确');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException($label . '格式不正确');
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException($label . '必须是 http 或 https 地址');
        }

        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST)));
        if ($host === '') {
            throw new InvalidArgumentException($label . '缺少域名或 IP');
        }
        $this->assertPublicHost($host, $label);

        return $url;
    }

    private function assertPublicHost(string $host, string $label): void
    {
        $host = trim($host, "[] \t\n\r\0\x0B.");
        if ($host === '' || in_array($host, ['localhost'], true)) {
            throw new InvalidArgumentException($label . '不能使用本机或内网地址');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host)) {
                throw new InvalidArgumentException($label . '不能使用本机或内网地址');
            }
            return;
        }

        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException($label . '域名格式不正确');
        }

        $ips = $this->resolveHostIps($host);
        if (!$ips) {
            throw new InvalidArgumentException($label . '域名无法解析');
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new InvalidArgumentException($label . '解析到了本机或内网地址');
            }
        }
    }

    private function resolveHostIps(string $host): array
    {
        $ips = [];
        $aRecords = gethostbynamel($host);
        if (is_array($aRecords)) {
            $ips = array_merge($ips, $aRecords);
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = (string)$record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = (string)$record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ips, static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP))));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
