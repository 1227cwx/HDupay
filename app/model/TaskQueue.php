<?php

namespace app\model;

class TaskQueue extends BaseModel
{
    protected $table = 'task_queue';
    protected $primaryKey = 'id';
    protected static array $fields = [
        'queue_type',
        'business_id',
        'network_code',
        'token_code',
        'source',
        'process_status',
        'is_invalid',
        'next_run_at',
        'locked_at',
        'locked_by',
        'last_error',
        'created_at',
        'updated_at',
    ];

    protected static array $filterFields = [
        'queue_type',
        'business_id',
        'network_code',
        'token_code',
        'source',
        'process_status',
        'is_invalid',
    ];

    public const PROCESS_STATUSES = ['queued', 'processing', 'success', 'failed'];

    public static function enqueue(
        string $queueType,
        int $businessId,
        string $networkCode,
        string $tokenCode,
        string $source,
        bool $invalidateExisting = false,
        ?string $nextRunAt = null
    ): array {
        $queueType = strtolower(trim($queueType));
        $networkCode = strtolower(trim($networkCode));
        $tokenCode = strtoupper(trim($tokenCode));
        $source = $source === 'manual' ? 'manual' : 'auto';
        $nextRunAt = $nextRunAt ?: self::now();

        if (!self::acquireBusinessLock($queueType, $businessId)) {
            throw new \RuntimeException('Task queue is being updated, please retry later');
        }

        try {
            if ($invalidateExisting) {
                self::invalidateByBusiness($queueType, $businessId);
            } else {
                $active = self::activeByBusiness($queueType, $businessId);
                if ($active) {
                    return $active;
                }
            }

            return self::createRecord([
                'queue_type' => $queueType,
                'business_id' => $businessId,
                'network_code' => $networkCode,
                'token_code' => $tokenCode,
                'source' => $source,
                'process_status' => 'queued',
                'is_invalid' => 0,
                'next_run_at' => $nextRunAt,
                'locked_at' => null,
                'locked_by' => '',
                'last_error' => '',
            ]);
        } finally {
            self::releaseBusinessLock($queueType, $businessId);
        }
    }

    public static function activeByBusiness(string $queueType, int $businessId): ?array
    {
        $row = self::query()
            ->where('queue_type', strtolower(trim($queueType)))
            ->where('business_id', $businessId)
            ->where('is_invalid', 0)
            ->orderByDesc('id')
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function latestByBusinessIds(string $queueType, array $businessIds): array
    {
        $businessIds = array_values(array_unique(array_filter(array_map('intval', $businessIds))));
        if (!$businessIds) {
            return [];
        }

        $rows = self::query()
            ->where('queue_type', strtolower(trim($queueType)))
            ->whereIn('business_id', $businessIds)
            ->orderBy('business_id')
            ->orderByDesc('id')
            ->get()
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $businessId = (int)$row['business_id'];
            if (!isset($result[$businessId])) {
                $result[$businessId] = $row;
            }
        }
        return $result;
    }

    public static function invalidateByBusiness(string $queueType, int $businessId): int
    {
        return self::query()
            ->where('queue_type', strtolower(trim($queueType)))
            ->where('business_id', $businessId)
            ->where('is_invalid', 0)
            ->update([
                'is_invalid' => 1,
                'locked_at' => null,
                'locked_by' => '',
                'updated_at' => self::now(),
            ]);
    }

    public static function claimNext(string $queueType, string $workerId): ?array
    {
        $queueType = strtolower(trim($queueType));
        return self::transaction(function () use ($queueType, $workerId) {
            $now = self::now();
            $row = self::query()
                ->where('queue_type', $queueType)
                ->where('is_invalid', 0)
                ->whereIn('process_status', ['queued', 'processing'])
                ->where(function ($query) use ($now) {
                    $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
                })
                ->where(function ($query) {
                    $query->where('process_status', 'queued')
                        ->orWhere(function ($subQuery) {
                            $subQuery->where('process_status', 'processing')
                                ->where(function ($lockQuery) {
                                    $lockQuery->whereNull('locked_at')->orWhere('locked_by', '');
                                });
                        });
                })
                ->whereNotExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('task_queue as earlier')
                        ->whereColumn('earlier.network_code', 'task_queue.network_code')
                        ->whereColumn('earlier.id', '<', 'task_queue.id')
                        ->where('earlier.is_invalid', 0)
                        ->whereIn('earlier.process_status', ['queued', 'processing']);
                })
                ->whereNotExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('task_queue as locked_queue')
                        ->whereColumn('locked_queue.network_code', 'task_queue.network_code')
                        ->whereColumn('locked_queue.id', '<>', 'task_queue.id')
                        ->where('locked_queue.is_invalid', 0)
                        ->where('locked_queue.process_status', 'processing')
                        ->whereNotNull('locked_queue.locked_at')
                        ->where('locked_queue.locked_by', '<>', '');
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return null;
            }

            $id = (int)$row->id;
            $updated = self::query()
                ->where('id', $id)
                ->where('is_invalid', 0)
                ->whereIn('process_status', ['queued', 'processing'])
                ->where(function ($query) use ($now) {
                    $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
                })
                ->where(function ($query) {
                    $query->where('process_status', 'queued')
                        ->orWhere(function ($subQuery) {
                            $subQuery->where('process_status', 'processing')
                                ->where(function ($lockQuery) {
                                    $lockQuery->whereNull('locked_at')->orWhere('locked_by', '');
                                });
                        });
                })
                ->update([
                    'process_status' => 'processing',
                    'locked_at' => $now,
                    'locked_by' => $workerId,
                    'updated_at' => $now,
                ]);

            return $updated > 0 ? self::findById($id) : null;
        });
    }

    public static function hasEarlierActive(string $networkCode, int $id): bool
    {
        return self::query()
            ->where('network_code', strtolower($networkCode))
            ->where('id', '<', $id)
            ->where('is_invalid', 0)
            ->whereIn('process_status', ['queued', 'processing'])
            ->exists();
    }

    public static function hasOtherLockedProcessing(string $networkCode, int $id): bool
    {
        return self::query()
            ->where('network_code', strtolower($networkCode))
            ->where('id', '<>', $id)
            ->where('is_invalid', 0)
            ->where('process_status', 'processing')
            ->whereNotNull('locked_at')
            ->where('locked_by', '<>', '')
            ->exists();
    }

    public static function markProcessing(int $id, ?string $nextRunAt = null, string $lastError = ''): bool
    {
        return self::query()
            ->where('id', $id)
            ->where('is_invalid', 0)
            ->update([
                'process_status' => 'processing',
                'next_run_at' => $nextRunAt ?: self::now(),
                'locked_at' => null,
                'locked_by' => '',
                'last_error' => $lastError,
                'updated_at' => self::now(),
            ]) > 0;
    }

    public static function markSuccess(int $id): bool
    {
        return self::query()
            ->where('id', $id)
            ->where('is_invalid', 0)
            ->update([
                'process_status' => 'success',
                'is_invalid' => 1,
                'next_run_at' => null,
                'locked_at' => null,
                'locked_by' => '',
                'last_error' => '',
                'updated_at' => self::now(),
            ]) > 0;
    }

    public static function markFailed(int $id, string $error = ''): bool
    {
        return self::query()
            ->where('id', $id)
            ->where('is_invalid', 0)
            ->update([
                'process_status' => 'failed',
                'is_invalid' => 1,
                'next_run_at' => null,
                'locked_at' => null,
                'locked_by' => '',
                'last_error' => $error,
                'updated_at' => self::now(),
            ]) > 0;
    }

    public static function lockedProcessingList(int $limit = 100): array
    {
        return self::query()
            ->where('is_invalid', 0)
            ->where('process_status', 'processing')
            ->whereNotNull('locked_at')
            ->where('locked_by', '<>', '')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }
    private static function acquireBusinessLock(string $queueType, int $businessId): bool
    {
        $row = self::query()
            ->getConnection()
            ->selectOne('SELECT GET_LOCK(?, 5) AS locked', [self::businessLockName($queueType, $businessId)]);
        return (int)self::dbValue($row, 'locked') === 1;
    }

    private static function releaseBusinessLock(string $queueType, int $businessId): void
    {
        try {
            self::query()
                ->getConnection()
                ->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::businessLockName($queueType, $businessId)]);
        } catch (\Throwable) {
        }
    }

    private static function businessLockName(string $queueType, int $businessId): string
    {
        return 'hdupay:task-queue:' . sha1(strtolower($queueType) . ':' . $businessId);
    }

    private static function dbValue(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }
        if (is_object($row)) {
            return $row->{$key} ?? null;
        }
        return null;
    }

}
