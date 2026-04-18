<?php

namespace Tests\Unit\Notifications;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\TaskStatusNotification;
use App\Services\Notifications\Channels\NotificationChannel;
use App\Services\Notifications\TaskStatusNotificationFormatter;
use App\Services\Notifications\TaskStatusNotificationOrchestrator;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class TaskStatusNotificationOrchestratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_notify_sends_message_to_enabled_channel(): void
    {
        config([
            'evolution.notifications.enabled' => true,
            'evolution.whatsapp.enabled' => true,
        ]);

        $formatter = Mockery::mock(TaskStatusNotificationFormatter::class);
        $formatter->shouldReceive('format')->once()->andReturn('formatted message');

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('isEnabled')->once()->andReturn(true);
        $channel->shouldReceive('send')->once()->withArgs(function ($notification, $message): bool {
            return $notification->task->id === 501 && $message === 'formatted message';
        });

        $service = new TaskStatusNotificationOrchestrator($formatter, [$channel]);
        $service->notify($this->makeNotification());

        $this->addToAssertionCount(1);
    }

    public function test_notify_skips_disabled_channel(): void
    {
        config([
            'evolution.notifications.enabled' => true,
            'evolution.whatsapp.enabled' => false,
        ]);

        $formatter = Mockery::mock(TaskStatusNotificationFormatter::class);
        $formatter->shouldReceive('format')->once()->andReturn('formatted message');

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('isEnabled')->once()->andReturn(false);
        $channel->shouldNotReceive('send');

        $service = new TaskStatusNotificationOrchestrator($formatter, [$channel]);
        $service->notify($this->makeNotification());

        $this->addToAssertionCount(1);
    }

    public function test_notify_logs_and_ignores_channel_failure(): void
    {
        config([
            'evolution.notifications.enabled' => true,
            'evolution.whatsapp.enabled' => true,
        ]);

        Log::spy();

        $formatter = Mockery::mock(TaskStatusNotificationFormatter::class);
        $formatter->shouldReceive('format')->once()->andReturn('formatted message');

        $channel = Mockery::mock(NotificationChannel::class);
        $channel->shouldReceive('isEnabled')->once()->andReturn(true);
        $channel->shouldReceive('send')->once()->andThrow(new \RuntimeException('Gateway timeout'));
        $channel->shouldReceive('name')->once()->andReturn('whatsapp');

        $service = new TaskStatusNotificationOrchestrator($formatter, [$channel]);
        $service->notify($this->makeNotification());

        Log::shouldHaveReceived('warning')->once();
        $this->addToAssertionCount(1);
    }

    private function makeNotification(): TaskStatusNotification
    {
        return new TaskStatusNotification(
            task: ApiTaskMapper::map([
                'id' => 501,
                'title' => 'Executar task',
                'status' => 'claimed',
                'project_id' => 1,
                'environment_profile_id' => 1,
                'created_by' => 1,
                'claimed_by_worker' => 'worker-1',
                'claimed_at' => '2026-01-01T00:00:00.000000Z',
                'attempts' => 1,
                'max_attempts' => 3,
                'implementation_type' => 'feature',
                'review_status' => '',
                'revision_count' => 0,
                'priority' => 'medium',
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ]),
            result: 'success',
            reportedStatus: 'review',
            executionSummary: 'Resumo'
        );
    }
}
