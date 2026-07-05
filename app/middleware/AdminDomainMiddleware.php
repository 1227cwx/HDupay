<?php

namespace app\middleware;

use app\service\AdminDomainAccessService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AdminDomainMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!(new AdminDomainAccessService())->isAdminAllowed($this->requestHost($request))) {
            return response('Not Found', 404);
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
