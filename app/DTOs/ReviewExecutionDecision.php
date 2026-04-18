<?php

namespace App\DTOs;

readonly class ReviewExecutionDecision extends DataTransferObject
{
    public function __construct(
        public bool $shouldExecute,
        public ?string $humanFeedback,
        public ?string $skipReason,
    ) {}
}
