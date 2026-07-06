<?php

namespace app\service;

use app\model\CollectionTask;
use app\model\PaymentAddress;
use app\model\SystemSetting;
use app\model\TaskQueue;
use app\model\WalletAccount;
use RuntimeException;
use support\Log;
use Throwable;

class TaskQueueService
{
    private int $lastCollectionRunAt = 0;

    public function enqueueCollection(int|array $task, string $source = 'auto'): array
    {
        $task = is_array($task) ? $task : (CollectionTask::findById($task) ?: []);
        if (!$task) {
            throw new RuntimeException('Collection task does not exist');
        }
        if ((string)($task['status'] ?? '') === 'collected') {
            throw new RuntimeException('Collection task already completed');
        }

        $taskId = (int)$task['id'];
        $manual = $source === 'manual';
        $active = TaskQueue::activeByBusiness('collection', $taskId);
        if ($active) {
            CollectionTask::markQueueActive($taskId, true);
            if ($manual) {
                throw new RuntimeException('Collection task is already in queue');
            }
            return $active;
        }

        if ($manual) {
            if ((int)($task['queue_active'] ?? 0) === 1) {
                throw new RuntimeException('Collection task is running or waiting in queue');
            }
            CollectionTask::resetForManualQueue($taskId);
            $task = CollectionTask::findById($taskId) ?: $task;
        }

        try {
            $queue = TaskQueue::enqueue(
                'collection',
                $taskId,
                (string)$task['network_code'],
                (string)$task['token_code'],
                $source,
                false,
                date('Y-m-d H:i:s')
            );
            if (empty($queue['id'])) {
                throw new RuntimeException('Collection queue creation failed');
            }
        } catch (Throwable $e) {
            CollectionTask::markQueueActive($taskId, false);
            throw $e;
        }

        CollectionTask::markQueueActive($taskId, true);
        return $queue;
    }

    public function enqueueCollectionPending(int $limit = 0, string $source = 'auto'): array
    {
        $tasks = $source === 'manual'
            ? CollectionTask::findManualQueueable()
            : CollectionTask::findAutoProcessable($limit, $this->collectionMaxRetryCount());
        return $this->enqueueCollectionBatch($tasks, $source, $limit);
    }

    private function enqueueCollectionBatch(array $tasks, string $source, int $limit = 0): array
    {
        $stats = $this->emptyStats();
        foreach ($tasks as $task) {
            if ($limit > 0 && $stats['created'] >= $limit) {
                break;
            }
            try {
                if ($this->shouldSkipCollectionQueue($task, $source)) {
                    $stats['skipped']++;
                    continue;
                }
                $stats['items'][] = $this->enqueueCollection($task, $source);
                $stats['created']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = [
                    'id' => (int)($task['id'] ?? 0),
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $stats;
    }

    private function shouldSkipCollectionQueue(array $task, string $source): bool
    {
        $taskId = (int)($task['id'] ?? 0);
        $status = (string)($task['status'] ?? '');
        if ($taskId <= 0 || $status === 'collected') {
            return true;
        }
        if (TaskQueue::activeByBusiness('collection', $taskId)) {
            return true;
        }
        if ($source === 'manual') {
            return (int)($task['queue_active'] ?? 0) === 1;
        }
        if (in_array($status, CollectionTask::RETRY_STATUSES, true)) {
            return (int)($task['retry_count'] ?? 0) >= $this->collectionMaxRetryCount();
        }
        return false;
    }

    public function processNext(string $queueType = 'collection'): ?array
    {
        $this->recoverStaleProcessingQueues();
        $workerId = gethostname() . ':' . getmypid();
        $queue = TaskQueue::claimNext('collection', $workerId);
        if (!$queue) {
            return null;
        }
        return $this->processQueue($queue);
    }

    public function processDue(int $limit = 10): array
    {
        $results = [];
        for ($i = 0; $i < max(1, $limit); $i++) {
            $collection = $this->processNext('collection');
            if ($collection) {
                $results[] = $collection;
                continue;
            }
            break;
        }
        return $results;
    }

    private function processQueue(array $queue): array
    {
        $queueId = (int)$queue['id'];
        $type = (string)$queue['queue_type'];
        $businessId = (int)$queue['business_id'];
        $manual = (string)($queue['source'] ?? '') === 'manual';

        try {
            if ($type !== 'collection') {
                TaskQueue::markFailed($queueId, 'Unknown queue type: ' . $type);
                return ['queue_id' => $queueId, 'ok' => false, 'error' => 'unknown_type'];
            }
            if (!CollectionTask::findById($businessId)) {
                TaskQueue::markFailed($queueId, 'Collection task does not exist');
                return ['queue_id' => $queueId, 'ok' => false, 'error' => 'task_missing'];
            }
            (new CollectionService())->executeQueuedTask($businessId, $manual);
            return $this->finishCollectionQueue($queue);
        } catch (Throwable $e) {
            TaskQueue::markProcessing($queueId, $this->afterSeconds($this->collectionIntervalSeconds()), $e->getMessage());
            return ['queue_id' => $queueId, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function finishCollectionQueue(array $queue): array
    {
        $queueId = (int)$queue['id'];
        $task = CollectionTask::findById((int)$queue['business_id']);
        if (!$task) {
            TaskQueue::markFailed($queueId, 'Collection task does not exist');
            return ['queue_id' => $queueId, 'ok' => false, 'error' => 'task_missing'];
        }

        $status = (string)($task['status'] ?? '');
        if ($status === 'collected') {
            if (TaskQueue::markSuccess($queueId)) {
                CollectionTask::markQueueActive((int)$task['id'], false);
            }
        } elseif (in_array($status, ['gas_funding', 'collecting', 'pending_collect', 'processing'], true)) {
            if (TaskQueue::markProcessing($queueId, $this->afterSeconds($this->collectionIntervalSeconds()))) {
                CollectionTask::markQueueActive((int)$task['id'], true);
            }
        } elseif (in_array($status, CollectionTask::RETRY_STATUSES, true)) {
            $retryCount = (int)($task['retry_count'] ?? 0);
            if ($retryCount >= $this->collectionMaxRetryCount()) {
                if (TaskQueue::markFailed($queueId, (string)($task['error_message'] ?? ''))) {
                    CollectionTask::markQueueActive((int)$task['id'], false);
                }
            } else {
                if (TaskQueue::markProcessing($queueId, $this->afterSeconds($this->collectionIntervalSeconds()), (string)($task['error_message'] ?? ''))) {
                    CollectionTask::markQueueActive((int)$task['id'], true);
                }
            }
        } else {
            if (TaskQueue::markFailed($queueId, 'Unexpected collection status: ' . $status)) {
                CollectionTask::markQueueActive((int)$task['id'], false);
            }
        }

        return ['queue_id' => $queueId, 'type' => 'collection', 'business_id' => (int)$task['id'], 'status' => $status, 'ok' => $status !== 'collect_failed'];
    }

    private function recoverStaleProcessingQueues(): void
    {
        foreach (TaskQueue::lockedProcessingList() as $queue) {
            if ((string)($queue['queue_type'] ?? '') !== 'collection') {
                continue;
            }
            $lockedAt = strtotime((string)($queue['locked_at'] ?? ''));
            if (!$lockedAt || time() - $lockedAt < $this->timeoutMinutesForQueue($queue) * 60) {
                continue;
            }
            $this->recoverBusinessTask($queue);
            TaskQueue::markProcessing((int)$queue['id'], date('Y-m-d H:i:s'), 'Queue processing timeout');
        }
    }

    private function recoverBusinessTask(array $queue): void
    {
        $task = CollectionTask::findById((int)$queue['business_id']);
        if (!$task || (string)($task['status'] ?? '') !== 'processing') {
            return;
        }
        if (!empty($task['collect_tx_hash'])) {
            CollectionTask::mark((int)$task['id'], 'collecting', ['error_message' => '']);
            return;
        }
        if (!empty($task['gas_funding_tx_hash'])) {
            CollectionTask::mark((int)$task['id'], 'gas_funding', ['error_message' => '']);
            return;
        }
        CollectionTask::mark((int)$task['id'], 'manual_required', [
            'retry_count' => $this->collectionMaxRetryCount(),
            'error_message' => 'Collection task processing timeout; no transaction hash was recorded.',
        ]);
    }

    private function timeoutMinutesForQueue(array $queue): int
    {
        try {
            $task = CollectionTask::findById((int)$queue['business_id']);
            $address = $task ? PaymentAddress::findById((int)($task['address_id'] ?? 0)) : null;
            $account = $address ? WalletAccount::findById((int)($address['wallet_account_id'] ?? 0)) : null;
            return min(1440, max(1, (int)($account['deposit_timeout_minutes'] ?? 10)));
        } catch (Throwable) {
            return 10;
        }
    }

    public function loop(): void
    {
        while (true) {
            try {
                $now = time();
                if ($this->lastCollectionRunAt <= 0 || $now - $this->lastCollectionRunAt >= $this->collectionIntervalSeconds()) {
                    $enqueue = $this->enqueueCollectionPending(0, 'auto');
                    $result = $this->processNext('collection');
                    $this->lastCollectionRunAt = $now;
                    if ($result) {
                        Log::info('Collection queue executed', $result + ['enqueue' => $this->loggableStats($enqueue)]);
                    }
                }
            } catch (Throwable $e) {
                Log::error('Task queue worker failed: ' . $e->getMessage());
            }
            sleep(1);
        }
    }

    private function emptyStats(): array
    {
        return [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'items' => [],
            'errors' => [],
        ];
    }

    private function loggableStats(array $stats): array
    {
        return [
            'created' => (int)($stats['created'] ?? 0),
            'skipped' => (int)($stats['skipped'] ?? 0),
            'failed' => (int)($stats['failed'] ?? 0),
        ];
    }

    private function afterSeconds(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() + max(0, $seconds));
    }

    private function collectionIntervalSeconds(): int
    {
        return min(3600, max(1, (int)SystemSetting::getValue('collection.auto_collect_interval_seconds', '10')));
    }

    private function collectionMaxRetryCount(): int
    {
        return min(100, max(0, (int)SystemSetting::getValue('collection.auto_collect_max_retry_count', '3')));
    }
}
