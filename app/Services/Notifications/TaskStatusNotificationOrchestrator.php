<?php

namespace App\Services\Notifications;

use App\DTOs\TaskStatusNotification;
use App\Services\Notifications\Channels\NotificationChannel;
use App\Services\Service;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskStatusNotificationOrchestrator extends Service
{
    /**
     * @param  iterable<NotificationChannel>  $channels
     */
    public function __construct(
        private readonly TaskStatusNotificationFormatter $formatter,
        private readonly iterable $channels,
    ) {}

    public function notify(TaskStatusNotification $notification): void
    {
        if (! (bool) config('evolution.notifications.enabled', true)) {
            return;
        }

        $message = $this->formatter->format($notification);

        foreach ($this->channels as $channel) {
            if (! $channel instanceof NotificationChannel || ! $channel->isEnabled()) {
                continue;
            }

            try {
                $channel->send($notification, $message);
            } catch (Throwable $e) {
                Log::warning('Notification channel failed and was ignored.', [
                    'channel' => $channel->name(),
                    'task_id' => $notification->task->id,
                    'reported_status' => $notification->reportedStatus,
                    'result' => $notification->result,
                    'exception_class' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
