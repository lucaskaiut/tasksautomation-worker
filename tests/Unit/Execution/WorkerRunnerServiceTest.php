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
use App\Services\Publication\GitPublicationService;
use App\Services\Reporting\TaskResultReporterService;
use App\Services\Repository\ProjectRepositoryResolver;
use App\Services\Repository\RepositorySyncService;
use App\Services\Workspace\WorkspaceService;
use Mockery;
use Tests\TestCase;

class WorkerRunnerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_run_cycle_returns_idle_when_no_task_is_claimed(): void
    {
        $api = Mockery::mock(TaskApiClient::class);
        $api->shouldReceive('claimTask')->once()->andReturn(null);
        $this->app->instance(TaskApiClient::class, $api);

        $result = app(WorkerRunnerService::class)->runCycle();

        $this->assertFalse($result->hadTask);
        $this->assertTrue($result->succeeded);
        $this->assertSame('No task available.', $result->message);
    }

    public function test_run_cycle_processes_task_and_reports_success(): void
    {
        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths();
        $progressMessages = [];

        $api = Mockery::mock(TaskApiClient::class);
        $api->shouldReceive('claimTask')->once()->andReturn($task);
        $this->app->instance(TaskApiClient::class, $api);

        $workspace = Mockery::mock(WorkspaceService::class);
        $workspace->shouldReceive('prepare')->once()->with($task, ['data' => $task->sourcePayload])->andReturn($paths);
        $workspace->shouldReceive('cleanup')->once()->with($task->id, true);
        $this->app->instance(WorkspaceService::class, $workspace);

        $resolver = Mockery::mock(ProjectRepositoryResolver::class);
        $resolver->shouldReceive('resolveForTask')->once()->with($task)->andReturn(new RepositoryResolution(
            strategy: ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE,
            expectedBasePath: '/tmp/cache/repo',
            repositoryUrl: 'https://github.com/acme/project',
            defaultBranch: 'main',
        ));
        $this->app->instance(ProjectRepositoryResolver::class, $resolver);

        $sync = Mockery::mock(RepositorySyncService::class);
        $sync->shouldReceive('syncToWorkspace')->once()->andReturn(new RepositorySyncResult(
            strategy: ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE,
            cachePath: '/tmp/cache/repo',
            workspaceRepositoryPath: $paths->repoPath,
            defaultBranch: 'main',
        ));
        $this->app->instance(RepositorySyncService::class, $sync);

        $loop = Mockery::mock(ExecutionLoopService::class);
        $loop->shouldReceive('run')->once()->with($task, $paths)->andReturn(new ExecutionLoopResult(
            succeeded: true,
            attemptsUsed: 1,
            iterations: [],
            finalTechnicalError: null,
        ));
        $this->app->instance(ExecutionLoopService::class, $loop);

        $publication = Mockery::mock(GitPublicationService::class);
        $publication->shouldReceive('publish')->once()->with($task, $paths)->andReturn(new PublicationResult(
            branchName: 'feat/501',
            commitSha: 'abc123',
            commitMessage: 'implement requested task 501 changes across 2 files',
            changedFiles: ['app/Service.php', 'tests/ServiceTest.php'],
        ));
        $this->app->instance(GitPublicationService::class, $publication);

        $reporter = Mockery::mock(TaskResultReporterService::class);
        $reporter->shouldReceive('reportTechnicalSuccess')->once()->withArgs(function ($reportedTask, ...$args) use ($task): bool {
            return $reportedTask === $task;
        });
        $reporter->shouldNotReceive('reportFailure');
        $this->app->instance(TaskResultReporterService::class, $reporter);

        $result = app(WorkerRunnerService::class)->runCycle(function (string $message) use (&$progressMessages): void {
            $progressMessages[] = $message;
        });

        $this->assertTrue($result->hadTask);
        $this->assertTrue($result->succeeded);
        $this->assertSame($task->id, $result->taskId);
        $this->assertSame([
            'Task 501 claimed. Starting execution for "Executar task".',
        ], $progressMessages);
    }

    public function test_run_cycle_processes_task_and_reports_failure(): void
    {
        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths();

        $api = Mockery::mock(TaskApiClient::class);
        $api->shouldReceive('claimTask')->once()->andReturn($task);
        $this->app->instance(TaskApiClient::class, $api);

        $workspace = Mockery::mock(WorkspaceService::class);
        $workspace->shouldReceive('prepare')->once()->andReturn($paths);
        $workspace->shouldReceive('cleanup')->once()->with($task->id, false);
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
            succeeded: false,
            attemptsUsed: 3,
            iterations: [],
            finalTechnicalError: 'validation failed',
        ));
        $this->app->instance(ExecutionLoopService::class, $loop);

        $publication = Mockery::mock(GitPublicationService::class);
        $publication->shouldNotReceive('publish');
        $this->app->instance(GitPublicationService::class, $publication);

        $reporter = Mockery::mock(TaskResultReporterService::class);
        $reporter->shouldReceive('reportFailure')->once()->withArgs(function ($reportedTask, ...$args) use ($task): bool {
            return $reportedTask === $task;
        });
        $reporter->shouldNotReceive('reportTechnicalSuccess');
        $this->app->instance(TaskResultReporterService::class, $reporter);

        $result = app(WorkerRunnerService::class)->runCycle();

        $this->assertTrue($result->hadTask);
        $this->assertFalse($result->succeeded);
        $this->assertSame($task->id, $result->taskId);
    }

    public function test_run_cycle_preserves_workspace_and_reports_failure_when_publication_throws(): void
    {
        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths();

        $api = Mockery::mock(TaskApiClient::class);
        $api->shouldReceive('claimTask')->once()->andReturn($task);
        $this->app->instance(TaskApiClient::class, $api);

        $workspace = Mockery::mock(WorkspaceService::class);
        $workspace->shouldReceive('prepare')->once()->andReturn($paths);
        $workspace->shouldReceive('cleanup')->once()->with($task->id, false);
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
            succeeded: true,
            attemptsUsed: 1,
            iterations: [],
            finalTechnicalError: null,
        ));
        $this->app->instance(ExecutionLoopService::class, $loop);

        $publication = Mockery::mock(GitPublicationService::class);
        $publication->shouldReceive('publish')->once()->andThrow(new \RuntimeException('No repository changes were available for publication.'));
        $this->app->instance(GitPublicationService::class, $publication);

        $reporter = Mockery::mock(TaskResultReporterService::class);
        $reporter->shouldReceive('reportFailure')->once()->withArgs(function ($reportedTask, ...$args) use ($task): bool {
            return $reportedTask === $task;
        });
        $reporter->shouldNotReceive('reportTechnicalSuccess');
        $this->app->instance(TaskResultReporterService::class, $reporter);

        $result = app(WorkerRunnerService::class)->runCycle();

        $this->assertTrue($result->hadTask);
        $this->assertFalse($result->succeeded);
        $this->assertSame($task->id, $result->taskId);
    }

    public function test_run_cycle_skips_publication_for_analysis_stage(): void
    {
        $task = $this->makeTask([
            'current_stage' => 'analysis',
        ]);
        $paths = $this->makeWorkspacePaths();

        $api = Mockery::mock(TaskApiClient::class);
        $api->shouldReceive('claimTask')->once()->andReturn($task);
        $this->app->instance(TaskApiClient::class, $api);

        $workspace = Mockery::mock(WorkspaceService::class);
        $workspace->shouldReceive('prepare')->once()->andReturn($paths);
        $workspace->shouldReceive('cleanup')->once()->with($task->id, true);
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
            succeeded: true,
            attemptsUsed: 1,
            iterations: [],
            finalTechnicalError: null,
        ));
        $this->app->instance(ExecutionLoopService::class, $loop);

        $publication = Mockery::mock(GitPublicationService::class);
        $publication->shouldNotReceive('publish');
        $this->app->instance(GitPublicationService::class, $publication);

        $reporter = Mockery::mock(TaskResultReporterService::class);
        $reporter->shouldReceive('reportTechnicalSuccess')->once()->withArgs(function ($reportedTask, $summary, $branchName, $commitSha) use ($task): bool {
            return $reportedTask === $task
                && str_contains($summary, 'foi analisada com sucesso')
                && $branchName === null
                && $commitSha === null;
        });
        $reporter->shouldNotReceive('reportFailure');
        $this->app->instance(TaskResultReporterService::class, $reporter);

        $result = app(WorkerRunnerService::class)->runCycle();

        $this->assertTrue($result->hadTask);
        $this->assertTrue($result->succeeded);
        $this->assertSame($task->id, $result->taskId);
    }

    private function makeTask(array $overrides = []): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map(array_replace_recursive([
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
            'current_stage' => 'implementation:backend',
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
        ], $overrides));
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
