<?php

namespace App\Services\Validation;

use App\DTOs\TaskData;
use App\DTOs\ValidationCommandResult;
use App\DTOs\ValidationResult;
use App\DTOs\WorkspacePaths;
use App\Services\Service;
use App\Services\Execution\DockerComposeEnvironmentService;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class AutomaticValidationService extends Service
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly DockerComposeEnvironmentService $dockerComposeEnvironmentService,
    ) {}

    public function validate(TaskData $task, WorkspacePaths $paths): ValidationResult
    {
        $commands = $this->resolveCommands($task);

        if ($commands === []) {
            $this->filesystem->put($paths->logsPath.DIRECTORY_SEPARATOR.'validation-summary.log', "No validation commands configured.\n");

            return new ValidationResult(
                passed: true,
                commands: [],
                firstFailureOutput: null,
            );
        }

        $results = [];
        $firstFailureOutput = null;
        $stopOnFailure = (bool) config('worker.validation.stop_on_failure', true);

        foreach ($commands as $index => $command) {
            $executedCommand = $this->dockerComposeEnvironmentService->wrapCommandForTask($task, $paths, $command);
            $result = $this->runCommand($executedCommand, $paths->repoPath);
            $results[] = $result;

            $this->filesystem->put(
                $paths->logsPath.DIRECTORY_SEPARATOR.sprintf('validation-%02d.log', $index + 1),
                $this->formatValidationLog($result)
            );

            if (! $result->passed && $firstFailureOutput === null) {
                $firstFailureOutput = $result->output;

                if ($stopOnFailure) {
                    break;
                }
            }
        }

        $aggregate = new ValidationResult(
            passed: ! collect($results)->contains(static fn (ValidationCommandResult $result): bool => ! $result->passed),
            commands: $results,
            firstFailureOutput: $firstFailureOutput,
        );

        $this->filesystem->put(
            $paths->logsPath.DIRECTORY_SEPARATOR.'validation-summary.log',
            $this->formatSummaryLog($aggregate)
        );

        return $aggregate;
    }

    /**
     * @return list<string>
     */
    public function resolveCommands(TaskData $task): array
    {
        $globalCommands = $this->normalizeCommands(config('worker.validation.global_commands', []));
        $profileCommands = [];
        $profileSlug = $task->environmentProfile?->slug;

        if (is_string($profileSlug) && trim($profileSlug) !== '') {
            $configuredMap = config('worker.validation.commands_by_environment_profile_slug', []);
            $configuredCommands = $configuredMap[$profileSlug] ?? [];
            $profileCommands = $this->normalizeCommands(is_array($configuredCommands) ? $configuredCommands : []);
        }

        return array_values(array_merge($globalCommands, $profileCommands));
    }

    private function runCommand(string $command, string $workingDirectory): ValidationCommandResult
    {
        $process = Process::fromShellCommandline(
            command: $command,
            cwd: $workingDirectory,
            timeout: (float) config('worker.process_timeout_seconds', 3600),
        );

        $process->run();

        return new ValidationCommandResult(
            command: $command,
            passed: $process->isSuccessful(),
            output: trim($process->getOutput().$process->getErrorOutput()),
            exitCode: $process->getExitCode() ?? 1,
        );
    }

    /**
     * @param  array<int, mixed>  $commands
     * @return list<string>
     */
    private function normalizeCommands(array $commands): array
    {
        return array_values(array_filter($commands, static fn (mixed $command): bool => is_string($command) && trim($command) !== ''));
    }

    private function formatValidationLog(ValidationCommandResult $result): string
    {
        return implode("\n", [
            'command: '.$result->command,
            'passed: '.($result->passed ? 'true' : 'false'),
            'exit_code: '.$result->exitCode,
            '',
            $result->output,
        ])."\n";
    }

    private function formatSummaryLog(ValidationResult $result): string
    {
        $lines = [
            'passed: '.($result->passed ? 'true' : 'false'),
            'commands_run: '.count($result->commands),
        ];

        if ($result->firstFailureOutput !== null) {
            $lines[] = '';
            $lines[] = 'first_failure_output:';
            $lines[] = $result->firstFailureOutput;
        }

        return implode("\n", $lines)."\n";
    }
}
