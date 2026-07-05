<?php

namespace app\controller;

use app\service\EasyPayService;
use support\Request;
use support\Response;
use Throwable;

class EasyPayController extends BaseController
{
    public function submit(Request $request): Response
    {
        try {
            $url = (new EasyPayService())->submit($request->all(), $request->getRealIp(false), $this->publicBaseUrl($request));
            return new Response(302, ['Location' => $url], '');
        } catch (Throwable $e) {
            return new Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], $e->getMessage());
        }
    }

    public function detail(Request $request)
    {
        try {
            return $this->ok((new EasyPayService())->publicDetail((string)$request->input('epay_order_no', '')));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
