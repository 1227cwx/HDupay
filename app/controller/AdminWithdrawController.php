<?php

namespace app\controller;

use app\service\WithdrawalService;
use support\Request;
use Throwable;

class AdminWithdrawController extends BaseController
{
    public function config(Request $request)
    {
        try {
            return $this->ok((new WithdrawalService())->config());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function settings(Request $request)
    {
        try {
            return $this->ok((new WithdrawalService())->settings());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveSetting(Request $request)
    {
        try {
            return $this->ok((new WithdrawalService())->saveSetting($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveConfig(Request $request)
    {
        try {
            return $this->ok((new WithdrawalService())->saveConfig($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function list(Request $request)
    {
        try {
            $filters = array_filter([
                'network_code' => $request->input('network_code', ''),
                'status' => $request->input('status', ''),
            ], fn($v) => $v !== '');
            return $this->ok((new WithdrawalService())->list($filters, (int)$request->input('page', 1), (int)$request->input('per_page', 10)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function preview(Request $request)
    {
        try {
            return $this->ok((new WithdrawalService())->preview($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function create(Request $request)
    {
        try {
            return $this->ok((new WithdrawalService())->create($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function processAll(Request $request)
    {
        try {
            return $this->ok((new WithdrawalService())->processAll());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function processOne(Request $request)
    {
        try {
            $input = $this->input($request);
            return $this->ok((new WithdrawalService())->processSingle((int)($input['id'] ?? 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
