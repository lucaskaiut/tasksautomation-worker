<?php

namespace Tests\Unit\Notifications\Channels;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\TaskStatusNotification;
use App\Services\Notifications\Channels\WhatsAppNotificationChannel;
use App\Services\Notifications\Evolution\EvolutionApiClient;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class WhatsAppNotificationChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_channel_enablement_follows_configuration_flags(): void
    {
        config([
            'evolution.notifications.enabled' => true,
            'evolution.whatsapp.enabled' => true,
        ]);

        $channel = new WhatsAppNotificationChannel(Mockery::mock(EvolutionApiClient::class));
        $this->assertTrue($channel->isEnabled());

        config(['evolution.notifications.enabled' => false]);
        $this->assertFalse($channel->isEnabled());
    }

    public function test_send_delegates_message_to_evolution_client(): void
    {
        $client = Mockery::mock(EvolutionApiClient::class);
        $client->shouldReceive('sendText')->once()->with('formatted message');

        $channel = new WhatsAppNotificationChannel($client);
        $channel->send($this->makeNotification(), 'formatted message');

        $this->addToAssertionCount(1);
    }

    public function test_send_logs_and_rethrows_integration_failures(): void
    {
        Log::spy();

        $client = Mockery::mock(EvolutionApiClient::class);
        $client->shouldReceive('sendText')->once()->andThrow(new \RuntimeException('Evolution unavailable'));

        $channel = new WhatsAppNotificationChannel($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Evolution unavailable');

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
