<?php

namespace app\middleware;

use app\service\AdminDomainAccessService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class PublicDomainMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!(new AdminDomainAccessService())->isPublicAllowed($request)) {
            return response('Not Found', 404);
        }

        return $handler($request);
    }
}
