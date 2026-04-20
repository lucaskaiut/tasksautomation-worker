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
use Illuminate\Support\Arr;
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

    public function claimTask(): ?TaskData
    {
        return $this->taskApiClient->claimTask();
    }

    public function runCycle(?callable $onProgress = null): WorkerCycleResult
    {
        $task = $this->claimTask();

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
            $stagePayload = $this->buildStagePayload($task, $loopResult);

            if ($task->isAnalysisStage()) {
                $this->taskResultReporterService->reportTechnicalSuccess(
                    task: $task,
                    executionSummary: $this->buildSuccessSummary($task, $loopResult, null, $stagePayload),
                    logsPath: $paths->logsPath,
                    metadata: $this->buildMetadata($task, $repositorySync, $loopResult, null, null, $paths->root),
                    extraPayload: $stagePayload,
                );

                return;
            }

            $prePublicationDiagnostics = $this->captureGitDiagnostics($paths, 'pre-publication');
            $publicationResult = $this->gitPublicationService->publish($task, $paths);

            $this->taskResultReporterService->reportTechnicalSuccess(
                task: $task,
                executionSummary: $this->buildSuccessSummary($task, $loopResult, $publicationResult, $stagePayload),
                branchName: $publicationResult->branchName,
                commitSha: $publicationResult->commitSha,
                logsPath: $paths->logsPath,
                metadata: $this->buildMetadata($task, $repositorySync, $loopResult, $publicationResult, $prePublicationDiagnostics, $paths->root),
                extraPayload: $stagePayload,
            );

            return;
        }

        $gitDiagnostics = $this->captureGitDiagnostics($paths, 'loop-failure');
        $stagePayload = $this->buildStagePayload($task, $loopResult);
        $this->taskResultReporterService->reportFailure(
            task: $task,
            executionSummary: $this->buildFailureSummary($task, $loopResult),
            failureReason: $loopResult->finalTechnicalError ?? 'Execution loop exhausted attempts.',
            metadata: $this->buildMetadata($task, $repositorySync, $loopResult, null, $gitDiagnostics, $paths->root),
            logsPath: $paths->logsPath,
            extraPayload: $stagePayload,
        );
    }

    private function buildSuccessSummary(
        TaskData $task,
        ExecutionLoopResult $loopResult,
        ?PublicationResult $publicationResult,
        array $stagePayload,
    ): string
    {
        if ($task->isAnalysisStage()) {
            $nextStage = Arr::get($stagePayload, 'analysis.next_stage');

            return sprintf(
                'Task "%s" foi analisada com sucesso apos %d tentativa(s)%s.',
                $task->title,
                $loopResult->attemptsUsed,
                is_string($nextStage) && $nextStage !== '' ? ' e sugeriu o proximo estagio '.$nextStage : '',
            );
        }

        if ($publicationResult === null) {
            return sprintf(
                'Task "%s" completed successfully after %d attempt(s).',
                $task->title,
                $loopResult->attemptsUsed,
            );
        }

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
        TaskData $task,
        RepositorySyncResult $repositorySync,
        ExecutionLoopResult $loopResult,
        ?PublicationResult $publicationResult = null,
        ?array $gitDiagnostics = null,
        ?string $workspacePath = null,
    ): array
    {
        $metadata = [
            'current_stage' => $task->currentStage,
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

    private function buildStagePayload(TaskData $task, ExecutionLoopResult $loopResult): array
    {
        $lastIteration = $loopResult->iterations === [] ? null : $loopResult->iterations[array_key_last($loopResult->iterations)];
        $executionResult = $lastIteration?->executionResult;
        $rawOutput = $this->combineExecutionOutput(
            $executionResult?->stdout ?? '',
            $executionResult?->stderr ?? '',
        );
        $structuredOutput = $this->extractStructuredOutput($rawOutput);
        $summary = $this->extractStageSummary($structuredOutput, $rawOutput, $task->title);

        $payload = [
            'current_stage' => $task->currentStage,
            'stage_execution' => array_filter([
                'reference' => $lastIteration !== null ? sprintf('task-%d-attempt-%02d', $task->id, $lastIteration->attempt) : sprintf('task-%d', $task->id),
                'stage' => $task->currentStage,
                'status' => $loopResult->succeeded ? 'completed' : 'failed',
                'agent' => 'codex',
                'summary' => $summary,
                'output' => $structuredOutput,
                'raw_output' => $rawOutput !== '' ? $rawOutput : null,
                'exit_code' => $executionResult?->exitCode,
                'context' => [
                    'attempts_used' => $loopResult->attemptsUsed,
                ],
            ], static fn (mixed $value): bool => $value !== null),
        ];

        if (! $task->isAnalysisStage() || ! is_array($structuredOutput)) {
            return $payload;
        }

        $analysis = array_filter([
            'domain' => $this->normalizeAnalysisDomain(Arr::get($structuredOutput, 'domain')),
            'confidence' => $this->normalizeConfidence(Arr::get($structuredOutput, 'confidence')),
            'next_stage' => $this->normalizeStage(Arr::get($structuredOutput, 'next_stage')),
            'summary' => Arr::get($structuredOutput, 'summary'),
            'evidence' => Arr::get($structuredOutput, 'evidence'),
            'risks' => Arr::get($structuredOutput, 'risks'),
            'artifacts' => Arr::get($structuredOutput, 'artifacts'),
            'notes' => Arr::get($structuredOutput, 'notes'),
        ], static fn (mixed $value): bool => $value !== null);

        if ($analysis !== []) {
            $payload['analysis'] = $analysis;
        }

        if (isset($analysis['next_stage']) && is_string($analysis['next_stage'])) {
            $payload['handoff'] = array_filter([
                'from_stage' => $task->currentStage,
                'to_stage' => $analysis['next_stage'],
                'reason' => $analysis['summary'] ?? null,
                'confidence' => $analysis['confidence'] ?? null,
                'summary' => $analysis['summary'] ?? null,
                'payload' => [
                    'analysis' => $analysis,
                ],
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $payload;
    }

    private function combineExecutionOutput(string $stdout, string $stderr): string
    {
        return trim(implode("\n", array_filter([
            trim($stdout),
            trim($stderr),
        ])));
    }

    private function extractStructuredOutput(string $rawOutput): ?array
    {
        if ($rawOutput === '') {
            return null;
        }

        $candidates = [$rawOutput];

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $rawOutput, $matches) === 1) {
            $candidates[] = trim($matches[1]);
        }

        $firstBrace = strpos($rawOutput, '{');
        $lastBrace = strrpos($rawOutput, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidates[] = substr($rawOutput, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        foreach ($candidates as $candidate) {
            try {
                $decoded = json_decode(trim($candidate), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractStageSummary(?array $structuredOutput, string $rawOutput, string $fallbackTitle): string
    {
        $summary = Arr::get($structuredOutput, 'summary');

        if (is_string($summary) && trim($summary) !== '') {
            return trim($summary);
        }

        if ($rawOutput !== '') {
            return mb_substr(preg_replace('/\s+/u', ' ', $rawOutput) ?? $rawOutput, 0, 500);
        }

        return 'Stage execution finished for task "'.$fallbackTitle.'".';
    }

    private function normalizeStage(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \App\Support\Enums\TaskStage::tryFrom(trim($value))?->value;
    }

    private function normalizeAnalysisDomain(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \App\Support\Enums\TaskAnalysisDomain::tryFrom(trim($value))?->value;
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $confidence = (float) $value;

        if ($confidence < 0 || $confidence > 1) {
            return null;
        }

        return $confidence;
    }
}
