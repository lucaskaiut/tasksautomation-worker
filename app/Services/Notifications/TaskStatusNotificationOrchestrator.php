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
            Log::info('Task notifications are disabled. Skipping dispatch.', [
                'task_id' => $notification->task->id,
                'reported_status' => $notification->reportedStatus,
                'result' => $notification->result,
            ]);

            return;
        }

        $message = $this->formatter->format($notification);
        $enabledChannelsDispatched = 0;
        $disabledChannels = [];

        foreach ($this->channels as $channel) {
            if (! $channel instanceof NotificationChannel) {
                Log::warning('Invalid notification channel binding was ignored.', [
                    'task_id' => $notification->task->id,
                    'reported_status' => $notification->reportedStatus,
                    'result' => $notification->result,
                    'channel_type' => get_debug_type($channel),
                ]);

                continue;
            }

            if (! $channel->isEnabled()) {
                $disabledChannels[] = $channel->name();

                continue;
            }

            try {
                $channel->send($notification, $message);
                $enabledChannelsDispatched++;
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

        if ($enabledChannelsDispatched === 0) {
            Log::warning('No notification channels were enabled for task completion.', [
                'task_id' => $notification->task->id,
                'reported_status' => $notification->reportedStatus,
                'result' => $notification->result,
                'disabled_channels' => $disabledChannels,
            ]);
        }
    }
}
