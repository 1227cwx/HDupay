<?php

namespace app\controller;

use app\service\AdminListService;
use support\Request;
use Throwable;

class AdminAddressController extends BaseController
{
    public function list(Request $request)
    {
        try {
            $filters = array_filter([
                'network_code' => $request->input('network_code', ''),
                'status' => $request->input('status', ''),
            ], fn($v) => $v !== '');
            return $this->ok((new AdminListService())->addresses($filters, (int)$request->input('page', 1), (int)$request->input('per_page', 10)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
