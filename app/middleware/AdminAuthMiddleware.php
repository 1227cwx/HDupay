<?php

namespace app\middleware;

use app\service\AdminAuthService;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $loggedIn = (new AdminAuthService())->check($request);
        } catch (Throwable) {
            return json(['code' => 1, 'msg' => '后台登录状态校验失败，请联系管理员', 'data' => null]);
        }

        if (!$loggedIn) {
            return json(['code' => 401, 'msg' => '请先登录后台', 'data' => null]);
        }

        return $handler($request);
    }
}
