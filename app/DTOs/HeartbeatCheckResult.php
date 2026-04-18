<?php

namespace App\DTOs;

readonly class HeartbeatCheckResult extends DataTransferObject
{
    public function __construct(
        public bool $sent,
        public bool $succeeded,
        public ?string $errorMessage,
    ) {}
}
