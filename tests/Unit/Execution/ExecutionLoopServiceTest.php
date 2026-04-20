<?php

namespace Tests\Unit\Execution;

use App\DTOs\ExecutionResult;
use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\ValidationCommandResult;
use App\DTOs\ValidationResult;
use App\DTOs\WorkspacePaths;
use App\Services\Execution\CursorExecutorService;
use App\Services\Execution\ExecutionLoopService;
use App\Services\Prompt\PromptBuilderService;
use App\Services\Validation\AutomaticValidationService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class ExecutionLoopServiceTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir().'/tasksautomation-worker-loop-'.uniqid('', true);
        File::ensureDirectoryExists($this->tempBasePath);
        config(['worker.max_attempts_per_execution' => 3]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempBasePath)) {
            File::deleteDirectory($this->tempBasePath);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_run_succeeds_on_first_attempt(): void
    {
        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths('first-success');

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildInitialPrompt')->once()->with($task)->andReturn("# Prompt inicial\n");
        $this->app->instance(PromptBuilderService::class, $promptBuilder);

        $workspaceService = app(WorkspaceService::class);
        $this->app->instance(WorkspaceService::class, $workspaceService);

        $cursorExecutor = Mockery::mock(CursorExecutorService::class);
        $cursorExecutor->shouldReceive('execute')->once()->with($paths, Mockery::type(\Closure::class))->andReturn(new ExecutionResult(
            succeeded: true,
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationSeconds: 0.5,
        ));
        $this->app->instance(CursorExecutorService::class, $cursorExecutor);

        $validator = Mockery::mock(AutomaticValidationService::class);
        $validator->shouldReceive('validate')->once()->with($task, $paths)->andReturn(new ValidationResult(
            passed: true,
            commands: [
                new ValidationCommandResult('php artisan test', true, 'PASS', 0),
            ],
            firstFailureOutput: null,
        ));
        $this->app->instance(AutomaticValidationService::class, $validator);

        $result = app(ExecutionLoopService::class)->run($task, $paths);

        $this->assertTrue($result->succeeded);
        $this->assertSame(1, $result->attemptsUsed);
        $this->assertCount(1, $result->iterations);
        $this->assertFileExists($paths->contextPath.'/prompt-attempt-01.md');
        $this->assertFileExists($paths->contextPath.'/iteration-01.json');
    }

    public function test_run_succeeds_after_incremental_correction(): void
    {
        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths('eventual-success');

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildInitialPrompt')->once()->andReturn("# Prompt inicial\n");
        $promptBuilder->shouldReceive('buildIterationPrompt')->once()->with(Mockery::on(function ($context): bool {
            return $context->attempt === 2
                && str_contains((string) $context->lastTechnicalError, 'Assertion failed')
                && $context->humanReviewFeedback === 'Ajustar sem quebrar o fluxo atual.';
        }))->andReturn("# Prompt incremental\n");
        $this->app->instance(PromptBuilderService::class, $promptBuilder);

        $cursorExecutor = Mockery::mock(CursorExecutorService::class);
        $cursorExecutor->shouldReceive('execute')->twice()->with($paths, Mockery::type(\Closure::class))->andReturn(
            new ExecutionResult(true, 0, 'attempt 1', '', 0.4),
            new ExecutionResult(true, 0, 'attempt 2', '', 0.3),
        );
        $this->app->instance(CursorExecutorService::class, $cursorExecutor);

        $validator = Mockery::mock(AutomaticValidationService::class);
        $validator->shouldReceive('validate')->twice()->andReturn(
            new ValidationResult(
                passed: false,
                commands: [new ValidationCommandResult('php artisan test', false, 'Assertion failed', 1)],
                firstFailureOutput: 'Assertion failed',
            ),
            new ValidationResult(
                passed: true,
                commands: [new ValidationCommandResult('php artisan test', true, 'PASS', 0)],
                firstFailureOutput: null,
            ),
        );
        $this->app->instance(AutomaticValidationService::class, $validator);

        $result = app(ExecutionLoopService::class)->run($task, $paths, 'Ajustar sem quebrar o fluxo atual.');

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $result->attemptsUsed);
        $this->assertCount(2, $result->iterations);
        $this->assertSame("# Prompt incremental\n", file_get_contents($paths->contextPath.'/prompt-attempt-02.md'));
    }

    public function test_run_fails_after_reaching_max_attempts(): void
    {
        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths('max-failure');

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildInitialPrompt')->once()->andReturn("# Prompt inicial\n");
        $promptBuilder->shouldReceive('buildIterationPrompt')->twice()->andReturn("# Prompt incremental 2\n", "# Prompt incremental 3\n");
        $this->app->instance(PromptBuilderService::class, $promptBuilder);

        $cursorExecutor = Mockery::mock(CursorExecutorService::class);
        $cursorExecutor->shouldReceive('execute')->times(3)->with($paths, Mockery::type(\Closure::class))->andReturn(
            new ExecutionResult(true, 0, 'a1', '', 0.2),
            new ExecutionResult(true, 0, 'a2', '', 0.2),
            new ExecutionResult(true, 0, 'a3', '', 0.2),
        );
        $this->app->instance(CursorExecutorService::class, $cursorExecutor);

        $validator = Mockery::mock(AutomaticValidationService::class);
        $validator->shouldReceive('validate')->times(3)->andReturn(
            new ValidationResult(false, [new ValidationCommandResult('t', false, 'err1', 1)], 'err1'),
            new ValidationResult(false, [new ValidationCommandResult('t', false, 'err2', 1)], 'err2'),
            new ValidationResult(false, [new ValidationCommandResult('t', false, 'err3', 1)], 'err3'),
        );
        $this->app->instance(AutomaticValidationService::class, $validator);

        $result = app(ExecutionLoopService::class)->run($task, $paths);

        $this->assertFalse($result->succeeded);
        $this->assertSame(3, $result->attemptsUsed);
        $this->assertSame('err3', $result->finalTechnicalError);
        $this->assertCount(3, $result->iterations);
    }

    public function test_run_generates_incremental_prompt_with_previous_execution_error(): void
    {
        config(['worker.max_attempts_per_execution' => 2]);

        $task = $this->makeTask();
        $paths = $this->makeWorkspacePaths('execution-error');

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildInitialPrompt')->once()->andReturn("# Prompt inicial\n");
        $promptBuilder->shouldReceive('buildIterationPrompt')->once()->with(Mockery::on(function ($context): bool {
            return str_contains((string) $context->lastTechnicalError, 'exit code 2')
                && str_contains((string) $context->lastTechnicalError, 'fatal stderr');
        }))->andReturn("# Prompt incremental apos erro\n");
        $this->app->instance(PromptBuilderService::class, $promptBuilder);

        $cursorExecutor = Mockery::mock(CursorExecutorService::class);
        $cursorExecutor->shouldReceive('execute')->twice()->with($paths, Mockery::type(\Closure::class))->andReturn(
            new ExecutionResult(false, 2, 'fatal stdout', 'fatal stderr', 0.2),
            new ExecutionResult(true, 0, 'ok', '', 0.1),
        );
        $this->app->instance(CursorExecutorService::class, $cursorExecutor);

        $validator = Mockery::mock(AutomaticValidationService::class);
        $validator->shouldReceive('validate')->once()->andReturn(
            new ValidationResult(true, [new ValidationCommandResult('t', true, 'ok', 0)], null)
        );
        $this->app->instance(AutomaticValidationService::class, $validator);

        $result = app(ExecutionLoopService::class)->run($task, $paths);

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $result->attemptsUsed);
    }

    public function test_run_uses_review_feedback_from_task_when_reopened_with_needs_adjustment(): void
    {
        $task = ApiTaskMapper::map([
            'id' => 32,
            'title' => 'Executar loop com review',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'worker-1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 5,
            'review_status' => 'needs_adjustment',
            'revision_count' => 1,
            'priority' => 'medium',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'latest_review' => [
                'review_feedback' => 'Corrigir apenas a validacao e preservar o fluxo atual.',
            ],
        ]);
        $paths = $this->makeWorkspacePaths('review-feedback');

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildInitialPrompt')->once()->andReturn("# Prompt inicial\n");
        $promptBuilder->shouldReceive('buildIterationPrompt')->once()->with(Mockery::on(function ($context): bool {
            return $context->humanReviewFeedback === 'Corrigir apenas a validacao e preservar o fluxo atual.';
        }))->andReturn("# Prompt incremental com review\n");
        $this->app->instance(PromptBuilderService::class, $promptBuilder);

        $cursorExecutor = Mockery::mock(CursorExecutorService::class);
        $cursorExecutor->shouldReceive('execute')->twice()->with($paths, Mockery::type(\Closure::class))->andReturn(
            new ExecutionResult(true, 0, 'attempt 1', '', 0.2),
            new ExecutionResult(true, 0, 'attempt 2', '', 0.2),
        );
        $this->app->instance(CursorExecutorService::class, $cursorExecutor);

        $validator = Mockery::mock(AutomaticValidationService::class);
        $validator->shouldReceive('validate')->twice()->andReturn(
            new ValidationResult(false, [new ValidationCommandResult('t', false, 'err', 1)], 'err'),
            new ValidationResult(true, [new ValidationCommandResult('t', true, 'ok', 0)], null),
        );
        $this->app->instance(AutomaticValidationService::class, $validator);

        $result = app(ExecutionLoopService::class)->run($task, $paths);

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $result->attemptsUsed);
    }

    public function test_run_skips_task_waiting_review(): void
    {
        $task = ApiTaskMapper::map([
            'id' => 33,
            'title' => 'Nao reexecutar',
            'status' => 'review',
            'review_status' => '',
        ]);
        $paths = $this->makeWorkspacePaths('skip-review');

        $result = app(ExecutionLoopService::class)->run($task, $paths);

        $this->assertFalse($result->succeeded);
        $this->assertSame(0, $result->attemptsUsed);
        $this->assertSame([], $result->iterations);
        $this->assertStringContainsString('awaiting human review', (string) $result->finalTechnicalError);
    }

    public function test_run_skips_automatic_validation_for_analysis_stage(): void
    {
        $task = $this->makeTask([
            'current_stage' => 'analysis',
        ]);
        $paths = $this->makeWorkspacePaths('analysis-stage');

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildInitialPrompt')->once()->andReturn("# Prompt de analise\n");
        $this->app->instance(PromptBuilderService::class, $promptBuilder);

        $cursorExecutor = Mockery::mock(CursorExecutorService::class);
        $cursorExecutor->shouldReceive('execute')->once()->with($paths, Mockery::type(\Closure::class))->andReturn(
            new ExecutionResult(true, 0, '{"next_stage":"implementation:backend"}', '', 0.2),
        );
        $this->app->instance(CursorExecutorService::class, $cursorExecutor);

        $validator = Mockery::mock(AutomaticValidationService::class);
        $validator->shouldNotReceive('validate');
        $this->app->instance(AutomaticValidationService::class, $validator);

        $result = app(ExecutionLoopService::class)->run($task, $paths);

        $this->assertTrue($result->succeeded);
        $this->assertSame(1, $result->attemptsUsed);
        $this->assertTrue($result->iterations[0]->validationResult->passed);
        $this->assertSame([], $result->iterations[0]->validationResult->commands);
    }

    private function makeTask(array $overrides = []): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map(array_replace_recursive([
            'id' => 31,
            'title' => 'Executar loop',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'worker-1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 5,
            'current_stage' => 'implementation:backend',
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'medium',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ], $overrides));
    }

    private function makeWorkspacePaths(string $name): WorkspacePaths
    {
        $root = $this->tempBasePath.'/workspace-'.$name;
        File::ensureDirectoryExists($root.'/repo');
        File::ensureDirectoryExists($root.'/context');
        File::ensureDirectoryExists($root.'/logs');
        file_put_contents($root.'/prompt.md', "# Prompt\n");

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
}
