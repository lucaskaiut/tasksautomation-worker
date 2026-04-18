<?php

namespace Tests\Unit\Dto;

use App\DTOs\ExecutionResult;
use App\DTOs\IterationContext;
use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\ValidationCommandResult;
use App\DTOs\ValidationResult;
use PHPUnit\Framework\TestCase;

class ExecutionAndValidationDtoTest extends TestCase
{
    public function test_execution_result_holds_process_outcome(): void
    {
        $result = new ExecutionResult(
            succeeded: true,
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationSeconds: 1.5,
        );

        $this->assertTrue($result->succeeded);
        $this->assertSame(0, $result->exitCode);
        $this->assertSame(1.5, $result->durationSeconds);
    }

    public function test_validation_result_aggregates_command_results(): void
    {
        $cmd = new ValidationCommandResult(
            command: 'php artisan test',
            passed: true,
            output: 'PASS',
            exitCode: 0,
        );

        $aggregate = new ValidationResult(
            passed: true,
            commands: [$cmd],
            firstFailureOutput: null,
        );

        $this->assertTrue($aggregate->passed);
        $this->assertSame('php artisan test', $aggregate->commands[0]->command);
    }

    public function test_iteration_context_references_task_snapshot(): void
    {
        $task = ApiTaskMapper::map([
            'id' => 9,
            'title' => 'T',
            'status' => 'running',
        ]);

        $ctx = new IterationContext(
            taskId: 9,
            attempt: 2,
            maxAttempts: 5,
            task: $task,
            lastTechnicalError: 'exit 1',
            humanReviewFeedback: null,
        );

        $this->assertSame(9, $ctx->taskId);
        $this->assertSame(2, $ctx->attempt);
        $this->assertSame('exit 1', $ctx->lastTechnicalError);
        $this->assertSame('running', $ctx->task->status);
    }
}
