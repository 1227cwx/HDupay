<?php

namespace app\controller;

use app\service\OpenApiService;
use support\Request;
use Throwable;

class AdminOpenApiController extends BaseController
{
    public function list(Request $request)
    {
        try {
            $filters = [
                'keyword' => (string)$request->input('keyword', ''),
                'status' => (string)$request->input('status', ''),
            ];
            return $this->ok((new OpenApiService())->list(
                $filters,
                (int)$request->input('page', 1),
                (int)$request->input('per_page', 10)
            ));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function save(Request $request)
    {
        try {
            return $this->ok((new OpenApiService())->save($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function toggle(Request $request)
    {
        try {
            return $this->ok((new OpenApiService())->toggle(
                (int)$request->input('id', 0),
                (string)$request->input('status', '')
            ));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function delete(Request $request)
    {
        try {
            return $this->ok(['deleted' => (new OpenApiService())->delete((int)$request->input('id', 0))]);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function resetSecret(Request $request)
    {
        try {
            return $this->ok((new OpenApiService())->resetSecret((int)$request->input('id', 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
