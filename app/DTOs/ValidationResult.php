<?php

namespace App\DTOs;

readonly class ValidationResult extends DataTransferObject
{
    /**
     * @param  list<ValidationCommandResult>  $commands
     */
    public function __construct(
        public bool $passed,
        public array $commands,
        public ?string $firstFailureOutput,
    ) {}
}
