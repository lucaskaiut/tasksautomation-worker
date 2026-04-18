<?php

namespace App\DTOs;

readonly class ValidationCommandResult extends DataTransferObject
{
    public function __construct(
        public string $command,
        public bool $passed,
        public string $output,
        public int $exitCode,
    ) {}
}
