<?php

namespace app\controller;

use support\Request;

class IndexController
{
    public function index(Request $request)
    {
        $indexFile = public_path('index.html');
        if (!is_file($indexFile)) {
            return response('前端入口文件不存在', 500);
        }

        return response(file_get_contents($indexFile), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    public function view(Request $request)
    {
        return view('index/view', ['name' => 'webman']);
    }

    public function json(Request $request)
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }

}
