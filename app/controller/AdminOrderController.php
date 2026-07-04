<?php

namespace app\controller;

use app\service\DepositService;
use app\service\OpenApiService;
use support\Request;
use Throwable;

class AdminOrderController extends BaseController
{
    public function networks(Request $request)
    {
        try {
            return $this->ok((new DepositService())->networks());
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

    public function list(Request $request)
    {
        try {
            $filters = array_filter([
                'keyword' => $request->input('keyword', ''),
                'network_code' => $request->input('network_code', ''),
                'status' => $request->input('status', ''),
                'source' => $request->input('source', ''),
                'fiat_currency' => $request->input('fiat_currency', ''),
            ], fn($v) => $v !== '');
            return $this->ok((new DepositService())->list($filters, (int)$request->input('page', 1), (int)$request->input('per_page', 10)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function create(Request $request)
    {
        try {
            $input = $this->input($request);
            $input['source'] = 'admin';
            $input['source_ip'] = $request->getRealIp(false);
            return $this->ok((new DepositService())->create($input), '订单创建成功');
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

    public function callback(Request $request)
    {
        try {
            $input = $this->input($request);
            return $this->ok((new OpenApiService())->callbackOrder((string)($input['order_no'] ?? '')), '回调已提交');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
