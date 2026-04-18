<?php

namespace App\Services\Api\Exceptions;

class TaskApiAuthenticationException extends TaskApiException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
