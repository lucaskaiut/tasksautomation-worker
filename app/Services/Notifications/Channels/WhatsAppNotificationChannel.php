<?php

namespace App\Services\Notifications\Channels;

use App\DTOs\TaskStatusNotification;
use App\Services\Notifications\Evolution\EvolutionApiClient;
use App\Services\Service;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppNotificationChannel extends Service implements NotificationChannel
{
    public function __construct(
        private readonly EvolutionApiClient $evolutionApiClient,
    ) {}

    public function name(): string
    {
        return 'whatsapp';
    }

    public function isEnabled(): bool
    {
        return (bool) config('evolution.notifications.enabled', true)
            && (bool) config('evolution.whatsapp.enabled', false);
    }

    public function send(TaskStatusNotification $notification, string $message): void
    {
        try {
            $this->evolutionApiClient->sendText($message);
        } catch (Throwable $e) {
            Log::error('WhatsApp notification delivery failed.', [
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
}
