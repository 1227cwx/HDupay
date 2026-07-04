<?php

namespace app\service;

class EasyPaySignatureService
{
    public function sign(array $params, string $secret): string
    {
        $items = $this->filter($params);
        ksort($items, SORT_STRING);
        $pairs = [];
        foreach ($items as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        return md5(implode('&', $pairs) . $secret);
    }

    public function verify(array $params, string $secret): bool
    {
        $sign = strtolower(trim((string)($params['sign'] ?? '')));
        if ($sign === '') {
            return false;
        }
        return hash_equals($sign, $this->sign($params, $secret));
    }

    public function signedParams(array $params, string $secret): array
    {
        $params['sign'] = $this->sign($params, $secret);
        $params['sign_type'] = 'MD5';
        return $params;
    }

    private function filter(array $params): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            $key = (string)$key;
            if ($key === 'sign' || $key === 'sign_type') {
                continue;
            }
            $value = is_scalar($value) ? trim((string)$value) : '';
            if ($value === '') {
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
