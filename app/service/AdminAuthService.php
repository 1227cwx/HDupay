<?php

namespace app\service;

use app\model\AdminLoginAttempt;
use app\model\AdminUser;
use InvalidArgumentException;
use Webman\Http\Request;

class AdminAuthService
{
    private const FAILURE_WINDOW_SECONDS = 300;
    private const LOCK_SECONDS = 600;
    private const MAX_FAILURES = 5;

    public function login(array $input, Request $request): array
    {
        (new AdminLoginAttemptSchemaService())->ensure();

        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('请输入管理员账号和密码');
        }

        $ip = $request->getRealIp(false);
        $rateKey = strtolower($username);
        $this->assertNotLocked($rateKey, $ip);

        $admin = AdminUser::findByUsername($username);
        if (!$admin || $admin['status'] !== 'active' || !password_verify($password, (string)$admin['password_hash'])) {
            $this->recordLoginFailure($rateKey, $ip);
            throw new InvalidArgumentException('管理员账号或密码错误');
        }

        AdminLoginAttempt::clearByUsernameIp($rateKey, $ip);

        $session = $request->session();
        $session->set('admin_user_id', (int)$admin['id']);
        $session->set('admin_username', $admin['username']);
        AdminUser::markLogin((int)$admin['id']);

        return $this->safeAdmin($admin);
    }

    public function logout(Request $request): bool
    {
        $session = $request->session();
        $session->delete('admin_user_id');
        $session->delete('admin_username');
        return true;
    }

    public function current(Request $request): array
    {
        $adminId = (int)$request->session()->get('admin_user_id', 0);
        if ($adminId <= 0) {
            return [];
        }
        $admin = AdminUser::findActiveById($adminId);
        return $admin ? $this->safeAdmin($admin) : [];
    }

    public function check(Request $request): bool
    {
        return $this->current($request) !== [];
    }

    public function updateProfile(array $input, Request $request): array
    {
        $admin = $this->requireCurrentAdmin($request);
        $adminId = (int)$admin['id'];
        $username = trim((string)($input['username'] ?? ''));
        $nickname = trim((string)($input['nickname'] ?? ''));

        if ($username === '') {
            throw new InvalidArgumentException('请输入管理员账号');
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,64}$/', $username)) {
            throw new InvalidArgumentException('管理员账号只能使用 3-64 位字母、数字或下划线');
        }
        $nicknameLength = function_exists('mb_strlen') ? mb_strlen($nickname) : strlen($nickname);
        if ($nicknameLength > 128) {
            throw new InvalidArgumentException('管理员昵称不能超过 128 个字符');
        }
        if (AdminUser::findByUsernameExceptId($username, $adminId)) {
            throw new InvalidArgumentException('管理员账号已存在');
        }

        AdminUser::updateProfile($adminId, $username, $nickname);
        $request->session()->set('admin_username', $username);

        $updated = AdminUser::findActiveById($adminId);
        return $updated ? $this->safeAdmin($updated) : [];
    }

    public function updatePassword(array $input, Request $request): bool
    {
        $admin = $this->requireCurrentAdmin($request);
        $oldPassword = (string)($input['old_password'] ?? '');
        $newPassword = (string)($input['new_password'] ?? '');
        $confirmPassword = (string)($input['confirm_password'] ?? '');

        if ($oldPassword === '') {
            throw new InvalidArgumentException('请输入原密码');
        }
        if (!password_verify($oldPassword, (string)$admin['password_hash'])) {
            throw new InvalidArgumentException('原密码错误');
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            throw new InvalidArgumentException('新密码长度必须为 8-72 个字符');
        }
        if ($newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('两次输入的新密码不一致');
        }
        if ($oldPassword === $newPassword) {
            throw new InvalidArgumentException('新密码不能和原密码相同');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        return AdminUser::updatePasswordHash((int)$admin['id'], $hash);
    }

    private function requireCurrentAdmin(Request $request): array
    {
        $adminId = (int)$request->session()->get('admin_user_id', 0);
        if ($adminId <= 0) {
            throw new InvalidArgumentException('请先登录后台');
        }
        $admin = AdminUser::findActiveById($adminId);
        if (!$admin) {
            throw new InvalidArgumentException('管理员账号不存在或已停用');
        }
        return $admin;
    }

    private function safeAdmin(array $admin): array
    {
        unset($admin['password_hash']);
        return $admin;
    }

    private function assertNotLocked(string $username, string $ip): void
    {
        $attempt = AdminLoginAttempt::findByUsernameIp($username, $ip);
        if (!$attempt) {
            return;
        }

        $lockedUntil = strtotime((string)($attempt['locked_until'] ?? ''));
        if ($lockedUntil !== false && $lockedUntil > time()) {
            throw new InvalidArgumentException('登录失败次数过多，请稍后再试');
        }
    }

    private function recordLoginFailure(string $username, string $ip): void
    {
        $attempt = AdminLoginAttempt::findByUsernameIp($username, $ip);
        $lastFailedAt = $attempt ? strtotime((string)($attempt['last_failed_at'] ?? '')) : false;
        $failedCount = 1;
        if ($attempt && $lastFailedAt !== false && $lastFailedAt >= time() - self::FAILURE_WINDOW_SECONDS) {
            $failedCount = (int)($attempt['failed_count'] ?? 0) + 1;
        }

        $lockedUntil = '';
        if ($failedCount >= self::MAX_FAILURES) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_SECONDS);
        }

        AdminLoginAttempt::saveFailure($username, $ip, $failedCount, $lockedUntil);
    }
}
