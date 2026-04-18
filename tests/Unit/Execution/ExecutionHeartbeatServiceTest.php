<?php

namespace Tests\Unit\Execution;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\TaskWithApiMessage;
use App\Services\Api\TaskApiClient;
use App\Services\Execution\Exceptions\HeartbeatException;
use App\Services\Execution\ExecutionHeartbeatService;
use Mockery;
use Tests\TestCase;

class ExecutionHeartbeatServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_heartbeat_is_sent_successfully_when_due(): void
    {
        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('heartbeat')->once()->with(7)->andReturn(
            new TaskWithApiMessage($this->makeTask(), 'ok')
        );
        $this->app->instance(TaskApiClient::class, $client);

        $result = app(ExecutionHeartbeatService::class)->beatIfDue(7, 100);

        $this->assertTrue($result->sent);
        $this->assertTrue($result->succeeded);
        $this->assertNull($result->errorMessage);
    }

    public function test_multiple_heartbeats_can_be_sent_during_long_execution(): void
    {
        config(['worker.heartbeat_interval_seconds' => 10]);

        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('heartbeat')->twice()->with(9)->andReturn(
            new TaskWithApiMessage($this->makeTask(), 'ok'),
            new TaskWithApiMessage($this->makeTask(), 'ok'),
        );
        $this->app->instance(TaskApiClient::class, $client);

        $service = app(ExecutionHeartbeatService::class);

        $first = $service->beatIfDue(9, 100);
        $second = $service->beatIfDue(9, 105);
        $third = $service->beatIfDue(9, 111);

        $this->assertTrue($first->sent);
        $this->assertFalse($second->sent);
        $this->assertTrue($third->sent);
        $this->assertTrue($third->succeeded);
    }

    public function test_heartbeat_failure_is_handled_predictably_without_stopping_execution_by_default(): void
    {
        config(['worker.heartbeat.fail_on_error' => false]);

        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('heartbeat')->once()->with(5)->andThrow(new \RuntimeException('transient'));
        $this->app->instance(TaskApiClient::class, $client);

        $result = app(ExecutionHeartbeatService::class)->forceHeartbeat(5, 100);

        $this->assertTrue($result->sent);
        $this->assertFalse($result->succeeded);
        $this->assertSame('transient', $result->errorMessage);
    }

    public function test_heartbeat_failure_can_be_escalated_by_policy(): void
    {
        config(['worker.heartbeat.fail_on_error' => true]);

        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('heartbeat')->once()->with(5)->andThrow(new \RuntimeException('fatal'));
        $this->app->instance(TaskApiClient::class, $client);

        $this->expectException(HeartbeatException::class);
        $this->expectExceptionMessage('Heartbeat failed for task 5: fatal');

        app(ExecutionHeartbeatService::class)->forceHeartbeat(5, 100);
    }

    private function makeTask(): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map([
            'id' => 1,
            'title' => 'Task',
            'status' => 'running',
        ]);
    }
}
