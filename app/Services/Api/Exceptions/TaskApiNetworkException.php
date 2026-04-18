<?php

namespace App\Services\Api\Exceptions;

class TaskApiNetworkException extends TaskApiException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
