<?php

namespace App\Services\Repository;

use App\DTOs\RepositoryResolution;
use App\DTOs\RepositorySyncResult;
use App\DTOs\WorkspacePaths;
use App\Services\Repository\Exceptions\RepositoryCheckoutException;
use App\Services\Repository\Exceptions\RepositoryCloneException;
use App\Services\Repository\Exceptions\RepositoryUpdateException;
use App\Services\Service;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class RepositorySyncService extends Service
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function syncToWorkspace(RepositoryResolution $resolution, WorkspacePaths $workspacePaths): RepositorySyncResult
    {
        $cachePath = match ($resolution->strategy) {
            ProjectRepositoryResolver::STRATEGY_LOCAL_EXISTING => $this->prepareLocalExistingRepository($resolution),
            ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE => $this->prepareAutomaticCloneRepository($resolution),
            default => throw new RepositoryUpdateException(
                sprintf('Unsupported repository sync strategy "%s".', $resolution->strategy)
            ),
        };

        $this->materializeWorkspaceRepository($cachePath, $workspacePaths->repoPath);
        $this->checkoutBranch($workspacePaths->repoPath, $resolution->defaultBranch);

        return new RepositorySyncResult(
            strategy: $resolution->strategy,
            cachePath: $cachePath,
            workspaceRepositoryPath: $workspacePaths->repoPath,
            defaultBranch: $resolution->defaultBranch,
        );
    }

    private function prepareLocalExistingRepository(RepositoryResolution $resolution): string
    {
        if (! $this->filesystem->isDirectory($resolution->expectedBasePath)) {
            throw new RepositoryUpdateException(sprintf(
                'Local repository path is not available for sync: %s',
                $resolution->expectedBasePath
            ));
        }

        return $resolution->expectedBasePath;
    }

    private function prepareAutomaticCloneRepository(RepositoryResolution $resolution): string
    {
        if ($this->filesystem->isDirectory($resolution->expectedBasePath.DIRECTORY_SEPARATOR.'.git')) {
            $this->updateAutomaticCloneRepository($resolution);

            return $resolution->expectedBasePath;
        }

        $parentDirectory = dirname($resolution->expectedBasePath);

        if (! $this->filesystem->isDirectory($parentDirectory)) {
            $this->filesystem->makeDirectory($parentDirectory, 0755, true);
        }

        $this->cloneRepository($resolution);
        $this->checkoutBranch($resolution->expectedBasePath, $resolution->defaultBranch);
        $this->hardResetToOriginBranch($resolution->expectedBasePath, $resolution->defaultBranch);

        return $resolution->expectedBasePath;
    }

    private function updateAutomaticCloneRepository(RepositoryResolution $resolution): void
    {
        $this->runGit(
            ['remote', 'set-url', 'origin', $resolution->repositoryUrl],
            $resolution->expectedBasePath,
            RepositoryUpdateException::class,
            'Failed to update repository origin before sync.'
        );

        $this->runGit(
            ['fetch', '--prune', 'origin'],
            $resolution->expectedBasePath,
            RepositoryUpdateException::class,
            'Failed to fetch repository updates before sync.'
        );

        $this->checkoutBranch($resolution->expectedBasePath, $resolution->defaultBranch);
        $this->hardResetToOriginBranch($resolution->expectedBasePath, $resolution->defaultBranch);
    }

    private function cloneRepository(RepositoryResolution $resolution): void
    {
        $this->runGit(
            ['clone', '--origin', 'origin', $resolution->repositoryUrl, $resolution->expectedBasePath],
            null,
            RepositoryCloneException::class,
            sprintf('Failed to clone repository from "%s".', $resolution->repositoryUrl)
        );
    }

    private function checkoutBranch(string $repositoryPath, string $branch): void
    {
        $this->runGit(
            ['checkout', $branch],
            $repositoryPath,
            RepositoryCheckoutException::class,
            sprintf('Failed to checkout branch "%s".', $branch)
        );
    }

    private function hardResetToOriginBranch(string $repositoryPath, string $branch): void
    {
        $this->runGit(
            ['reset', '--hard', 'origin/'.$branch],
            $repositoryPath,
            RepositoryUpdateException::class,
            sprintf('Failed to align repository with origin/%s.', $branch)
        );
    }

    private function materializeWorkspaceRepository(string $sourcePath, string $workspaceRepositoryPath): void
    {
        if ($this->filesystem->exists($workspaceRepositoryPath)) {
            $this->filesystem->deleteDirectory($workspaceRepositoryPath);
            $this->filesystem->delete($workspaceRepositoryPath);
        }

        if (! $this->filesystem->copyDirectory($sourcePath, $workspaceRepositoryPath)) {
            throw new RepositoryUpdateException(sprintf(
                'Failed to copy repository from "%s" to workspace "%s".',
                $sourcePath,
                $workspaceRepositoryPath
            ));
        }
    }

    /**
     * @param  class-string<\RuntimeException>  $exceptionClass
     */
    private function runGit(
        array $arguments,
        ?string $workingDirectory,
        string $exceptionClass,
        string $failureMessage,
    ): void {
        $process = new Process(
            command: array_merge([$this->gitBinary()], $arguments),
            cwd: $workingDirectory,
            timeout: (float) config('worker.process_timeout_seconds', 3600),
        );

        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        throw new $exceptionClass($failureMessage.' '.$this->formatProcessOutput($process));
    }

    private function gitBinary(): string
    {
        return (string) config('worker.repositories.git_binary', 'git');
    }

    private function formatProcessOutput(Process $process): string
    {
        $output = trim($process->getOutput().' '.$process->getErrorOutput());

        if ($output === '') {
            return sprintf('(exit code %d)', $process->getExitCode() ?? 1);
        }

        return sprintf('(exit code %d) %s', $process->getExitCode() ?? 1, $output);
    }
}
