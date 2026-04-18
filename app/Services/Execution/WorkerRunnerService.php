<?php

namespace App\Services\Execution;

use App\DTOs\ExecutionLoopResult;
use App\DTOs\PublicationResult;
use App\DTOs\RepositorySyncResult;
use App\DTOs\TaskData;
use App\DTOs\WorkspacePaths;
use App\Services\Api\TaskApiClient;
use App\Services\Publication\GitPublicationService;
use App\Services\Reporting\TaskResultReporterService;
use App\Services\Repository\ProjectRepositoryResolver;
use App\Services\Repository\RepositorySyncService;
use App\Services\Service;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

class WorkerRunnerService extends Service
{
    public function __construct(
        private readonly TaskApiClient $taskApiClient,
        private readonly WorkspaceService $workspaceService,
        private readonly ProjectRepositoryResolver $repositoryResolver,
        private readonly RepositorySyncService $repositorySyncService,
        private readonly DockerComposeEnvironmentService $dockerComposeEnvironmentService,
        private readonly ExecutionLoopService $executionLoopService,
        private readonly GitPublicationService $gitPublicationService,
        private readonly TaskResultReporterService $taskResultReporterService,
        private readonly Filesystem $filesystem,
    ) {}

    public function runCycle(?callable $onProgress = null): WorkerCycleResult
    {
        $task = $this->taskApiClient->claimTask();

        if ($task === null) {
            return new WorkerCycleResult(
                hadTask: false,
                taskId: null,
                succeeded: true,
                message: 'No task available.',
            );
        }

        $onProgress?->__invoke(sprintf(
            'Task %d claimed. Starting execution for "%s".',
            $task->id,
            $task->title,
        ));

        return $this->runClaimedTask($task, $onProgress);
    }

    public function runClaimedTask(TaskData $task, ?callable $onProgress = null): WorkerCycleResult
    {
        $paths = null;
        $taskSucceeded = false;
        $failureStage = 'workspace_prepare';

        try {
            $paths = $this->workspaceService->prepare($task, [
                'data' => $task->sourcePayload,
            ]);

            $failureStage = 'repository_resolution';
            $resolution = $this->repositoryResolver->resolveForTask($task);

            $failureStage = 'repository_sync';
            $repositorySync = $this->repositorySyncService->syncToWorkspace($resolution, $paths);

            $failureStage = 'docker_bootstrap';
            $this->dockerComposeEnvironmentService->bootstrap($task, $paths);

            $failureStage = 'execution_loop';
            $loopResult = $this->executionLoopService->run($task, $paths);

            $failureStage = $loopResult->succeeded ? 'publication' : 'loop_failure_report';
            $this->reportLoopResult($task, $paths, $repositorySync, $loopResult);
            $taskSucceeded = $loopResult->succeeded;

            return new WorkerCycleResult(
                hadTask: true,
                taskId: $task->id,
                succeeded: $loopResult->succeeded,
                message: $loopResult->succeeded
                    ? sprintf('Task %d finished with technical success.', $task->id)
                    : sprintf('Task %d finished with technical failure.', $task->id),
            );
        } catch (Throwable $e) {
            $logsPath = $paths?->logsPath;
            $diagnostics = $paths !== null ? $this->captureGitDiagnostics($paths, 'failure') : null;
            $this->writeFailureContext($paths, $task, $failureStage, $e, $diagnostics);

            $this->taskResultReporterService->reportFailure(
                task: $task,
                executionSummary: 'Worker execution failed before completing the task flow.',
                failureReason: $e->getMessage(),
                metadata: array_filter([
                    'exception_class' => $e::class,
                    'failure_stage' => $failureStage,
                    'workspace_path' => $paths?->root,
                    'diagnostics_path' => $paths?->logsPath.DIRECTORY_SEPARATOR.'failure-context.json',
                    'git_diagnostics_path' => $paths?->logsPath.DIRECTORY_SEPARATOR.'git-diagnostics-failure.json',
                    'git_diagnostics' => $diagnostics,
                ], static fn (mixed $value): bool => $value !== null),
                logsPath: $logsPath,
            );

            return new WorkerCycleResult(
                hadTask: true,
                taskId: $task->id,
                succeeded: false,
                message: sprintf('Task %d failed with exception: %s', $task->id, $e->getMessage()),
            );
        } finally {
            if ($paths !== null) {
                $this->dockerComposeEnvironmentService->teardown($paths);
            }

            $this->workspaceService->cleanup($task->id, $taskSucceeded);
        }
    }

    private function reportLoopResult(
        TaskData $task,
        WorkspacePaths $paths,
        RepositorySyncResult $repositorySync,
        ExecutionLoopResult $loopResult,
    ): void {
        if ($loopResult->succeeded) {
            $prePublicationDiagnostics = $this->captureGitDiagnostics($paths, 'pre-publication');
            $publicationResult = $this->gitPublicationService->publish($task, $paths);

            $this->taskResultReporterService->reportTechnicalSuccess(
                task: $task,
                executionSummary: $this->buildSuccessSummary($task, $loopResult, $publicationResult),
                branchName: $publicationResult->branchName,
                commitSha: $publicationResult->commitSha,
                logsPath: $paths->logsPath,
                metadata: $this->buildMetadata($repositorySync, $loopResult, $publicationResult, $prePublicationDiagnostics, $paths->root),
            );

            return;
        }

        $gitDiagnostics = $this->captureGitDiagnostics($paths, 'loop-failure');
        $this->taskResultReporterService->reportFailure(
            task: $task,
            executionSummary: $this->buildFailureSummary($task, $loopResult),
            failureReason: $loopResult->finalTechnicalError ?? 'Execution loop exhausted attempts.',
            metadata: $this->buildMetadata($repositorySync, $loopResult, null, $gitDiagnostics, $paths->root),
            logsPath: $paths->logsPath,
        );
    }

    private function buildSuccessSummary(
        TaskData $task,
        ExecutionLoopResult $loopResult,
        PublicationResult $publicationResult,
    ): string
    {
        return sprintf(
            'Task "%s" completed successfully after %d attempt(s) and was published to branch %s.',
            $task->title,
            $loopResult->attemptsUsed,
            $publicationResult->branchName,
        );
    }

    private function buildFailureSummary(TaskData $task, ExecutionLoopResult $loopResult): string
    {
        return sprintf(
            'Task "%s" falhou tecnicamente apos %d tentativa(s).',
            $task->title,
            $loopResult->attemptsUsed
        );
    }

    private function buildMetadata(
        RepositorySyncResult $repositorySync,
        ExecutionLoopResult $loopResult,
        ?PublicationResult $publicationResult = null,
        ?array $gitDiagnostics = null,
        ?string $workspacePath = null,
    ): array
    {
        $metadata = [
            'repository_strategy' => $repositorySync->strategy,
            'repository_cache_path' => $repositorySync->cachePath,
            'workspace_repository_path' => $repositorySync->workspaceRepositoryPath,
            'workspace_path' => $workspacePath,
            'default_branch' => $repositorySync->defaultBranch,
            'attempts_used' => $loopResult->attemptsUsed,
            'iterations' => array_map(static fn ($iteration): array => [
                'attempt' => $iteration->attempt,
                'execution_succeeded' => $iteration->executionResult->succeeded,
                'execution_exit_code' => $iteration->executionResult->exitCode,
                'validation_passed' => $iteration->validationResult->passed,
                'prompt_path' => $iteration->promptPath,
                'context_path' => $iteration->contextPath,
            ], $loopResult->iterations),
        ];

        if ($publicationResult !== null) {
            $metadata['publication'] = [
                'branch_name' => $publicationResult->branchName,
                'commit_sha' => $publicationResult->commitSha,
                'commit_message' => $publicationResult->commitMessage,
                'changed_files' => $publicationResult->changedFiles,
            ];
        }

        if ($gitDiagnostics !== null) {
            $metadata['git_diagnostics'] = $gitDiagnostics;
        }

        return $metadata;
    }

    private function captureGitDiagnostics(WorkspacePaths $paths, string $stage): array
    {
        $payload = [
            'stage' => $stage,
            'repository_path' => $paths->repoPath,
            'repository_available' => false,
        ];

        if (! $this->filesystem->isDirectory($paths->repoPath) || ! $this->filesystem->isDirectory($paths->repoPath.DIRECTORY_SEPARATOR.'.git')) {
            $payload['error'] = 'Workspace repository is not available for git diagnostics.';
            $this->writeJsonLog($paths, 'git-diagnostics-'.$stage.'.json', $payload);

            return $payload;
        }

        $payload['repository_available'] = true;
        $payload['branch'] = $this->captureGitOutput($paths->repoPath, ['branch', '--show-current']);
        $payload['head_sha'] = $this->captureGitOutput($paths->repoPath, ['rev-parse', 'HEAD']);
        $payload['status_short'] = $this->captureGitOutput($paths->repoPath, ['status', '--short']);
        $payload['diff_stat'] = $this->captureGitOutput($paths->repoPath, ['diff', '--stat']);
        $payload['diff_cached_name_only'] = $this->captureGitOutput($paths->repoPath, ['diff', '--cached', '--name-only']);

        $this->writeJsonLog($paths, 'git-diagnostics-'.$stage.'.json', $payload);

        return $payload;
    }

    private function writeFailureContext(
        ?WorkspacePaths $paths,
        TaskData $task,
        string $failureStage,
        Throwable $exception,
        ?array $gitDiagnostics,
    ): void {
        if ($paths === null) {
            return;
        }

        $payload = [
            'task_id' => $task->id,
            'failure_stage' => $failureStage,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'workspace_path' => $paths->root,
            'git_diagnostics' => $gitDiagnostics,
        ];

        $this->writeJsonLog($paths, 'failure-context.json', $payload);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function captureGitOutput(string $workingDirectory, array $arguments): array
    {
        $process = new Process(
            command: array_merge([(string) config('worker.repositories.git_binary', 'git')], $arguments),
            cwd: $workingDirectory,
            timeout: (float) config('worker.process_timeout_seconds', 3600),
        );

        $process->run();

        return [
            'successful' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
        ];
    }

    private function writeJsonLog(WorkspacePaths $paths, string $filename, array $payload): void
    {
        if (! $this->filesystem->isDirectory($paths->logsPath)) {
            $this->filesystem->makeDirectory($paths->logsPath, 0755, true);
        }

        $this->filesystem->put(
            $paths->logsPath.DIRECTORY_SEPARATOR.$filename,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
