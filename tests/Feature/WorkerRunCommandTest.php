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
        $runner->shouldReceive('runCycle')->once()->andReturn(new WorkerCycleResult(
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
}
