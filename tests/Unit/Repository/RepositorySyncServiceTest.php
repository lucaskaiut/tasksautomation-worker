<?php

namespace Tests\Unit\Repository;

use App\DTOs\RepositoryResolution;
use App\DTOs\WorkspacePaths;
use App\Services\Repository\Exceptions\RepositoryCheckoutException;
use App\Services\Repository\Exceptions\RepositoryCloneException;
use App\Services\Repository\ProjectRepositoryResolver;
use App\Services\Repository\RepositorySyncService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RepositorySyncServiceTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir().'/tasksautomation-worker-sync-'.uniqid('', true);
        File::ensureDirectoryExists($this->tempBasePath);
        config(['worker.repositories.git_binary' => 'git']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempBasePath)) {
            File::deleteDirectory($this->tempBasePath);
        }

        parent::tearDown();
    }

    public function test_clone_initial_successfully_materializes_workspace_repository(): void
    {
        [$remotePath] = $this->createRemoteRepositoryWithBranches();
        $workspacePaths = $this->makeWorkspacePaths('clone-success');

        $result = app(RepositorySyncService::class)->syncToWorkspace(
            new RepositoryResolution(
                strategy: ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE,
                expectedBasePath: $this->tempBasePath.'/cache/project',
                repositoryUrl: $remotePath,
                defaultBranch: 'main',
            ),
            $workspacePaths
        );

        $this->assertSame(ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE, $result->strategy);
        $this->assertDirectoryExists($result->cachePath);
        $this->assertDirectoryExists($result->workspaceRepositoryPath);
        $this->assertFileExists($result->workspaceRepositoryPath.'/README.md');
        $this->assertSame('main', trim(file_get_contents($result->workspaceRepositoryPath.'/branch.txt')));
    }

    public function test_updates_existing_cached_repository_before_copying_to_workspace(): void
    {
        [$remotePath, $seedPath] = $this->createRemoteRepositoryWithBranches();
        $resolution = new RepositoryResolution(
            strategy: ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE,
            expectedBasePath: $this->tempBasePath.'/cache/project',
            repositoryUrl: $remotePath,
            defaultBranch: 'main',
        );

        app(RepositorySyncService::class)->syncToWorkspace($resolution, $this->makeWorkspacePaths('first-run'));

        file_put_contents($seedPath.'/README.md', "updated\n");
        $this->runProcess(['git', 'add', 'README.md'], $seedPath);
        $this->commitInRepository($seedPath, 'update main');
        $this->runProcess(['git', 'push', 'origin', 'main'], $seedPath);

        $secondWorkspace = $this->makeWorkspacePaths('second-run');
        app(RepositorySyncService::class)->syncToWorkspace($resolution, $secondWorkspace);

        $this->assertSame("updated\n", file_get_contents($secondWorkspace->repoPath.'/README.md'));
    }

    public function test_checks_out_default_branch_inside_workspace_repository(): void
    {
        [$remotePath] = $this->createRemoteRepositoryWithBranches();
        $workspacePaths = $this->makeWorkspacePaths('checkout-develop');

        app(RepositorySyncService::class)->syncToWorkspace(
            new RepositoryResolution(
                strategy: ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE,
                expectedBasePath: $this->tempBasePath.'/cache/project-develop',
                repositoryUrl: $remotePath,
                defaultBranch: 'develop',
            ),
            $workspacePaths
        );

        $headBranch = trim($this->captureProcess(['git', 'branch', '--show-current'], $workspacePaths->repoPath));

        $this->assertSame('develop', $headBranch);
        $this->assertSame("develop\n", file_get_contents($workspacePaths->repoPath.'/branch.txt'));
    }

    public function test_clone_failure_is_reported_with_specific_exception(): void
    {
        $this->expectException(RepositoryCloneException::class);
        $this->expectExceptionMessage('Failed to clone repository');

        app(RepositorySyncService::class)->syncToWorkspace(
            new RepositoryResolution(
                strategy: ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE,
                expectedBasePath: $this->tempBasePath.'/cache/missing-project',
                repositoryUrl: $this->tempBasePath.'/missing-remote.git',
                defaultBranch: 'main',
            ),
            $this->makeWorkspacePaths('clone-failure')
        );
    }

    public function test_checkout_failure_is_reported_with_specific_exception(): void
    {
        [$remotePath] = $this->createRemoteRepositoryWithBranches();

        $this->expectException(RepositoryCheckoutException::class);
        $this->expectExceptionMessage('Failed to checkout branch');

        app(RepositorySyncService::class)->syncToWorkspace(
            new RepositoryResolution(
                strategy: ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE,
                expectedBasePath: $this->tempBasePath.'/cache/project-missing-branch',
                repositoryUrl: $remotePath,
                defaultBranch: 'feature/missing',
            ),
            $this->makeWorkspacePaths('checkout-failure')
        );
    }

    private function createRemoteRepositoryWithBranches(): array
    {
        $remotePath = $this->tempBasePath.'/remote.git';
        $seedPath = $this->tempBasePath.'/seed';

        $this->runProcess(['git', 'init', '--bare', $remotePath], $this->tempBasePath);
        $this->runProcess(['git', 'init', '--initial-branch=main', $seedPath], $this->tempBasePath);

        file_put_contents($seedPath.'/README.md', "initial\n");
        file_put_contents($seedPath.'/branch.txt', "main\n");

        $this->runProcess(['git', 'add', 'README.md', 'branch.txt'], $seedPath);
        $this->commitInRepository($seedPath, 'initial commit');
        $this->runProcess(['git', 'remote', 'add', 'origin', $remotePath], $seedPath);
        $this->runProcess(['git', 'push', '--set-upstream', 'origin', 'main'], $seedPath);

        $this->runProcess(['git', 'checkout', '-b', 'develop'], $seedPath);
        file_put_contents($seedPath.'/branch.txt', "develop\n");
        $this->runProcess(['git', 'add', 'branch.txt'], $seedPath);
        $this->commitInRepository($seedPath, 'develop branch');
        $this->runProcess(['git', 'push', '--set-upstream', 'origin', 'develop'], $seedPath);
        $this->runProcess(['git', 'checkout', 'main'], $seedPath);

        return [$remotePath, $seedPath];
    }

    private function makeWorkspacePaths(string $name): WorkspacePaths
    {
        $root = $this->tempBasePath.'/workspace-'.$name;
        File::ensureDirectoryExists($root);
        File::ensureDirectoryExists($root.'/repo');
        File::ensureDirectoryExists($root.'/context');
        File::ensureDirectoryExists($root.'/logs');

        return new WorkspacePaths(
            root: $root,
            repoPath: $root.'/repo',
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

        return $process->getOutput();
    }
}
