<?php

namespace App\DTOs;

readonly class TaskData extends DataTransferObject
{
    public function __construct(
        public int $id,
        public int $projectId,
        public int $environmentProfileId,
        public int $createdBy,
        public string $claimedByWorker,
        public string $claimedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $lastHeartbeatAt,
        public int $attempts,
        public int $maxAttempts,
        public ?string $lockedUntil,
        public ?string $failureReason,
        public ?string $executionSummary,
        public ?string $runAfter,
        public string $title,
        public ?string $description,
        public ?string $deliverables,
        public ?string $constraints,
        public ?string $implementationType,
        public string $status,
        public string $reviewStatus,
        public int $revisionCount,
        public ?string $lastReviewedAt,
        public ?int $lastReviewedBy,
        public ?string $lastReviewer,
        public string $priority,
        public ?ProjectData $project,
        public ?EnvironmentProfileData $environmentProfile,
        public string $createdAt,
        public string $updatedAt,
        public array $sourcePayload,
    ) {}

    public function toNormalizedArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'environment_profile_id' => $this->environmentProfileId,
            'created_by' => $this->createdBy,
            'claimed_by_worker' => $this->claimedByWorker,
            'claimed_at' => $this->claimedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'last_heartbeat_at' => $this->lastHeartbeatAt,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'locked_until' => $this->lockedUntil,
            'failure_reason' => $this->failureReason,
            'execution_summary' => $this->executionSummary,
            'run_after' => $this->runAfter,
            'title' => $this->title,
            'description' => $this->description,
            'deliverables' => $this->deliverables,
            'constraints' => $this->constraints,
            'implementation_type' => $this->implementationType,
            'status' => $this->status,
            'review_status' => $this->reviewStatus,
            'revision_count' => $this->revisionCount,
            'last_reviewed_at' => $this->lastReviewedAt,
            'last_reviewed_by' => $this->lastReviewedBy,
            'last_reviewer' => $this->lastReviewer,
            'priority' => $this->priority,
            'project' => $this->project?->toNormalizedArray(),
            'environment_profile' => $this->environmentProfile?->toNormalizedArray(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
