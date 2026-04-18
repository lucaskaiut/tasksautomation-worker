<?php

namespace App\DTOs;

readonly class TaskStatusNotification extends DataTransferObject
{
    public function __construct(
        public TaskData $task,
        public string $result,
        public string $reportedStatus,
        public string $executionSummary,
        public ?string $failureReason = null,
        public ?string $branchName = null,
        public ?string $commitSha = null,
        public ?string $pullRequestUrl = null,
        public ?string $logsPath = null,
        public array $metadata = [],
        public ?string $occurredAt = null,
    ) {}
}
