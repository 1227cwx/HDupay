<?php

namespace app\middleware;

use app\service\AdminAuthService;
use app\service\AdminDomainAccessService;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!(new AdminDomainAccessService())->isAdminAllowed($this->requestHost($request))) {
            return response('Not Found', 404);
        }

        try {
            $loggedIn = (new AdminAuthService())->check((int)$request->session()->get('admin_user_id', 0));
        } catch (Throwable) {
            return json(['code' => 1, 'msg' => '后台登录状态校验失败，请联系管理员', 'data' => null]);
        }

        if (!$loggedIn) {
            return json(['code' => 401, 'msg' => '请先登录后台', 'data' => null]);
        }

        return $handler($request);
    }

    private function requestHost(Request $request): string
    {
        $host = trim((string)$request->header('host'));
        if ($host === '') {
            $host = trim((string)$request->header('x-forwarded-host'));
        }
        return $host;
    }
}
