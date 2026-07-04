<?php

namespace app\service;

use app\model\PaymentAddress;

class AdminListService
{
    public function addresses(array $filters, int $page, int $perPage): array
    {
        return PaymentAddress::listPage($filters, $page, $perPage);
    }
}
