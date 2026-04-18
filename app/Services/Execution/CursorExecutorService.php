<?php

namespace App\Services\Execution;

use App\DTOs\ExecutionResult;
use App\DTOs\WorkspacePaths;
use App\Services\Execution\Exceptions\CursorBinaryNotFoundException;
use App\Services\Execution\Exceptions\CursorExecutionContextException;
use App\Services\Execution\Exceptions\CursorExecutionTimeoutException;
use App\Services\Service;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class CursorExecutorService extends Service
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @return list<string>
     */
    public function buildCommand(WorkspacePaths $paths): array
    {
        $command = [
            $this->resolveCodexBinary(),
            'exec',
            '--full-auto',
            '--sandbox',
            $this->sandboxMode(),
        ];

        if ($this->useEphemeralMode()) {
            $command[] = '--ephemeral';
        }

        if ($this->skipGitRepoCheck()) {
            $command[] = '--skip-git-repo-check';
        }

        $command[] = $this->filesystem->get($paths->promptMdPath);

        return $command;
    }

    public function execute(WorkspacePaths $paths, ?callable $onTick = null): ExecutionResult
    {
        $this->assertExecutionContextPrepared($paths);

        $command = $this->buildCommand($paths);
        $startedAt = microtime(true);
        $process = new Process(
            command: $command,
            cwd: $paths->repoPath,
            timeout: (float) config('worker.process_timeout_seconds', 3600),
        );

        try {
            $process->start();

            while ($process->isRunning()) {
                $onTick?->__invoke();
                usleep($this->pollIntervalMicroseconds());
                $process->checkTimeout();
            }

            $process->wait();
        } catch (ProcessTimedOutException $e) {
            $this->writeLogs($paths, $command, $process->getOutput(), $process->getErrorOutput());

            throw new CursorExecutionTimeoutException(
                sprintf('Codex process timed out after %.2f seconds.', $process->getTimeout() ?? 0.0),
                0,
                $e
            );
        }

        $result = new ExecutionResult(
            succeeded: $process->isSuccessful(),
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            durationSeconds: microtime(true) - $startedAt,
        );

        $this->writeLogs($paths, $command, $result->stdout, $result->stderr);

        return $result;
    }

    private function assertExecutionContextPrepared(WorkspacePaths $paths): void
    {
        if (! $this->filesystem->isDirectory($paths->repoPath)) {
            throw new CursorExecutionContextException(
                sprintf('Cannot execute Codex without prepared repository directory: %s', $paths->repoPath)
            );
        }

        if (! $this->filesystem->exists($paths->promptMdPath)) {
            throw new CursorExecutionContextException(
                sprintf('Cannot execute Codex without prompt file: %s', $paths->promptMdPath)
            );
        }

        if (! $this->filesystem->isDirectory($paths->logsPath)) {
            $this->filesystem->makeDirectory($paths->logsPath, 0755, true);
        }
    }

    private function resolveCodexBinary(): string
    {
        $binary = (string) config('worker.codex.binary', 'codex');

        if ($binary === '') {
            throw new CursorBinaryNotFoundException('Codex binary is not configured.');
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            if (! is_file($binary) || ! is_executable($binary)) {
                throw new CursorBinaryNotFoundException(
                    sprintf('Configured Codex binary was not found or is not executable: %s', $binary)
                );
            }

            return $binary;
        }

        $resolved = (new ExecutableFinder)->find($binary);

        if ($resolved === null) {
            throw new CursorBinaryNotFoundException(
                sprintf('Configured Codex binary was not found on PATH: %s', $binary)
            );
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $command
     */
    private function writeLogs(WorkspacePaths $paths, array $command, string $stdout, string $stderr): void
    {
        $this->filesystem->put($paths->logsPath.DIRECTORY_SEPARATOR.'codex-command.txt', $this->formatCommand($command));
        $this->filesystem->put($paths->logsPath.DIRECTORY_SEPARATOR.'codex-stdout.log', $stdout);
        $this->filesystem->put($paths->logsPath.DIRECTORY_SEPARATOR.'codex-stderr.log', $stderr);
    }

    /**
     * @param  list<string>  $command
     */
    private function formatCommand(array $command): string
    {
        return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command))."\n";
    }

    private function pollIntervalMicroseconds(): int
    {
        return max(1, (int) config('worker.heartbeat.poll_interval_milliseconds', 250)) * 1000;
    }

    private function sandboxMode(): string
    {
        return (string) config('worker.codex.sandbox', 'workspace-write');
    }

    private function useEphemeralMode(): bool
    {
        return (bool) config('worker.codex.ephemeral', true);
    }

    private function skipGitRepoCheck(): bool
    {
        return (bool) config('worker.codex.skip_git_repo_check', false);
    }
}
