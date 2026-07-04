<?php

namespace app\process;

use app\service\WalletAssetService;

class WalletBalanceSyncWorker
{
    public function onWorkerStart(): void
    {
        (new WalletAssetService())->loop();
    }
}
