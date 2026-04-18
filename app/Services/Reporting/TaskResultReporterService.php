<?php

namespace App\Services\Reporting;

use App\DTOs\TaskData;
use App\DTOs\TaskStatusNotification;
use App\Services\Api\TaskApiClient;
use App\Services\Notifications\TaskStatusNotificationOrchestrator;
use App\Services\Service;
use Carbon\CarbonImmutable;

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

        $this->taskApiClient->finishTask($task->id, $payload);

        $this->notificationOrchestrator->notify(new TaskStatusNotification(
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
        ));
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

        $this->taskApiClient->finishTask($task->id, $payload);

        $this->notificationOrchestrator->notify(new TaskStatusNotification(
            task: $task,
            result: 'failure',
            reportedStatus: 'failed',
            executionSummary: $executionSummary,
            failureReason: $failureReason,
            logsPath: $logsPath,
            metadata: $metadata,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }
}
