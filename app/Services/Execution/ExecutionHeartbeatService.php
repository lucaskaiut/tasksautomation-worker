<?php

namespace App\Services\Execution;

use App\DTOs\HeartbeatCheckResult;
use App\Services\Api\TaskApiClient;
use App\Services\Execution\Exceptions\HeartbeatException;
use App\Services\Service;
use Throwable;

class ExecutionHeartbeatService extends Service
{
    /**
     * @var array<int, int>
     */
    private array $lastSentAtByTask = [];

    public function __construct(
        private readonly TaskApiClient $taskApiClient,
    ) {}

    public function beatIfDue(int $taskId, ?int $now = null): HeartbeatCheckResult
    {
        $now ??= time();
        $lastSentAt = $this->lastSentAtByTask[$taskId] ?? null;
        $interval = max(1, (int) config('worker.heartbeat_interval_seconds', 10));

        if ($lastSentAt !== null && ($now - $lastSentAt) < $interval) {
            return new HeartbeatCheckResult(
                sent: false,
                succeeded: true,
                errorMessage: null,
            );
        }

        return $this->forceHeartbeat($taskId, $now);
    }

    public function forceHeartbeat(int $taskId, ?int $now = null): HeartbeatCheckResult
    {
        $now ??= time();

        try {
            $this->taskApiClient->heartbeat($taskId);
        } catch (Throwable $e) {
            if ((bool) config('worker.heartbeat.fail_on_error', false)) {
                throw new HeartbeatException(
                    sprintf('Heartbeat failed for task %d: %s', $taskId, $e->getMessage()),
                    0,
                    $e
                );
            }

            return new HeartbeatCheckResult(
                sent: true,
                succeeded: false,
                errorMessage: $e->getMessage(),
            );
        }

        $this->lastSentAtByTask[$taskId] = $now;

        return new HeartbeatCheckResult(
            sent: true,
            succeeded: true,
            errorMessage: null,
        );
    }

    public function reset(int $taskId): void
    {
        unset($this->lastSentAtByTask[$taskId]);
    }
}
