<?php

namespace app\controller;

use app\service\OpenApiService;
use support\Request;
use Throwable;

class OpenApiController extends BaseController
{
    public function networks(Request $request)
    {
        try {
            return $this->ok((new OpenApiService())->networks($request));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function createOrder(Request $request)
    {
        try {
            return $this->ok((new OpenApiService())->createOrder($request, $this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function orderStatus(Request $request)
    {
        try {
            return $this->ok((new OpenApiService())->orderStatus($request, $this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
