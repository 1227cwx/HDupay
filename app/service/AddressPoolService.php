<?php

namespace app\service;

use app\model\PaymentAddress;

class AddressPoolService
{
    public function allocate(string $networkCode, string $tokenCode, string $orderNo): array
    {
        $address = PaymentAddress::findAvailable($networkCode, $tokenCode);
        if (!$address) {
            $address = (new EvmWalletService())->createNextAddress($networkCode, $tokenCode);
        }
        PaymentAddress::assignToOrder((int)$address['id'], $orderNo);
        return PaymentAddress::findById((int)$address['id']) ?? $address;
    }

}
