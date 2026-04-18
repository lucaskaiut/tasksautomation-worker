<?php

namespace App\DTOs;

readonly class ExecutionLoopIteration extends DataTransferObject
{
    public function __construct(
        public int $attempt,
        public ExecutionResult $executionResult,
        public ValidationResult $validationResult,
        public string $promptPath,
        public string $contextPath,
    ) {}
}
