<?php

namespace Tests\Unit\Publication;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\WorkspacePaths;
use App\Services\Publication\Exceptions\PublicationException;
use App\Services\Publication\GitPublicationService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GitPublicationServiceTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir().'/tasksautomation-worker-publication-'.uniqid('', true);
        File::ensureDirectoryExists($this->tempBasePath);

        config([
            'worker.repositories.git_binary' => 'git',
            'worker.publication.enabled' => true,
            'worker.publication.git_user_name' => 'Tasks Automation Worker',
            'worker.publication.git_user_email' => 'worker@example.com',
            'worker.publication.remote_name' => 'origin',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempBasePath)) {
            File::deleteDirectory($this->tempBasePath);
        }

        parent::tearDown();
    }

    public function test_publish_creates_feature_branch_commits_and_pushes(): void
    {
        [$remotePath, $workspaceRepo] = $this->createWorkspaceRepository();
        file_put_contents($workspaceRepo.'/README.md', "updated\n");
        file_put_contents($workspaceRepo.'/new-file.txt', "hello\n");

        $paths = $this->makeWorkspacePaths($workspaceRepo, 'feature');
        $task = $this->makeTask(implementationType: 'feature', title: 'Implementar ajuste');

        $result = app(GitPublicationService::class)->publish($task, $paths);

        $this->assertSame('feat/501', $result->branchName);
        $this->assertSame('implement requested task 501 changes across 2 files', $result->commitMessage);
        $this->assertCount(2, $result->changedFiles);
        $this->assertSame('feat/501', trim($this->captureProcess(['git', 'branch', '--show-current'], $workspaceRepo)));
        $this->assertSame($result->commitSha, trim($this->captureProcess(['git', 'rev-parse', 'HEAD'], $workspaceRepo)));
        $this->assertSame('origin/feat/501', trim($this->captureProcess(['git', 'rev-parse', '--abbrev-ref', 'feat/501@{upstream}'], $workspaceRepo)));
        $this->assertStringContainsString('implement requested task 501 changes across 2 files', $this->captureProcess(['git', 'log', '-1', '--pretty=%s'], $workspaceRepo));
        $this->assertFileExists($paths->logsPath.'/publication.json');
        $this->assertDirectoryExists($remotePath);
    }

    public function test_publish_uses_fix_prefix_when_task_is_fix(): void
    {
        [, $workspaceRepo] = $this->createWorkspaceRepository();
        file_put_contents($workspaceRepo.'/README.md', "bugfix\n");

        $paths = $this->makeWorkspacePaths($workspaceRepo, 'fix');
        $task = $this->makeTask(implementationType: 'fix', title: 'Corrigir validacao');

        $result = app(GitPublicationService::class)->publish($task, $paths);

        $this->assertSame('fix/501', $result->branchName);
        $this->assertSame('fix requested task 501 changes across 1 file', $result->commitMessage);
    }

    public function test_publish_falls_back_to_fix_heuristic_when_api_field_is_missing(): void
    {
        [, $workspaceRepo] = $this->createWorkspaceRepository();
        file_put_contents($workspaceRepo.'/README.md', "bugfix\n");

        $paths = $this->makeWorkspacePaths($workspaceRepo, 'heuristic');
        $task = $this->makeTask(implementationType: null, title: 'Corrigir erro de login');

        $result = app(GitPublicationService::class)->publish($task, $paths);

        $this->assertSame('fix/501', $result->branchName);
    }

    public function test_publish_throws_when_no_changes_exist(): void
    {
        [, $workspaceRepo] = $this->createWorkspaceRepository();

        $this->expectException(PublicationException::class);
        $this->expectExceptionMessage('No repository changes were available for publication.');

        app(GitPublicationService::class)->publish(
            $this->makeTask(implementationType: 'feature', title: 'Implementar algo'),
            $this->makeWorkspacePaths($workspaceRepo, 'no-changes')
        );
    }

    private function createWorkspaceRepository(): array
    {
        $remotePath = $this->tempBasePath.'/remote.git';
        $seedPath = $this->tempBasePath.'/seed';
        $workspaceClonePath = $this->tempBasePath.'/workspace-repo';

        $this->runProcess(['git', 'init', '--bare', $remotePath], $this->tempBasePath);
        $this->runProcess(['git', 'init', '--initial-branch=main', $seedPath], $this->tempBasePath);

        file_put_contents($seedPath.'/README.md', "initial\n");
        $this->runProcess(['git', 'add', 'README.md'], $seedPath);
        $this->commitInRepository($seedPath, 'initial commit');
        $this->runProcess(['git', 'remote', 'add', 'origin', $remotePath], $seedPath);
        $this->runProcess(['git', 'push', '--set-upstream', 'origin', 'main'], $seedPath);

        $this->runProcess(['git', 'clone', $remotePath, $workspaceClonePath], $this->tempBasePath);

        return [$remotePath, $workspaceClonePath];
    }

    private function makeTask(?string $implementationType, string $title): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map([
            'id' => 501,
            'title' => $title,
            'description' => 'Task description',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'worker-1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'implementation_type' => $implementationType,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'medium',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ]);
    }

    private function makeWorkspacePaths(string $repoPath, string $name): WorkspacePaths
    {
        $root = $this->tempBasePath.'/workspace-'.$name;
        File::ensureDirectoryExists($root.'/context');
        File::ensureDirectoryExists($root.'/logs');

        return new WorkspacePaths(
            root: $root,
            repoPath: $repoPath,
            contextPath: $root.'/context',
            logsPath: $root.'/logs',
            dockerComposePath: $root.'/docker-compose.yml',
            rawTaskResponsePath: $root.'/raw-task-response.json',
            taskJsonPath: $root.'/task.json',
            promptMdPath: $root.'/prompt.md',
        );
    }

    private function commitInRepository(string $repositoryPath, string $message): void
    {
        $this->runProcess(
            ['git', '-c', 'user.name=Test User', '-c', 'user.email=test@example.com', 'commit', '-m', $message],
            $repositoryPath
        );
    }

    private function runProcess(array $command, ?string $cwd = null): void
    {
        $process = new Process($command, $cwd);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getOutput().' '.$process->getErrorOutput()));
        }
    }

    private function captureProcess(array $command, ?string $cwd = null): string
    {
        $process = new Process($command, $cwd);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getOutput().' '.$process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }
}
