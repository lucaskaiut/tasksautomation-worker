<?php

namespace Tests\Unit\Execution;

use App\DTOs\Mapping\ApiTaskMapper;
use App\Services\Execution\WorkerProcessPoolService;
use App\Services\Execution\WorkerRunnerService;
use Illuminate\Filesystem\Filesystem;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkerProcessPoolServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_run_dispatches_claimed_tasks_until_capacity_is_filled_when_running_once(): void
    {
        config(['worker.max_concurrent_tasks' => 2]);

        $taskA = ApiTaskMapper::map($this->taskPayload(501));
        $taskB = ApiTaskMapper::map($this->taskPayload(502));

        $runner = Mockery::mock(WorkerRunnerService::class);
        $runner->shouldReceive('claimTask')->times(2)->andReturn($taskA, $taskB);
        $this->app->instance(WorkerRunnerService::class, $runner);

        $processA = $this->mockFinishedProcess();
        $processB = $this->mockFinishedProcess();

        $service = Mockery::mock(WorkerProcessPoolService::class, [
            $runner,
            app(Filesystem::class),
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('startChildProcess')->once()->with($taskA, Mockery::type('string'))->andReturn($processA);
        $service->shouldReceive('startChildProcess')->once()->with($taskB, Mockery::type('string'))->andReturn($processB);

        $messages = [];

        $service->run(function (string $message) use (&$messages): void {
            $messages[] = $message;
        }, true);

        $this->assertSame([
            'Task 501 claimed. Dispatched to worker child process.',
            'Task 502 claimed. Dispatched to worker child process.',
        ], $messages);
    }

    public function test_run_claimed_task_payload_maps_payload_and_executes_claimed_task(): void
    {
        $task = ApiTaskMapper::map($this->taskPayload(777));
        $payloadPath = storage_path('framework/testing/claimed-task-777.json');

        app(Filesystem::class)->ensureDirectoryExists(dirname($payloadPath));
        app(Filesystem::class)->put(
            $payloadPath,
            json_encode($task->sourcePayload, JSON_THROW_ON_ERROR)
        );

        $runner = Mockery::mock(WorkerRunnerService::class);
        $runner->shouldReceive('runClaimedTask')->once()->withArgs(function ($receivedTask, $callback) use ($task): bool {
            return $receivedTask->id === $task->id && is_callable($callback);
        })->andReturn(new \App\Services\Execution\WorkerCycleResult(
            hadTask: true,
            taskId: $task->id,
            succeeded: true,
            message: 'ok',
        ));
        $this->app->instance(WorkerRunnerService::class, $runner);

        $messages = [];

        $result = app(WorkerProcessPoolService::class)->runClaimedTaskPayload($payloadPath, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertTrue($result->succeeded);
        $this->assertSame([
            'Task 777 claimed. Starting execution for "Executar task 777".',
        ], $messages);

        app(Filesystem::class)->delete($payloadPath);
    }

    private function mockFinishedProcess(): Process
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getIncrementalOutput')->andReturn('', '');
        $process->shouldReceive('getIncrementalErrorOutput')->andReturn('', '');
        $process->shouldReceive('isRunning')->andReturn(false);
        $process->shouldReceive('getExitCode')->andReturn(0);

        return $process;
    }

    private function taskPayload(int $taskId): array
    {
        return [
            'id' => $taskId,
            'title' => sprintf('Executar task %d', $taskId),
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
            'project' => [
                'id' => 1,
                'name' => 'Project',
                'slug' => 'project',
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
            ],
        ];
    }
}
