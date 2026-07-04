<?php

namespace app\controller;

use support\Request;
use Throwable;

abstract class BaseController
{
    protected function input(Request $request): array
    {
        $raw = $request->rawBody();
        $json = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($json)) {
            return $json + $request->all();
        }
        return $request->all();
    }

    protected function ok(mixed $data = [], string $msg = '成功')
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    protected function fail(Throwable $e)
    {
        return json(['code' => 1, 'msg' => $this->errorMessage($e), 'data' => null]);
    }

    private function errorMessage(Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '' || preg_match('/\?{4,}/', $message)) {
            return '系统运行异常，请联系管理员';
        }
        if (
            str_starts_with($message, '汇率接口')
            || str_starts_with($message, '已选择代理模式')
            || str_starts_with($message, '当前只支持 CoinGecko')
            || str_starts_with($message, '当前只支持 USDC')
            || str_starts_with($message, '当前系统货币基准只支持')
            || str_starts_with($message, '当前 ')
            || str_starts_with($message, '不支持的法币')
            || str_starts_with($message, '法币金额过小')
            || str_starts_with($message, '系统基准货币')
            || str_starts_with($message, '上一轮汇率同步')
        ) {
            return $message;
        }
        if (preg_match('/SQLSTATE|database|PDO|Duplicate entry|Integrity constraint/i', $message)) {
            return '数据库操作失败，请检查提交内容或联系管理员';
        }
        if (preg_match('/cURL|timed out|Connection|Could not resolve|SSL|Client error|Server error/i', $message)) {
            return '外部接口请求失败，请检查接口配置或网络连接';
        }
        if (preg_match('/Class .* not found|Call to undefined|Undefined|TypeError|ArgumentCountError|Too few arguments|Too many arguments|Parse error|Fatal error|deprecated|deprecation/i', $message)) {
            return '系统组件异常，请联系管理员处理';
        }

        return $message;
    }
}
