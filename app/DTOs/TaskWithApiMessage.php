<?php

namespace App\DTOs;

readonly class TaskWithApiMessage extends DataTransferObject
{
    public function __construct(
        public TaskData $task,
        public ?string $message,
    ) {}
}
