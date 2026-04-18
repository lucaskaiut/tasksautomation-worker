<?php

namespace Tests\Unit\Execution;

use App\DTOs\Mapping\ApiTaskMapper;
use App\Services\Execution\TaskReviewFlowService;
use Tests\TestCase;

class TaskReviewFlowServiceTest extends TestCase
{
    public function test_new_task_without_feedback_can_execute_normally(): void
    {
        $task = $this->makeTask([
            'status' => 'claimed',
            'review_status' => '',
        ]);

        $decision = app(TaskReviewFlowService::class)->decide($task);

        $this->assertTrue($decision->shouldExecute);
        $this->assertNull($decision->humanFeedback);
        $this->assertNull($decision->skipReason);
    }

    public function test_task_reopened_with_needs_adjustment_is_executable_and_extracts_feedback(): void
    {
        $task = $this->makeTask([
            'status' => 'claimed',
            'review_status' => 'needs_adjustment',
            'latest_review' => [
                'review_feedback' => 'Ajustar a validacao sem quebrar a implementacao atual.',
            ],
        ]);

        $decision = app(TaskReviewFlowService::class)->decide($task);

        $this->assertTrue($decision->shouldExecute);
        $this->assertSame('Ajustar a validacao sem quebrar a implementacao atual.', $decision->humanFeedback);
        $this->assertNull($decision->skipReason);
    }

    public function test_task_waiting_review_is_not_reexecuted(): void
    {
        $task = $this->makeTask([
            'status' => 'review',
            'review_status' => '',
        ]);

        $decision = app(TaskReviewFlowService::class)->decide($task);

        $this->assertFalse($decision->shouldExecute);
        $this->assertStringContainsString('awaiting human review', (string) $decision->skipReason);
    }

    public function test_approved_task_is_not_reexecuted(): void
    {
        $task = $this->makeTask([
            'status' => 'done',
            'review_status' => 'approved',
        ]);

        $decision = app(TaskReviewFlowService::class)->decide($task);

        $this->assertFalse($decision->shouldExecute);
        $this->assertStringContainsString('approved', (string) $decision->skipReason);
    }

    private function makeTask(array $overrides): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map(array_merge([
            'id' => 81,
            'title' => 'Review flow',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'worker-1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'medium',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ], $overrides));
    }
}
