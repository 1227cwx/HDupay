<?php

namespace app\process;

use app\service\EvmMonitorService;

class EvmDepositMonitor
{
    public function onWorkerStart(): void
    {
        (new EvmMonitorService())->loop();
    }
}
