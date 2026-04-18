<?php

namespace Tests\Unit\Reporting;

use App\Services\Api\TaskApiClient;
use App\Services\Reporting\TaskResultReporterService;
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

        app(TaskResultReporterService::class)->reportTechnicalSuccess(
            taskId: 123,
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

        $this->addToAssertionCount(1);
    }

    public function test_report_failure_sends_failed_payload_to_finish_endpoint(): void
    {
        config(['worker.worker_id' => 'worker-local-01']);

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

        app(TaskResultReporterService::class)->reportFailure(
            taskId: 123,
            executionSummary: 'Tentei aplicar a correcao, mas a suite falha por dependencia externa.',
            failureReason: 'Servico de terceiros indisponivel',
            metadata: [
                'attempts' => 3,
            ],
            logsPath: 'storage/logs/task-123.log',
        );

        $this->addToAssertionCount(1);
    }

    public function test_report_technical_success_omits_optional_null_fields(): void
    {
        config(['worker.worker_id' => 'worker-local-01']);

        $client = Mockery::mock(TaskApiClient::class);
        $client->shouldReceive('finishTask')->once()->with(44, [
            'worker_id' => 'worker-local-01',
            'status' => 'review',
            'execution_summary' => 'Resumo estruturado',
        ]);
        $this->app->instance(TaskApiClient::class, $client);

        app(TaskResultReporterService::class)->reportTechnicalSuccess(
            taskId: 44,
            executionSummary: 'Resumo estruturado',
        );

        $this->addToAssertionCount(1);
    }
}
