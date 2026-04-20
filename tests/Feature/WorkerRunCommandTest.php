<?php

namespace Tests\Feature;

use App\DTOs\Mapping\ApiTaskMapper;
use App\Services\Execution\WorkerCycleResult;
use App\Services\Execution\WorkerProcessPoolService;
use App\Services\Execution\WorkerRunnerService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class WorkerRunCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_worker_run_once_processes_single_cycle_and_exits(): void
    {
        $runner = Mockery::mock(WorkerRunnerService::class);
        $runner->shouldReceive('runCycle')->once()->withArgs(function ($callback): bool {
            return is_callable($callback);
        })->andReturn(new WorkerCycleResult(
            hadTask: false,
            taskId: null,
            succeeded: true,
            message: 'No task available.',
        ));
        $this->app->instance(WorkerRunnerService::class, $runner);

        $this->artisan('worker:run --once')
            ->expectsOutput('No task available.')
            ->assertExitCode(0);
    }

    public function test_worker_run_once_logs_task_start_before_final_status(): void
    {
        $runner = Mockery::mock(WorkerRunnerService::class);
        $runner->shouldReceive('runCycle')->once()->withArgs(function ($callback): bool {
            if (! is_callable($callback)) {
                return false;
            }

            $callback('Task 501 claimed. Starting execution for "Executar task".');

            return true;
        })->andReturn(new WorkerCycleResult(
            hadTask: true,
            taskId: 501,
            succeeded: true,
            message: 'Task 501 finished with technical success.',
        ));
        $this->app->instance(WorkerRunnerService::class, $runner);

        $this->artisan('worker:run --once')
            ->expectsOutput('Task 501 claimed. Starting execution for "Executar task".')
            ->expectsOutput('Task 501 finished with technical success.')
            ->assertExitCode(0);
    }

    public function test_worker_run_uses_process_pool_when_concurrency_is_enabled(): void
    {
        config(['worker.max_concurrent_tasks' => 3]);

        $pool = Mockery::mock(WorkerProcessPoolService::class);
        $pool->shouldReceive('run')->once()->withArgs(function ($callback, $once): bool {
            return is_callable($callback) && $once === true;
        });
        $this->app->instance(WorkerProcessPoolService::class, $pool);

        $runner = Mockery::mock(WorkerRunnerService::class);
        $runner->shouldNotReceive('runCycle');
        $this->app->instance(WorkerRunnerService::class, $runner);

        $this->artisan('worker:run --once')->assertExitCode(0);
    }

    public function test_worker_run_can_process_a_preclaimed_task_payload(): void
    {
        $payloadPath = storage_path('framework/testing/preclaimed-task.json');
        File::ensureDirectoryExists(dirname($payloadPath));
        File::put($payloadPath, json_encode($this->taskPayload(), JSON_THROW_ON_ERROR));

        $task = ApiTaskMapper::map($this->taskPayload());

        $pool = Mockery::mock(WorkerProcessPoolService::class);
        $pool->shouldReceive('runClaimedTaskPayload')->once()->withArgs(function ($path, $callback) use ($payloadPath): bool {
            return $path === $payloadPath && is_callable($callback);
        })->andReturn(new WorkerCycleResult(
            hadTask: true,
            taskId: $task->id,
            succeeded: true,
            message: 'Task 501 finished with technical success.',
        ));
        $this->app->instance(WorkerProcessPoolService::class, $pool);

        $this->artisan('worker:run --child-task-file='.$payloadPath)
            ->expectsOutput('Task 501 finished with technical success.')
            ->assertExitCode(0);

        File::delete($payloadPath);
    }

    private function taskPayload(): array
    {
        return [
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
