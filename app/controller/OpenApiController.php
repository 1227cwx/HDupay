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
            return $this->ok((new OpenApiService())->networks($this->apiInput($request), $request->getRealIp(false)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function createOrder(Request $request)
    {
        try {
            return $this->ok((new OpenApiService())->createOrder($this->apiInput($request), $request->getRealIp(false), $this->publicBaseUrl($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function orderStatus(Request $request)
    {
        try {
            return $this->ok((new OpenApiService())->orderStatus($this->apiInput($request), $request->getRealIp(false), $this->publicBaseUrl($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    private function apiInput(Request $request): array
    {
        $input = $this->input($request);
        $headerApiKey = trim((string)$request->header('x-api-key'));
        $headerApiSecret = trim((string)$request->header('x-api-secret'));
        if ($headerApiKey !== '') {
            $input['api_key'] = $headerApiKey;
        }
        if ($headerApiSecret !== '') {
            $input['api_secret'] = $headerApiSecret;
        }
        return $input;
    }
}
