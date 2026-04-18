<?php

namespace App\Services\Execution;

use App\DTOs\ExecutionLoopIteration;
use App\DTOs\ExecutionLoopResult;
use App\DTOs\ExecutionResult;
use App\DTOs\IterationContext;
use App\DTOs\TaskData;
use App\DTOs\ValidationResult;
use App\DTOs\WorkspacePaths;
use App\Services\Prompt\PromptBuilderService;
use App\Services\Service;
use App\Services\Validation\AutomaticValidationService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Filesystem\Filesystem;

class ExecutionLoopService extends Service
{
    public function __construct(
        private readonly PromptBuilderService $promptBuilder,
        private readonly CursorExecutorService $cursorExecutor,
        private readonly ExecutionHeartbeatService $heartbeatService,
        private readonly TaskReviewFlowService $taskReviewFlowService,
        private readonly AutomaticValidationService $validationService,
        private readonly WorkspaceService $workspaceService,
        private readonly Filesystem $filesystem,
    ) {}

    public function run(TaskData $task, WorkspacePaths $paths, ?string $humanReviewFeedback = null): ExecutionLoopResult
    {
        $reviewDecision = $this->taskReviewFlowService->decide($task);

        if (! $reviewDecision->shouldExecute) {
            return new ExecutionLoopResult(
                succeeded: false,
                attemptsUsed: 0,
                iterations: [],
                finalTechnicalError: $reviewDecision->skipReason,
            );
        }

        $humanReviewFeedback ??= $reviewDecision->humanFeedback;

        $maxAttempts = max(1, (int) config('worker.max_attempts_per_execution', 1));
        $iterations = [];
        $lastTechnicalError = null;
        $this->heartbeatService->reset($task->id);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->heartbeatService->beatIfDue($task->id);

            $prompt = $attempt === 1
                ? $this->promptBuilder->buildInitialPrompt($task)
                : $this->promptBuilder->buildIterationPrompt(new IterationContext(
                    taskId: $task->id,
                    attempt: $attempt,
                    maxAttempts: $maxAttempts,
                    task: $task,
                    lastTechnicalError: $lastTechnicalError,
                    humanReviewFeedback: $humanReviewFeedback,
                ));

            $this->workspaceService->writePrompt($paths, $prompt);
            $promptPath = $this->storePromptSnapshot($paths, $attempt);

            $executionResult = $this->cursorExecutor->execute(
                $paths,
                fn () => $this->heartbeatService->beatIfDue($task->id),
            );
            $validationResult = $executionResult->succeeded
                ? $this->validationService->validate($task, $paths)
                : new ValidationResult(
                    passed: false,
                    commands: [],
                    firstFailureOutput: $this->buildExecutionFailureOutput($executionResult),
                );

            $this->heartbeatService->beatIfDue($task->id);

            $contextPath = $this->storeIterationContext(
                paths: $paths,
                task: $task,
                attempt: $attempt,
                maxAttempts: $maxAttempts,
                executionResult: $executionResult,
                validationResult: $validationResult,
                humanReviewFeedback: $humanReviewFeedback,
                lastTechnicalError: $lastTechnicalError,
            );

            $iterations[] = new ExecutionLoopIteration(
                attempt: $attempt,
                executionResult: $executionResult,
                validationResult: $validationResult,
                promptPath: $promptPath,
                contextPath: $contextPath,
            );

            if ($executionResult->succeeded && $validationResult->passed) {
                return new ExecutionLoopResult(
                    succeeded: true,
                    attemptsUsed: $attempt,
                    iterations: $iterations,
                    finalTechnicalError: null,
                );
            }

            $lastTechnicalError = $validationResult->firstFailureOutput
                ?? $this->buildExecutionFailureOutput($executionResult);
        }

        return new ExecutionLoopResult(
            succeeded: false,
            attemptsUsed: count($iterations),
            iterations: $iterations,
            finalTechnicalError: $lastTechnicalError,
        );
    }

    private function storePromptSnapshot(WorkspacePaths $paths, int $attempt): string
    {
        $target = $paths->contextPath.DIRECTORY_SEPARATOR.sprintf('prompt-attempt-%02d.md', $attempt);

        $this->filesystem->copy($paths->promptMdPath, $target);

        return $target;
    }

    private function storeIterationContext(
        WorkspacePaths $paths,
        TaskData $task,
        int $attempt,
        int $maxAttempts,
        ExecutionResult $executionResult,
        ValidationResult $validationResult,
        ?string $humanReviewFeedback,
        ?string $lastTechnicalError,
    ): string {
        $target = $paths->contextPath.DIRECTORY_SEPARATOR.sprintf('iteration-%02d.json', $attempt);

        $payload = [
            'task_id' => $task->id,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'human_review_feedback' => $humanReviewFeedback,
            'last_technical_error' => $lastTechnicalError,
            'execution' => [
                'succeeded' => $executionResult->succeeded,
                'exit_code' => $executionResult->exitCode,
                'stdout' => $executionResult->stdout,
                'stderr' => $executionResult->stderr,
                'duration_seconds' => $executionResult->durationSeconds,
            ],
            'validation' => [
                'passed' => $validationResult->passed,
                'first_failure_output' => $validationResult->firstFailureOutput,
                'commands' => array_map(static fn ($command): array => [
                    'command' => $command->command,
                    'passed' => $command->passed,
                    'output' => $command->output,
                    'exit_code' => $command->exitCode,
                ], $validationResult->commands),
            ],
        ];

        $this->filesystem->put(
            $target,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $target;
    }

    private function buildExecutionFailureOutput(ExecutionResult $executionResult): string
    {
        return trim(implode("\n", array_filter([
            'Codex execution failed with exit code '.$executionResult->exitCode.'.',
            trim($executionResult->stdout),
            trim($executionResult->stderr),
        ])));
    }
}
