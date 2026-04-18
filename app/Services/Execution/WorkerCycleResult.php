<?php

namespace App\Services\Execution;

readonly class WorkerCycleResult
{
    public function __construct(
        public bool $hadTask,
        public ?int $taskId,
        public bool $succeeded,
        public string $message,
    ) {}
}
