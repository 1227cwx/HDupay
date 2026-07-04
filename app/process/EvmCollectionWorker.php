<?php

namespace app\process;

use app\service\CollectionService;

class EvmCollectionWorker
{
    public function onWorkerStart(): void
    {
        (new CollectionService())->loop();
    }
}
