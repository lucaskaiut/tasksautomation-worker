<?php

namespace Tests\Unit\Execution;

use App\DTOs\ExecutionLoopResult;
use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\PublicationResult;
use App\DTOs\RepositoryResolution;
use App\DTOs\RepositorySyncResult;
use App\DTOs\WorkspacePaths;
use App\Services\Api\TaskApiClient;
use App\Services\Execution\ExecutionLoopService;
use App\Services\Execution\WorkerRunnerService;
use App\Services\Notifications\TaskStatusNotificationOrchestrator;
use App\Services\Publication\GitPublicationService;
use App\Services\Repository\ProjectRepositoryResolver;
use App\Services\Repository\RepositorySyncService;
use App\Services\Workspace\WorkspaceService;
use Mockery;
use Tests\TestCase;

class WorkerNotificationIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_run_cycle_success_reports_and_notifies_once(): void
    {
        config(['worker.worker_id' => 'worker-local-01']);

        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths();

        $api = Mockery::mock(TaskApiClient::class);
        $api->shouldReceive('claimTask')->once()->andReturn($task);
        $api->shouldReceive('finishTask')->once()->with($task->id, Mockery::on(function (array $payload): bool {
            return ($payload['status'] ?? null) === 'review';
        }));
        $this->app->instance(TaskApiClient::class, $api);

        $orchestrator = Mockery::mock(TaskStatusNotificationOrchestrator::class);
        $orchestrator->shouldReceive('notify')->once()->withArgs(function ($notification): bool {
            return $notification->task->id === 501
                && $notification->result === 'success'
                && $notification->reportedStatus === 'review';
        });
        $this->app->instance(TaskStatusNotificationOrchestrator::class, $orchestrator);

        $this->bindExecutionFlowDependencies($task, $paths, true);

        $result = app(WorkerRunnerService::class)->runCycle();

        $this->assertTrue($result->succeeded);
    }

    public function test_run_cycle_failure_reports_and_notifies_once(): void
    {
        config(['worker.worker_id' => 'worker-local-01']);

        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths();

        $api = Mockery::mock(TaskApiClient::class);
        $api->shouldReceive('claimTask')->once()->andReturn($task);
        $api->shouldReceive('finishTask')->once()->with($task->id, Mockery::on(function (array $payload): bool {
            return ($payload['status'] ?? null) === 'failed';
        }));
        $this->app->instance(TaskApiClient::class, $api);

        $orchestrator = Mockery::mock(TaskStatusNotificationOrchestrator::class);
        $orchestrator->shouldReceive('notify')->once()->withArgs(function ($notification): bool {
            return $notification->task->id === 501
                && $notification->result === 'failure'
                && $notification->reportedStatus === 'failed';
        });
        $this->app->instance(TaskStatusNotificationOrchestrator::class, $orchestrator);

        $this->bindExecutionFlowDependencies($task, $paths, false);

        $result = app(WorkerRunnerService::class)->runCycle();

        $this->assertFalse($result->succeeded);
    }

    private function bindExecutionFlowDependencies(\App\DTOs\TaskData $task, WorkspacePaths $paths, bool $success): void
    {
        $workspace = Mockery::mock(WorkspaceService::class);
        $workspace->shouldReceive('prepare')->once()->andReturn($paths);
        $workspace->shouldReceive('cleanup')->once()->with($task->id, $success);
        $this->app->instance(WorkspaceService::class, $workspace);

        $resolver = Mockery::mock(ProjectRepositoryResolver::class);
        $resolver->shouldReceive('resolveForTask')->once()->andReturn(new RepositoryResolution(
            strategy: ProjectRepositoryResolver::STRATEGY_LOCAL_EXISTING,
            expectedBasePath: '/tmp/local/repo',
            repositoryUrl: 'https://github.com/acme/project',
            defaultBranch: 'main',
        ));
        $this->app->instance(ProjectRepositoryResolver::class, $resolver);

        $sync = Mockery::mock(RepositorySyncService::class);
        $sync->shouldReceive('syncToWorkspace')->once()->andReturn(new RepositorySyncResult(
            strategy: ProjectRepositoryResolver::STRATEGY_LOCAL_EXISTING,
            cachePath: '/tmp/local/repo',
            workspaceRepositoryPath: $paths->repoPath,
            defaultBranch: 'main',
        ));
        $this->app->instance(RepositorySyncService::class, $sync);

        $loop = Mockery::mock(ExecutionLoopService::class);
        $loop->shouldReceive('run')->once()->with($task, $paths)->andReturn(new ExecutionLoopResult(
            succeeded: $success,
            attemptsUsed: $success ? 1 : 3,
            iterations: [],
            finalTechnicalError: $success ? null : 'validation failed',
        ));
        $this->app->instance(ExecutionLoopService::class, $loop);

        $publication = Mockery::mock(GitPublicationService::class);
        if ($success) {
            $publication->shouldReceive('publish')->once()->with($task, $paths)->andReturn(new PublicationResult(
                branchName: 'feat/501',
                commitSha: 'abc123',
                commitMessage: 'implement requested task 501 changes across 2 files',
                changedFiles: ['app/Service.php', 'tests/ServiceTest.php'],
            ));
        } else {
            $publication->shouldNotReceive('publish');
        }
        $this->app->instance(GitPublicationService::class, $publication);
    }

    private function makeTask(): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map([
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
        ]);
    }

    private function makeWorkspacePaths(): WorkspacePaths
    {
        return new WorkspacePaths(
            root: '/tmp/workspaces/501',
            repoPath: '/tmp/workspaces/501/repo',
            contextPath: '/tmp/workspaces/501/context',
            logsPath: '/tmp/workspaces/501/logs',
            dockerComposePath: '/tmp/workspaces/501/docker-compose.yml',
            rawTaskResponsePath: '/tmp/workspaces/501/raw-task-response.json',
            taskJsonPath: '/tmp/workspaces/501/task.json',
            promptMdPath: '/tmp/workspaces/501/prompt.md',
        );
    }
}
