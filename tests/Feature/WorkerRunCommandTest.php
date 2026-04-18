<?php

namespace Tests\Feature;

use App\Services\Execution\WorkerCycleResult;
use App\Services\Execution\WorkerRunnerService;
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
}
