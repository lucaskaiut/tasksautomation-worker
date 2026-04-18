<?php

namespace App\Services\Reporting;

use App\Services\Api\TaskApiClient;
use App\Services\Service;

class TaskResultReporterService extends Service
{
    public function __construct(
        private readonly TaskApiClient $taskApiClient,
    ) {}

    public function reportTechnicalSuccess(
        int $taskId,
        string $executionSummary,
        ?string $branchName = null,
        ?string $commitSha = null,
        ?string $pullRequestUrl = null,
        ?string $logsPath = null,
        array $metadata = [],
    ): void {
        $this->taskApiClient->finishTask($taskId, array_filter([
            'worker_id' => (string) config('worker.worker_id'),
            'status' => 'review',
            'execution_summary' => $executionSummary,
            'branch_name' => $branchName,
            'commit_sha' => $commitSha,
            'pull_request_url' => $pullRequestUrl,
            'logs_path' => $logsPath,
            'metadata' => $metadata === [] ? null : $metadata,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function reportFailure(
        int $taskId,
        string $executionSummary,
        string $failureReason,
        array $metadata = [],
        ?string $logsPath = null,
    ): void {
        $this->taskApiClient->finishTask($taskId, array_filter([
            'worker_id' => (string) config('worker.worker_id'),
            'status' => 'failed',
            'execution_summary' => $executionSummary,
            'failure_reason' => $failureReason,
            'logs_path' => $logsPath,
            'metadata' => $metadata === [] ? null : $metadata,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
