<?php

namespace App\DTOs;

readonly class IterationContext extends DataTransferObject
{
    public function __construct(
        public int $taskId,
        public int $attempt,
        public int $maxAttempts,
        public TaskData $task,
        public ?string $lastTechnicalError,
        public ?string $humanReviewFeedback,
    ) {}
}
