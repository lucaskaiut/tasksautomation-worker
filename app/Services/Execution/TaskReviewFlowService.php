<?php

namespace App\Services\Execution;

use App\DTOs\ReviewExecutionDecision;
use App\DTOs\TaskData;
use App\Services\Service;

class TaskReviewFlowService extends Service
{
    public function decide(TaskData $task): ReviewExecutionDecision
    {
        $reviewStatus = trim($task->reviewStatus);

        if ($task->status === 'review') {
            return new ReviewExecutionDecision(
                shouldExecute: false,
                humanFeedback: null,
                skipReason: 'Task is awaiting human review and must not be reexecuted.',
            );
        }

        if ($reviewStatus === 'approved') {
            return new ReviewExecutionDecision(
                shouldExecute: false,
                humanFeedback: null,
                skipReason: 'Task review is approved and must not be reexecuted.',
            );
        }

        if ($reviewStatus === 'needs_adjustment') {
            return new ReviewExecutionDecision(
                shouldExecute: true,
                humanFeedback: $this->extractHumanFeedback($task),
                skipReason: null,
            );
        }

        return new ReviewExecutionDecision(
            shouldExecute: true,
            humanFeedback: null,
            skipReason: null,
        );
    }

    public function extractHumanFeedback(TaskData $task): ?string
    {
        foreach ($this->feedbackCandidates() as $candidate) {
            $value = $this->findStringByKeyRecursive($task->sourcePayload, $candidate);

            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function feedbackCandidates(): array
    {
        return [
            'human_review_feedback',
            'review_feedback',
            'latest_review_feedback',
            'review_comment',
            'feedback',
            'comment',
        ];
    }

    private function findStringByKeyRecursive(array $payload, string $key): ?string
    {
        if (array_key_exists($key, $payload) && is_string($payload[$key])) {
            return $payload[$key];
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = $this->findStringByKeyRecursive($value, $key);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
