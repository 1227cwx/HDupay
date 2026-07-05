<?php

namespace app\service;

use app\model\PaymentAddress;
use RuntimeException;

class AddressPoolService
{
    public function allocate(string $networkCode, string $tokenCode, string $orderNo): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $address = PaymentAddress::findAvailableForUpdate($networkCode, $tokenCode);
            if (!$address) {
                $address = (new EvmWalletService())->createNextAddress($networkCode, $tokenCode);
            }
            if (PaymentAddress::assignToOrder((int)$address['id'], $orderNo)) {
                return PaymentAddress::findById((int)$address['id']) ?? $address;
            }
        }

        throw new RuntimeException('收款地址分配失败，请重试');
    }

}
