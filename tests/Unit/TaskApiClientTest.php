<?php

namespace Tests\Unit;

use App\Services\Api\Exceptions\TaskApiAuthenticationException;
use App\Services\Api\Exceptions\TaskApiHttpException;
use App\Services\Api\Exceptions\TaskApiNetworkException;
use App\Services\Api\Exceptions\TaskApiUnexpectedResponseException;
use App\Services\Api\TaskApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'worker.api.base_url' => 'http://localhost',
            'worker.api.token_path' => 'api/tokens/create',
            'worker.api.email' => 'admin@example.com',
            'worker.api.password' => 'password',
            'worker.api.token_name' => 'worker',
            'worker.api.abilities' => ['*'],
        ]);
    }

    private function tokenUrl(): string
    {
        return rtrim((string) config('worker.api.base_url'), '/').'/'.ltrim((string) config('worker.api.token_path'), '/');
    }

    private function claimUrl(): string
    {
        return rtrim((string) config('worker.api.base_url'), '/').'/'.ltrim((string) config('worker.api.claim_path'), '/');
    }

    private function heartbeatUrl(int $taskId): string
    {
        $path = sprintf((string) config('worker.api.heartbeat_path_template'), $taskId);

        return rtrim((string) config('worker.api.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function finishUrl(int $taskId): string
    {
        $path = sprintf((string) config('worker.api.finish_path_template'), $taskId);

        return rtrim((string) config('worker.api.base_url'), '/').'/'.ltrim($path, '/');
    }

    private static function claimEndpointSuccessPayload(): array
    {
        return [
            'data' => [
                'id' => 1,
                'project_id' => 1,
                'environment_profile_id' => 1,
                'created_by' => 1,
                'claimed_by_worker' => 'worker-1',
                'claimed_at' => '2026-04-17T14:59:06.000000Z',
                'started_at' => null,
                'finished_at' => null,
                'last_heartbeat_at' => null,
                'attempts' => 1,
                'max_attempts' => 3,
                'locked_until' => '2026-04-17T15:09:06.000000Z',
                'failure_reason' => null,
                'execution_summary' => null,
                'run_after' => null,
                'title' => 'Instalar a aplicação "tasksautomation" na infra',
                'description' => 'Fazer o deploy da aplicação "tasksautomation" seguindo os mesmos principios das aplicações com CI/CD.',
                'deliverables' => 'Aplicação acessível em dev-automation.lucaskaiut.com.br com SSL com certificado válido',
                'constraints' => null,
                'status' => 'claimed',
                'review_status' => '',
                'revision_count' => 0,
                'last_reviewed_at' => null,
                'last_reviewed_by' => null,
                'last_reviewer' => null,
                'priority' => 'low',
                'project' => [
                    'id' => 1,
                    'name' => 'Infra',
                    'slug' => 'infra',
                    'description' => 'A documentação do projeto pode ser encontrada em docs/arquitetura.md',
                    'repository_url' => 'https://github.com/lucaskaiut/infra',
                    'default_branch' => 'main',
                    'global_rules' => null,
                    'is_active' => true,
                    'created_at' => '2026-04-16T17:44:50.000000Z',
                    'updated_at' => '2026-04-16T17:44:50.000000Z',
                ],
                'environment_profile' => [
                    'id' => 1,
                    'project_id' => 1,
                    'name' => 'light',
                    'slug' => 'light',
                    'is_default' => false,
                    'docker_compose_yml' => null,
                ],
                'created_at' => '2026-04-16T17:54:26.000000Z',
                'updated_at' => '2026-04-17T14:59:06.000000Z',
            ],
            'message' => 'Tarefa claimada com sucesso.',
        ];
    }

    private static function heartbeatEndpointSuccessPayload(): array
    {
        return [
            'data' => [
                'id' => 1,
                'project_id' => 1,
                'environment_profile_id' => 1,
                'created_by' => 1,
                'claimed_by_worker' => 'worker-1',
                'claimed_at' => '2026-04-17T14:59:06.000000Z',
                'started_at' => '2026-04-17T15:00:09.000000Z',
                'finished_at' => null,
                'last_heartbeat_at' => '2026-04-17T15:00:09.000000Z',
                'attempts' => 1,
                'max_attempts' => 3,
                'locked_until' => '2026-04-17T15:10:09.000000Z',
                'failure_reason' => null,
                'execution_summary' => null,
                'run_after' => null,
                'title' => 'Instalar a aplicação "tasksautomation" na infra',
                'description' => 'Fazer o deploy da aplicação "tasksautomation" seguindo os mesmos principios das aplicações com CI/CD.',
                'deliverables' => "Aplicação acessível em dev-automation.lucaskaiut.com.br com SSL com certificado válido\r\nCI/CD rodando com zero downtime\r\nMonitoramento com notificação através do Uptime Kuma",
                'constraints' => null,
                'status' => 'running',
                'review_status' => '',
                'revision_count' => 0,
                'last_reviewed_at' => null,
                'last_reviewed_by' => null,
                'last_reviewer' => null,
                'priority' => 'low',
                'project' => [
                    'id' => 1,
                    'name' => 'Infra',
                    'slug' => 'infra',
                    'description' => 'A documentação do projeto pode ser encontrada em docs/arquitetura.md',
                    'repository_url' => 'https://github.com/lucaskaiut/infra',
                    'default_branch' => 'main',
                    'global_rules' => null,
                    'is_active' => true,
                    'created_at' => '2026-04-16T17:44:50.000000Z',
                    'updated_at' => '2026-04-16T17:44:50.000000Z',
                ],
                'environment_profile' => [
                    'id' => 1,
                    'project_id' => 1,
                    'name' => 'light',
                    'slug' => 'light',
                    'is_default' => false,
                    'docker_compose_yml' => null,
                ],
                'created_at' => '2026-04-16T17:54:26.000000Z',
                'updated_at' => '2026-04-17T15:00:09.000000Z',
            ],
            'message' => 'Heartbeat registrado com sucesso.',
        ];
    }

    private static function tokenSuccessPayload(): array
    {
        return [
            'token' => 'token-worker-123',
        ];
    }

    public function test_claim_maps_api_payload_to_task_data(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->claimUrl() => Http::response(self::claimEndpointSuccessPayload(), 200),
        ]);

        $task = app(TaskApiClient::class)->claimTask();

        $this->assertNotNull($task);
        $this->assertSame(1, $task->id);
        $this->assertSame(1, $task->projectId);
        $this->assertSame(1, $task->environmentProfileId);
        $this->assertSame('worker-1', $task->claimedByWorker);
        $this->assertSame('claimed', $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertSame(3, $task->maxAttempts);
        $this->assertSame('low', $task->priority);

        $this->assertNotNull($task->project);
        $this->assertSame(1, $task->project->id);
        $this->assertSame('Infra', $task->project->name);
        $this->assertSame('infra', $task->project->slug);
        $this->assertSame('https://github.com/lucaskaiut/infra', $task->project->repositoryUrl);
        $this->assertSame('main', $task->project->defaultBranch);
        $this->assertTrue($task->project->isActive);
        $this->assertNull($task->project->globalRules);

        $this->assertNotNull($task->environmentProfile);
        $this->assertSame('light', $task->environmentProfile->slug);
        $this->assertSame(1, $task->environmentProfile->projectId);
        $this->assertFalse($task->environmentProfile->isDefault);
        $this->assertNull($task->environmentProfile->dockerComposeYml);

        $this->assertSame(1, $task->sourcePayload['id']);
        $this->assertArrayHasKey('project', $task->sourcePayload);

        Http::assertSent(function ($request) {
            return $request->url() === $this->tokenUrl()
                && ($request->data()['email'] ?? null) === 'admin@example.com'
                && ($request->data()['password'] ?? null) === 'password'
                && ($request->data()['token_name'] ?? null) === 'worker'
                && ($request->data()['abilities'] ?? null) === ['*'];
        });

        Http::assertSent(function ($request) {
            return $request->url() === $this->claimUrl()
                && $request->hasHeader('Authorization', 'Bearer token-worker-123')
                && ($request->data()['worker_id'] ?? null) === config('worker.worker_id');
        });
    }

    public function test_claim_accepts_task_wrapped_under_task_key(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->claimUrl() => Http::response([
                'task' => [
                    'id' => 5,
                    'title' => 'X',
                ],
                'message' => 'ok',
            ], 200),
        ]);

        $task = app(TaskApiClient::class)->claimTask();

        $this->assertNotNull($task);
        $this->assertSame(5, $task->id);
        $this->assertSame('X', $task->title);
    }

    public function test_claim_returns_null_when_no_task(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->claimUrl() => Http::response(['data' => null, 'message' => 'Nenhuma tarefa'], 200),
        ]);

        $this->assertNull(app(TaskApiClient::class)->claimTask());
    }

    public function test_claim_returns_null_on_204(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->claimUrl() => Http::response(null, 204),
        ]);

        $this->assertNull(app(TaskApiClient::class)->claimTask());
    }

    public function test_heartbeat_maps_api_payload_to_task_data(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->heartbeatUrl(1) => Http::response(self::heartbeatEndpointSuccessPayload(), 200),
        ]);

        $result = app(TaskApiClient::class)->heartbeat(1);

        $this->assertSame('Heartbeat registrado com sucesso.', $result->message);

        $task = $result->task;
        $this->assertSame(1, $task->id);
        $this->assertSame('running', $task->status);
        $this->assertSame('2026-04-17T15:00:09.000000Z', $task->startedAt);
        $this->assertSame('2026-04-17T15:00:09.000000Z', $task->lastHeartbeatAt);
        $this->assertSame('2026-04-17T15:10:09.000000Z', $task->lockedUntil);
        $this->assertNull($task->finishedAt);
        $this->assertSame('2026-04-17T15:00:09.000000Z', $task->updatedAt);
        $this->assertStringContainsString('Uptime Kuma', (string) $task->deliverables);

        Http::assertSent(fn ($request) => $request->url() === $this->heartbeatUrl(1)
            && $request->hasHeader('Authorization', 'Bearer token-worker-123'));
    }

    public function test_heartbeat_204_throws_unexpected_response(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->heartbeatUrl(1) => Http::response(null, 204),
        ]);

        $this->expectException(TaskApiUnexpectedResponseException::class);

        app(TaskApiClient::class)->heartbeat(1);
    }

    public function test_heartbeat_without_task_data_throws(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->heartbeatUrl(1) => Http::response(['data' => null, 'message' => 'erro'], 200),
        ]);

        $this->expectException(TaskApiUnexpectedResponseException::class);

        app(TaskApiClient::class)->heartbeat(1);
    }

    public function test_finish_task_succeeds(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->finishUrl(7) => Http::response([], 200),
        ]);

        app(TaskApiClient::class)->finishTask(7, ['execution_summary' => 'ok', 'status' => 'review']);

        Http::assertSent(function ($request) {
            return $request->url() === $this->finishUrl(7)
                && $request->hasHeader('Authorization', 'Bearer token-worker-123')
                && ($request->data()['execution_summary'] ?? null) === 'ok'
                && ($request->data()['status'] ?? null) === 'review';
        });
    }

    public function test_authentication_failure_on_claim(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->expectException(TaskApiAuthenticationException::class);

        app(TaskApiClient::class)->claimTask();
    }

    public function test_network_failure_is_wrapped(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->expectException(TaskApiNetworkException::class);

        app(TaskApiClient::class)->claimTask();
    }

    public function test_http_error_maps_to_task_api_http_exception(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->claimUrl() => Http::response('Server error', 500),
        ]);

        $this->expectException(TaskApiHttpException::class);

        app(TaskApiClient::class)->claimTask();
    }

    public function test_malformed_task_payload_throws_unexpected_response(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->claimUrl() => Http::response(['data' => ['title' => 'sem id'], 'message' => 'erro'], 200),
        ]);

        $this->expectException(TaskApiUnexpectedResponseException::class);

        app(TaskApiClient::class)->claimTask();
    }

    public function test_access_token_is_reused_across_multiple_requests(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(self::tokenSuccessPayload(), 200),
            $this->claimUrl() => Http::response(self::claimEndpointSuccessPayload(), 200),
            $this->heartbeatUrl(1) => Http::response(self::heartbeatEndpointSuccessPayload(), 200),
        ]);

        $client = app(TaskApiClient::class);

        $client->claimTask();
        $client->heartbeat(1);

        Http::assertSentCount(3);
    }

    public function test_missing_token_in_auth_response_throws_unexpected_response(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response(['message' => 'ok'], 200),
        ]);

        $this->expectException(TaskApiUnexpectedResponseException::class);

        app(TaskApiClient::class)->claimTask();
    }
}
