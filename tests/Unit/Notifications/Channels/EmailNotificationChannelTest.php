<?php

namespace Tests\Unit\Notifications\Channels;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\TaskStatusNotification;
use App\Mail\TaskStatusNotificationMail;
use App\Services\Notifications\Channels\EmailNotificationChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class EmailNotificationChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_channel_enablement_follows_configuration_flags_and_recipients(): void
    {
        config([
            'evolution.notifications.enabled' => true,
            'evolution.email.enabled' => true,
            'evolution.email.to' => ['ops@example.com'],
        ]);

        $channel = new EmailNotificationChannel;
        $this->assertTrue($channel->isEnabled());

        config(['evolution.notifications.enabled' => false]);
        $this->assertFalse($channel->isEnabled());

        config([
            'evolution.notifications.enabled' => true,
            'evolution.email.to' => [],
        ]);
        $this->assertFalse($channel->isEnabled());
    }

    public function test_send_dispatches_mailable_to_configured_recipients(): void
    {
        Mail::fake();

        config([
            'evolution.email.to' => ['ops@example.com', 'dev@example.com'],
        ]);

        $channel = new EmailNotificationChannel;
        $channel->send($this->makeNotification(), 'formatted message');

        Mail::assertSent(TaskStatusNotificationMail::class, function (TaskStatusNotificationMail $mail): bool {
            return $mail->hasTo('ops@example.com')
                && $mail->hasTo('dev@example.com')
                && $mail->messageBody === 'formatted message'
                && $mail->notification->task->id === 501;
        });
    }

    public function test_send_logs_and_rethrows_delivery_failures(): void
    {
        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        config([
            'evolution.email.to' => ['ops@example.com'],
        ]);

        $channel = new EmailNotificationChannel;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP unavailable');

        try {
            $channel->send($this->makeNotification(), 'formatted message');
        } finally {
            Log::shouldHaveReceived('error')->once();
        }
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
