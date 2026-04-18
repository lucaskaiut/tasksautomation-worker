<?php

namespace App\Services\Publication;

use App\DTOs\PublicationResult;
use App\DTOs\TaskData;
use App\DTOs\WorkspacePaths;
use App\Services\Publication\Exceptions\PublicationException;
use App\Services\Service;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class GitPublicationService extends Service
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function publish(TaskData $task, WorkspacePaths $paths): PublicationResult
    {
        if (! (bool) config('worker.publication.enabled', true)) {
            throw new PublicationException('Publication is disabled by configuration.');
        }

        $this->assertRepositoryPrepared($paths);

        $branchName = $this->branchNameForTask($task);

        $this->runGit(
            ['checkout', '-B', $branchName],
            $paths->repoPath,
            sprintf('Failed to prepare publication branch "%s".', $branchName)
        );

        $this->runGit(
            ['add', '-A'],
            $paths->repoPath,
            'Failed to stage repository changes for publication.'
        );

        $changedFiles = $this->changedFiles($paths->repoPath);

        if ($changedFiles === []) {
            throw new PublicationException('No repository changes were available for publication.');
        }

        $commitMessage = $this->commitMessageForTask($task, count($changedFiles));

        $this->runGit(
            [
                '-c',
                'user.name='.$this->gitUserName(),
                '-c',
                'user.email='.$this->gitUserEmail(),
                'commit',
                '-m',
                $commitMessage,
            ],
            $paths->repoPath,
            'Failed to create publication commit.'
        );

        $commitSha = $this->captureGit(
            ['rev-parse', 'HEAD'],
            $paths->repoPath,
            'Failed to resolve publication commit sha.'
        );

        $this->runGit(
            ['push', '-u', $this->remoteName(), $branchName],
            $paths->repoPath,
            sprintf('Failed to push publication branch "%s".', $branchName)
        );

        $this->writePublicationLog($paths, $branchName, $commitSha, $commitMessage, $changedFiles);

        return new PublicationResult(
            branchName: $branchName,
            commitSha: $commitSha,
            commitMessage: $commitMessage,
            changedFiles: $changedFiles,
        );
    }

    private function assertRepositoryPrepared(WorkspacePaths $paths): void
    {
        if (! $this->filesystem->isDirectory($paths->repoPath)) {
            throw new PublicationException(sprintf('Publication repository directory does not exist: %s', $paths->repoPath));
        }

        if (! $this->filesystem->isDirectory($paths->repoPath.DIRECTORY_SEPARATOR.'.git')) {
            throw new PublicationException(sprintf('Publication requires a git repository in workspace: %s', $paths->repoPath));
        }
    }

    private function branchNameForTask(TaskData $task): string
    {
        return match ($this->resolveImplementationType($task)) {
            'fix' => sprintf('fix/%d', $task->id),
            default => sprintf('feat/%d', $task->id),
        };
    }

    private function resolveImplementationType(TaskData $task): string
    {
        $declared = strtolower(trim((string) ($task->implementationType ?? '')));

        if (in_array($declared, ['feature', 'feat'], true)) {
            return 'feature';
        }

        if (in_array($declared, ['fix', 'bugfix', 'bug', 'hotfix'], true)) {
            return 'fix';
        }

        $context = strtolower(trim(implode(' ', array_filter([
            $task->title,
            $task->description,
            $task->deliverables,
            $task->constraints,
        ]))));

        if ($context !== '' && preg_match('/\b(fix|bug|issue|error|erro|corrig|ajuste|repair|defect|hotfix)\b/u', $context) === 1) {
            return 'fix';
        }

        return 'feature';
    }

    /**
     * @return list<string>
     */
    private function changedFiles(string $repositoryPath): array
    {
        $output = $this->captureGit(
            ['diff', '--cached', '--name-only'],
            $repositoryPath,
            'Failed to inspect staged repository changes.'
        );

        $lines = preg_split("/\r\n|\n|\r/", $output) ?: [];

        return array_values(array_filter(array_map(static fn (string $line): string => trim($line), $lines), static fn (string $line): bool => $line !== ''));
    }

    private function commitMessageForTask(TaskData $task, int $changedFileCount): string
    {
        $verb = $this->resolveImplementationType($task) === 'fix' ? 'fix' : 'implement';
        $noun = $changedFileCount === 1 ? 'file' : 'files';

        return strtolower(sprintf(
            '%s requested task %d changes across %d %s',
            $verb,
            $task->id,
            $changedFileCount,
            $noun
        ));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runGit(array $arguments, string $workingDirectory, string $failureMessage): void
    {
        $process = new Process(
            command: array_merge([$this->gitBinary()], $arguments),
            cwd: $workingDirectory,
            timeout: (float) config('worker.process_timeout_seconds', 3600),
        );

        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        throw new PublicationException($failureMessage.' '.$this->formatProcessOutput($process));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function captureGit(array $arguments, string $workingDirectory, string $failureMessage): string
    {
        $process = new Process(
            command: array_merge([$this->gitBinary()], $arguments),
            cwd: $workingDirectory,
            timeout: (float) config('worker.process_timeout_seconds', 3600),
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new PublicationException($failureMessage.' '.$this->formatProcessOutput($process));
        }

        return trim($process->getOutput());
    }

    /**
     * @param  list<string>  $changedFiles
     */
    private function writePublicationLog(
        WorkspacePaths $paths,
        string $branchName,
        string $commitSha,
        string $commitMessage,
        array $changedFiles,
    ): void {
        if (! $this->filesystem->isDirectory($paths->logsPath)) {
            $this->filesystem->makeDirectory($paths->logsPath, 0755, true);
        }

        $payload = [
            'branch_name' => $branchName,
            'commit_sha' => $commitSha,
            'commit_message' => $commitMessage,
            'changed_files' => $changedFiles,
        ];

        $this->filesystem->put(
            $paths->logsPath.DIRECTORY_SEPARATOR.'publication.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function gitBinary(): string
    {
        return (string) config('worker.repositories.git_binary', 'git');
    }

    private function gitUserName(): string
    {
        return (string) config('worker.publication.git_user_name', 'Tasks Automation Worker');
    }

    private function gitUserEmail(): string
    {
        return (string) config('worker.publication.git_user_email', 'worker@example.com');
    }

    private function remoteName(): string
    {
        return (string) config('worker.publication.remote_name', 'origin');
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
