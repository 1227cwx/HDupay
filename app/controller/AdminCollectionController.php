<?php

namespace app\controller;

use app\service\CollectionService;
use support\Request;
use Throwable;

class AdminCollectionController extends BaseController
{
    public function config(Request $request)
    {
        try {
            return $this->ok((new CollectionService())->config());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveConfig(Request $request)
    {
        try {
            return $this->ok((new CollectionService())->saveConfig($this->input($request)));
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
            return $this->ok((new CollectionService())->list($filters, (int)$request->input('page', 1), (int)$request->input('per_page', 10)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function retry(Request $request)
    {
        try {
            return $this->ok(['success' => (new CollectionService())->retry((int)$request->input('id', 0))]);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function manualCreate(Request $request)
    {
        try {
            $input = $this->input($request);
            return $this->ok((new CollectionService())->manualCreate((int)($input['address_id'] ?? 0), (string)($input['amount_int'] ?? '')));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function process(Request $request)
    {
        try {
            return $this->ok((new CollectionService())->processPending((int)$request->input('limit', 10)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function processAll(Request $request)
    {
        try {
            return $this->ok((new CollectionService())->processAll());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function processOne(Request $request)
    {
        try {
            $input = $this->input($request);
            return $this->ok((new CollectionService())->processSingle((int)($input['id'] ?? 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
