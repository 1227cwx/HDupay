<?php

namespace app\controller;

use app\service\RpcConfigService;
use support\Request;
use Throwable;

class AdminRpcConfigController extends BaseController
{
    public function list(Request $request)
    {
        try {
            return $this->ok((new RpcConfigService())->list());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveNetwork(Request $request)
    {
        try {
            return $this->ok((new RpcConfigService())->saveNetwork($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveGroup(Request $request)
    {
        try {
            return $this->ok((new RpcConfigService())->saveGroup($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function deleteGroup(Request $request)
    {
        try {
            return $this->ok(['deleted' => (new RpcConfigService())->deleteGroup((int)$request->input('id', 0))]);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveNode(Request $request)
    {
        try {
            return $this->ok((new RpcConfigService())->saveNode($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function toggleNode(Request $request)
    {
        try {
            return $this->ok((new RpcConfigService())->toggleNode((int)$request->input('id', 0), (bool)$request->input('enabled', false)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function deleteNode(Request $request)
    {
        try {
            return $this->ok(['deleted' => (new RpcConfigService())->deleteNode((int)$request->input('id', 0))]);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function test(Request $request)
    {
        try {
            return $this->rpcTestResult((new RpcConfigService())->test((string)$request->input('network_code', '')));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function testNode(Request $request)
    {
        try {
            return $this->rpcTestResult((new RpcConfigService())->testNode((int)$request->input('id', 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function testGroup(Request $request)
    {
        try {
            return $this->rpcTestResult((new RpcConfigService())->testGroup((int)$request->input('id', 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    private function rpcTestResult(array $result)
    {
        if (($result['ok'] ?? false) !== true) {
            return json([
                'code' => 1,
                'msg' => (string)($result['error'] ?? 'RPC 测试失败'),
                'data' => $result,
            ]);
        }

        return $this->ok($result);
    }
}
