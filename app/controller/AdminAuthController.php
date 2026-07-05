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
            $admin = (new AdminAuthService())->login($this->input($request), $request->getRealIp(false));
            $request->session()->set('admin_user_id', (int)$admin['id']);
            $request->session()->set('admin_username', (string)$admin['username']);
            return $this->ok($admin, '登录成功');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->session()->delete('admin_user_id');
            $request->session()->delete('admin_username');
            return $this->ok(true, '已退出登录');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function me(Request $request)
    {
        try {
            $admin = (new AdminAuthService())->current((int)$request->session()->get('admin_user_id', 0));
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
            $admin = (new AdminAuthService())->updateProfile($this->input($request), (int)$request->session()->get('admin_user_id', 0));
            $request->session()->set('admin_username', (string)($admin['username'] ?? ''));
            return $this->ok($admin, '管理员信息已保存');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function updatePassword(Request $request)
    {
        try {
            return $this->ok((new AdminAuthService())->updatePassword($this->input($request), (int)$request->session()->get('admin_user_id', 0)), '管理员密码已修改');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }
}
