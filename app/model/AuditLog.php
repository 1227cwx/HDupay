<?php

namespace app\model;

class AuditLog extends BaseModel
{
    protected $table = 'audit_logs';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'admin_id',
        'action',
        'target_type',
        'target_id',
        'summary',
        'ip',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'admin_id',
        'action',
        'target_type',
        'target_id',
        'ip',
    ];

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
