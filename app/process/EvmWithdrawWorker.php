<?php

namespace app\process;

use app\service\WithdrawalService;

class EvmWithdrawWorker
{
    public function onWorkerStart(): void
    {
        (new WithdrawalService())->loop();
    }
}
