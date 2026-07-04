<?php

namespace app\service;

use app\model\CollectionTask;
use app\model\DepositOrder;
use app\model\PaymentAddress;
use app\model\RpcNetworkSetting;

class DashboardService
{
    public function summary(): array
    {
        (new DepositOrderSchemaService())->ensure();
        return [
            'rpc_enabled' => count(RpcNetworkSetting::enabledList()),
            'orders' => DepositOrder::countAll(),
            'confirmed_orders' => DepositOrder::countByField('status', 'success'),
            'addresses' => PaymentAddress::countAll(),
            'collection_tasks' => CollectionTask::countAll(),
        ];
    }
}
