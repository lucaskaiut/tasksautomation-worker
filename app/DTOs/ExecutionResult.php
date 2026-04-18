<?php

namespace App\DTOs;

readonly class ExecutionResult extends DataTransferObject
{
    public function __construct(
        public bool $succeeded,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $durationSeconds,
    ) {}
}
