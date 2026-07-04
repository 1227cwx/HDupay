<?php

namespace app\model;

class AuditLog extends BaseModel
{
    protected $table = 'audit_logs';
    protected $primaryKey = 'id';

    public static function writeLog(string $action, string $summary, string $targetType = '', int $targetId = 0, int $adminId = 0, string $ip = ''): array
    {
        return self::createRecord([
            'admin_id' => $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => $summary,
            'ip' => $ip,
        ]);
    }
}
