<?php

namespace app\controller;

use app\service\DashboardService;
use support\Request;
use Throwable;

class AdminDashboardController extends BaseController
{
    public function summary(Request $request)
    {
        try {
            return $this->ok((new DashboardService())->summary());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
