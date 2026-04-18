<?php

namespace App\Services\Execution;

use App\DTOs\DockerComposeContext;
use App\DTOs\TaskData;
use App\DTOs\WorkspacePaths;
use App\Services\Execution\Exceptions\DockerComposeException;
use App\Services\Service;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class DockerComposeEnvironmentService extends Service
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function bootstrap(TaskData $task, WorkspacePaths $paths): DockerComposeContext
    {
        $context = $this->contextForTask($task, $paths);

        if (! $context->enabled) {
            return $context;
        }

        $this->runDockerCompose(
            ['up', '-d'],
            $paths->root,
            sprintf('Failed to start docker compose environment for task %d.', $task->id),
            $context->composeFilePath
        );

        return $context;
    }

    public function teardown(WorkspacePaths $paths): void
    {
        if (! $this->filesystem->exists($paths->dockerComposePath)) {
            return;
        }

        if (! (bool) config('worker.docker.shutdown_after_task', true)) {
            return;
        }

        $this->runDockerCompose(
            ['down', '--remove-orphans'],
            $paths->root,
            'Failed to stop docker compose environment after task execution.',
            $paths->dockerComposePath
        );
    }

    public function contextForTask(TaskData $task, WorkspacePaths $paths): DockerComposeContext
    {
        $enabled = $this->filesystem->exists($paths->dockerComposePath)
            && trim((string) $task->environmentProfile?->dockerComposeYml) !== '';

        return new DockerComposeContext(
            enabled: $enabled,
            composeFilePath: $paths->dockerComposePath,
            execService: $enabled ? $this->resolveExecService($task, $paths) : null,
        );
    }

    public function wrapCommandForTask(TaskData $task, WorkspacePaths $paths, string $command): string
    {
        $context = $this->contextForTask($task, $paths);

        if (! $context->enabled || $context->execService === null) {
            return $command;
        }

        return sprintf(
            "%s compose -f %s exec -T %s sh -lc %s",
            escapeshellarg($this->dockerBinary()),
            escapeshellarg($context->composeFilePath),
            escapeshellarg($context->execService),
            escapeshellarg($command)
        );
    }

    private function resolveExecService(TaskData $task, WorkspacePaths $paths): string
    {
        $profileSlug = $task->environmentProfile?->slug;
        $configuredMap = config('worker.docker.exec_service_by_environment_profile_slug', []);

        if (is_string($profileSlug) && isset($configuredMap[$profileSlug]) && is_string($configuredMap[$profileSlug]) && trim($configuredMap[$profileSlug]) !== '') {
            return trim($configuredMap[$profileSlug]);
        }

        $detected = $this->detectFirstComposeService($paths->dockerComposePath);

        if ($detected !== null) {
            return $detected;
        }

        return (string) config('worker.docker.default_exec_service', 'app');
    }

    private function detectFirstComposeService(string $composePath): ?string
    {
        if (! $this->filesystem->exists($composePath)) {
            return null;
        }

        $content = (string) $this->filesystem->get($composePath);
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $inServices = false;

        foreach ($lines as $line) {
            if (trim($line) === 'services:') {
                $inServices = true;

                continue;
            }

            if (! $inServices) {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_-]+:\s*$/', $line) === 1) {
                $inServices = false;

                continue;
            }

            if (preg_match('/^\s{2}([A-Za-z0-9._-]+):\s*$/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function runDockerCompose(array $arguments, string $workingDirectory, string $message, string $composeFilePath): void
    {
        $process = new Process(
            command: array_merge($this->dockerComposeBaseCommand($composeFilePath), $arguments),
            cwd: $workingDirectory,
            timeout: (float) config('worker.process_timeout_seconds', 3600),
        );

        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        throw new DockerComposeException($message.' '.$this->formatProcessOutput($process));
    }

    private function dockerBinary(): string
    {
        return (string) config('worker.docker.binary', 'docker');
    }

    /**
     * @return list<string>
     */
    private function dockerComposeBaseCommand(string $composeFilePath): array
    {
        $binary = $this->dockerBinary();
        $binaryName = basename($binary);

        if (in_array($binaryName, ['docker-compose', 'docker-compose.exe'], true)) {
            return [$binary, '-f', $composeFilePath];
        }

        return [$binary, 'compose', '-f', $composeFilePath];
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
