<?php

namespace app\controller;

use app\service\AdminAuthService;
use support\Request;
use Throwable;

class AdminAuthController extends BaseController
{
    public function login(Request $request)
    {
        try {
            return $this->ok((new AdminAuthService())->login($this->input($request), $request), '登录成功');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function logout(Request $request)
    {
        try {
            return $this->ok((new AdminAuthService())->logout($request), '已退出登录');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function me(Request $request)
    {
        try {
            $admin = (new AdminAuthService())->current($request);
            if (!$admin) {
                return json(['code' => 401, 'msg' => '请先登录后台', 'data' => null]);
            }
            return $this->ok($admin);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            return $this->ok((new AdminAuthService())->updateProfile($this->input($request), $request), '管理员信息已保存');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function updatePassword(Request $request)
    {
        try {
            return $this->ok((new AdminAuthService())->updatePassword($this->input($request), $request), '管理员密码已修改');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
