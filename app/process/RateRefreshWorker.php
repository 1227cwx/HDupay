<?php

namespace app\process;

use app\service\FiatRateService;

class RateRefreshWorker
{
    public function onWorkerStart(): void
    {
        (new FiatRateService())->loop();
    }
}
