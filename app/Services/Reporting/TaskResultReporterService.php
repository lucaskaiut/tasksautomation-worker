<?php

namespace App\Services\Reporting;

use App\DTOs\TaskData;
use App\DTOs\TaskStatusNotification;
use App\Services\Api\TaskApiClient;
use App\Services\Notifications\TaskStatusNotificationOrchestrator;
use App\Services\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskResultReporterService extends Service
{
    public function __construct(
        private readonly TaskApiClient $taskApiClient,
        private readonly TaskStatusNotificationOrchestrator $notificationOrchestrator,
    ) {}

    public function reportTechnicalSuccess(
        TaskData $task,
        string $executionSummary,
        ?string $branchName = null,
        ?string $commitSha = null,
        ?string $pullRequestUrl = null,
        ?string $logsPath = null,
        array $metadata = [],
    ): void {
        $payload = array_filter([
            'worker_id' => (string) config('worker.worker_id'),
            'status' => 'review',
            'execution_summary' => $executionSummary,
            'branch_name' => $branchName,
            'commit_sha' => $commitSha,
            'pull_request_url' => $pullRequestUrl,
            'logs_path' => $logsPath,
            'metadata' => $metadata === [] ? null : $metadata,
        ], static fn (mixed $value): bool => $value !== null);

        $notification = new TaskStatusNotification(
            task: $task,
            result: 'success',
            reportedStatus: 'review',
            executionSummary: $executionSummary,
            branchName: $branchName,
            commitSha: $commitSha,
            pullRequestUrl: $pullRequestUrl,
            logsPath: $logsPath,
            metadata: $metadata,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        );

        $this->reportAndNotify($task, $payload, $notification);
    }

    public function reportFailure(
        TaskData $task,
        string $executionSummary,
        string $failureReason,
        array $metadata = [],
        ?string $logsPath = null,
    ): void {
        $payload = array_filter([
            'worker_id' => (string) config('worker.worker_id'),
            'status' => 'failed',
            'execution_summary' => $executionSummary,
            'failure_reason' => $failureReason,
            'logs_path' => $logsPath,
            'metadata' => $metadata === [] ? null : $metadata,
        ], static fn (mixed $value): bool => $value !== null);

        $notification = new TaskStatusNotification(
            task: $task,
            result: 'failure',
            reportedStatus: 'failed',
            executionSummary: $executionSummary,
            failureReason: $failureReason,
            logsPath: $logsPath,
            metadata: $metadata,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        );

        $this->reportAndNotify($task, $payload, $notification);
    }

    private function reportAndNotify(TaskData $task, array $payload, TaskStatusNotification $notification): void
    {
        Log::info('Reporting task completion.', [
            'task_id' => $task->id,
            'reported_status' => $notification->reportedStatus,
            'result' => $notification->result,
        ]);

        $finishFailure = null;

        try {
            $this->taskApiClient->finishTask($task->id, $payload);

            Log::info('Task completion reported successfully.', [
                'task_id' => $task->id,
                'reported_status' => $notification->reportedStatus,
                'result' => $notification->result,
            ]);
        } catch (Throwable $throwable) {
            $finishFailure = $throwable;

            Log::error('Task completion report failed. Notification dispatch will still be attempted.', [
                'task_id' => $task->id,
                'reported_status' => $notification->reportedStatus,
                'result' => $notification->result,
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
                'payload' => $payload,
            ]);
        }

        Log::info('Dispatching task completion notifications.', [
            'task_id' => $task->id,
            'reported_status' => $notification->reportedStatus,
            'result' => $notification->result,
        ]);

        $this->notificationOrchestrator->notify($notification);

        if ($finishFailure !== null) {
            throw $finishFailure;
        }
    }
}
