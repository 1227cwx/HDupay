<?php

namespace app\controller;

use app\service\ProxyPoolService;
use support\Request;
use Throwable;

class AdminProxyController extends BaseController
{
    public function list(Request $request)
    {
        try {
            return $this->ok((new ProxyPoolService())->list());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function enabled(Request $request)
    {
        try {
            return $this->ok((new ProxyPoolService())->enabledList());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function save(Request $request)
    {
        try {
            return $this->ok((new ProxyPoolService())->save($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function delete(Request $request)
    {
        try {
            return $this->ok((new ProxyPoolService())->delete((int)$request->input('id', 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function toggle(Request $request)
    {
        try {
            return $this->ok((new ProxyPoolService())->toggle(
                (int)$request->input('id', 0),
                (string)$request->input('status', '')
            ));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function test(Request $request)
    {
        try {
            return $this->ok((new ProxyPoolService())->test(
                (int)$request->input('id', 0),
                (string)$request->input('test_url', '')
            ));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
