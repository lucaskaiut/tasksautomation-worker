<?php

namespace App\DTOs;

readonly class ExecutionLoopResult extends DataTransferObject
{
    /**
     * @param  list<ExecutionLoopIteration>  $iterations
     */
    public function __construct(
        public bool $succeeded,
        public int $attemptsUsed,
        public array $iterations,
        public ?string $finalTechnicalError,
    ) {}
}
