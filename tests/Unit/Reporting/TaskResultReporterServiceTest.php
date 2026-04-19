<?php

namespace Tests\Unit\Reporting;

use App\DTOs\Mapping\ApiTaskMapper;
use App\Services\Api\TaskApiClient;
use App\Services\Notifications\TaskStatusNotificationOrchestrator;
use App\Services\Reporting\TaskResultReporterService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class TaskResultReporterServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_report_technical_success_sends_review_payload_to_finish_endpoint(): void
    {
        config(['worker.worker_id' => 'worker-local-01']);
        Log::spy();

        $task = $this->makeTask();
        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('finishTask')->once()->with(123, [
            'worker_id' => 'worker-local-01',
            'status' => 'review',
            'execution_summary' => 'Implementei a validacao do callback, adicionei testes e abri o PR.',
            'branch_name' => 'fix/login-callback',
            'commit_sha' => 'abc123def456',
            'pull_request_url' => 'https://github.com/org/repo/pull/10',
            'logs_path' => 'storage/logs/task-123.log',
            'metadata' => [
                'tests' => 'php artisan test --compact',
                'attempts' => 2,
            ],
        ]);
        $this->app->instance(TaskApiClient::class, $client);

        $orchestrator = Mockery::mock(TaskStatusNotificationOrchestrator::class);
        $orchestrator->shouldReceive('notify')->once()->withArgs(function ($notification): bool {
            return $notification->task->id === 123
                && $notification->result === 'success'
                && $notification->reportedStatus === 'review'
                && $notification->branchName === 'fix/login-callback'
                && $notification->commitSha === 'abc123def456';
        });
        $this->app->instance(TaskStatusNotificationOrchestrator::class, $orchestrator);

        app(TaskResultReporterService::class)->reportTechnicalSuccess(
            task: $task,
            executionSummary: 'Implementei a validacao do callback, adicionei testes e abri o PR.',
            branchName: 'fix/login-callback',
            commitSha: 'abc123def456',
            pullRequestUrl: 'https://github.com/org/repo/pull/10',
            logsPath: 'storage/logs/task-123.log',
            metadata: [
                'tests' => 'php artisan test --compact',
                'attempts' => 2,
            ],
        );

        Log::shouldHaveReceived('info')->with('Reporting task completion.', Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Task completion reported successfully.', Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Dispatching task completion notifications.', Mockery::type('array'))->once();
        $this->addToAssertionCount(1);
    }

    public function test_report_failure_sends_failed_payload_to_finish_endpoint(): void
    {
        config(['worker.worker_id' => 'worker-local-01']);
        Log::spy();

        $task = $this->makeTask();
        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('finishTask')->once()->with(123, [
            'worker_id' => 'worker-local-01',
            'status' => 'failed',
            'execution_summary' => 'Tentei aplicar a correcao, mas a suite falha por dependencia externa.',
            'failure_reason' => 'Servico de terceiros indisponivel',
            'logs_path' => 'storage/logs/task-123.log',
            'metadata' => [
                'attempts' => 3,
            ],
        ]);
        $this->app->instance(TaskApiClient::class, $client);

        $orchestrator = Mockery::mock(TaskStatusNotificationOrchestrator::class);
        $orchestrator->shouldReceive('notify')->once()->withArgs(function ($notification): bool {
            return $notification->task->id === 123
                && $notification->result === 'failure'
                && $notification->reportedStatus === 'failed'
                && $notification->failureReason === 'Servico de terceiros indisponivel';
        });
        $this->app->instance(TaskStatusNotificationOrchestrator::class, $orchestrator);

        app(TaskResultReporterService::class)->reportFailure(
            task: $task,
            executionSummary: 'Tentei aplicar a correcao, mas a suite falha por dependencia externa.',
            failureReason: 'Servico de terceiros indisponivel',
            metadata: [
                'attempts' => 3,
            ],
            logsPath: 'storage/logs/task-123.log',
        );

        Log::shouldHaveReceived('info')->with('Reporting task completion.', Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Task completion reported successfully.', Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Dispatching task completion notifications.', Mockery::type('array'))->once();
        $this->addToAssertionCount(1);
    }

    public function test_report_technical_success_omits_optional_null_fields(): void
    {
        config(['worker.worker_id' => 'worker-local-01']);
        Log::spy();

        $task = $this->makeTask(taskId: 44);
        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('finishTask')->once()->with(44, [
            'worker_id' => 'worker-local-01',
            'status' => 'review',
            'execution_summary' => 'Resumo estruturado',
        ]);
        $this->app->instance(TaskApiClient::class, $client);

        $orchestrator = Mockery::mock(TaskStatusNotificationOrchestrator::class);
        $orchestrator->shouldReceive('notify')->once();
        $this->app->instance(TaskStatusNotificationOrchestrator::class, $orchestrator);

        app(TaskResultReporterService::class)->reportTechnicalSuccess(
            task: $task,
            executionSummary: 'Resumo estruturado',
        );

        Log::shouldHaveReceived('info')->with('Reporting task completion.', Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Task completion reported successfully.', Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Dispatching task completion notifications.', Mockery::type('array'))->once();
        $this->addToAssertionCount(1);
    }

    public function test_report_notifies_and_rethrows_when_finish_request_fails(): void
    {
        config(['worker.worker_id' => 'worker-local-01']);
        Log::spy();

        $task = $this->makeTask();
        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('finishTask')->once()->andThrow(new \RuntimeException('API offline'));
        $this->app->instance(TaskApiClient::class, $client);

        $orchestrator = Mockery::mock(TaskStatusNotificationOrchestrator::class);
        $orchestrator->shouldReceive('notify')->once()->withArgs(function ($notification): bool {
            return $notification->task->id === 123
                && $notification->result === 'failure'
                && $notification->reportedStatus === 'failed'
                && $notification->failureReason === 'Falha externa';
        });
        $this->app->instance(TaskStatusNotificationOrchestrator::class, $orchestrator);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API offline');

        try {
            app(TaskResultReporterService::class)->reportFailure(
                task: $task,
                executionSummary: 'Resumo',
                failureReason: 'Falha externa',
            );
        } finally {
            Log::shouldHaveReceived('info')->with('Reporting task completion.', Mockery::type('array'))->once();
            Log::shouldHaveReceived('error')->with('Task completion report failed. Notification dispatch will still be attempted.', Mockery::on(function (array $context): bool {
                return ($context['task_id'] ?? null) === 123
                    && ($context['reported_status'] ?? null) === 'failed'
                    && ($context['result'] ?? null) === 'failure'
                    && ($context['exception_class'] ?? null) === \RuntimeException::class
                    && ($context['error'] ?? null) === 'API offline';
            }))->once();
            Log::shouldHaveReceived('info')->with('Dispatching task completion notifications.', Mockery::type('array'))->once();
        }
    }

    private function makeTask(int $taskId = 123): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map([
            'id' => $taskId,
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
        ]);
    }
}
