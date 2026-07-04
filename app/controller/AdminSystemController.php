<?php

namespace app\controller;

use app\service\SystemSettingsService;
use support\Request;
use Throwable;

class AdminSystemController extends BaseController
{
    public function settings(Request $request)
    {
        try {
            return $this->ok((new SystemSettingsService())->settings());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveFiatRate(Request $request)
    {
        try {
            return $this->ok((new SystemSettingsService())->saveFiatRate($this->input($request)), '汇率设置已保存');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveSite(Request $request)
    {
        try {
            return $this->ok((new SystemSettingsService())->saveSite($this->input($request)), '站点设置已保存');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function testFiatRate(Request $request)
    {
        try {
            return $this->ok((new SystemSettingsService())->testFiatRate($this->input($request)), '汇率接口测试成功');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function refreshFiatRate(Request $request)
    {
        try {
            return $this->ok((new SystemSettingsService())->refreshFiatRate(), '汇率同步成功');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function toggleFiatCurrency(Request $request)
    {
        try {
            return $this->ok((new SystemSettingsService())->toggleFiatCurrency($this->input($request)), '法币同步状态已更新');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
