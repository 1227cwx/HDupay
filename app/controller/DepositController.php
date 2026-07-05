<?php

namespace app\controller;

use app\service\DepositService;
use support\Request;
use Throwable;

class DepositController extends BaseController
{
    public function create(Request $request)
    {
        try {
            $input = $this->input($request);
            $input['source'] = 'frontend';
            $input['source_ip'] = $request->getRealIp(false);
            $input['base_url'] = $this->publicBaseUrl($request);
            return $this->ok((new DepositService())->create($input));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function networks(Request $request)
    {
        try {
            return $this->ok((new DepositService())->networks());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function detail(Request $request)
    {
        try {
            return $this->ok((new DepositService())->detail((string)$request->input('order_no', '')));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function options(Request $request)
    {
        try {
            return $this->ok((new DepositService())->options());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function status(Request $request)
    {
        try {
            $input = $this->input($request);
            return $this->ok((new DepositService())->publicStatus(
                (string)($input['order_no'] ?? ''),
                !empty($input['allow_terminal']),
                $this->publicBaseUrl($request)
            ));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
