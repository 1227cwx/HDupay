<?php

namespace app\process;

use app\service\TaskQueueService;

class EvmTaskQueueWorker
{
    public function onWorkerStart(): void
    {
        (new TaskQueueService())->loop();
    }
}
