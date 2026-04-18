<?php

namespace App\Services\Notifications\Channels;

use App\DTOs\TaskStatusNotification;
use App\Mail\TaskStatusNotificationMail;
use App\Services\Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailNotificationChannel extends Service implements NotificationChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function isEnabled(): bool
    {
        return (bool) config('evolution.notifications.enabled', true)
            && (bool) config('evolution.email.enabled', false)
            && $this->recipients() !== [];
    }

    public function send(TaskStatusNotification $notification, string $message): void
    {
        try {
            Mail::to($this->recipients())->send(new TaskStatusNotificationMail($notification, $message));
        } catch (Throwable $e) {
            Log::error('Email notification delivery failed.', [
                'channel' => $this->name(),
                'task_id' => $notification->task->id,
                'reported_status' => $notification->reportedStatus,
                'result' => $notification->result,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $recipients = config('evolution.email.to', []);

        if (! is_array($recipients)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $recipient): string => trim((string) $recipient),
            $recipients,
        ), static fn (string $recipient): bool => $recipient !== ''));
    }
}
