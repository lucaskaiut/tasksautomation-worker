<?php

namespace App\Services\Notifications\Channels;

use App\DTOs\TaskStatusNotification;

interface NotificationChannel
{
    public function name(): string;

    public function isEnabled(): bool;

    public function send(TaskStatusNotification $notification, string $message): void;
}
