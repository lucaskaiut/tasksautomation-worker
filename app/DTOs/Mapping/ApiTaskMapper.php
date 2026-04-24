<?php

namespace App\DTOs\Mapping;

use App\DTOs\TaskData;
use App\Services\Api\Exceptions\TaskApiUnexpectedResponseException;

final class ApiTaskMapper
{
    public static function map(array $data): TaskData
    {
        if (! isset($data['id'])) {
            throw new TaskApiUnexpectedResponseException('Task payload is missing id.');
        }

        $project = null;
        if (isset($data['project']) && is_array($data['project'])) {
            $project = ApiProjectMapper::map($data['project']);
        }

        $environmentProfile = null;
        if (isset($data['environment_profile']) && is_array($data['environment_profile'])) {
            $environmentProfile = ApiEnvironmentProfileMapper::map($data['environment_profile']);
        }

        return new TaskData(
            id: (int) $data['id'],
            projectId: (int) ($data['project_id'] ?? 0),
            environmentProfileId: (int) ($data['environment_profile_id'] ?? 0),
            createdBy: (int) ($data['created_by'] ?? 0),
            claimedByWorker: (string) ($data['claimed_by_worker'] ?? ''),
            claimedAt: (string) ($data['claimed_at'] ?? ''),
            startedAt: ApiValue::optionalString($data, 'started_at'),
            finishedAt: ApiValue::optionalString($data, 'finished_at'),
            lastHeartbeatAt: ApiValue::optionalString($data, 'last_heartbeat_at'),
            attempts: (int) ($data['attempts'] ?? 0),
            maxAttempts: (int) ($data['max_attempts'] ?? 0),
            lockedUntil: ApiValue::optionalString($data, 'locked_until'),
            failureReason: ApiValue::optionalString($data, 'failure_reason'),
            executionSummary: ApiValue::optionalString($data, 'execution_summary'),
            runAfter: ApiValue::optionalString($data, 'run_after'),
            title: (string) ($data['title'] ?? ''),
            description: ApiValue::optionalString($data, 'description'),
            deliverables: ApiValue::optionalString($data, 'deliverables'),
            constraints: ApiValue::optionalString($data, 'constraints'),
            implementationType: ApiValue::optionalString($data, 'implementation_type'),
            currentStage: (string) ($data['current_stage'] ?? 'implementation:backend'),
            analysis: isset($data['analysis']) && is_array($data['analysis']) ? $data['analysis'] : null,
            stageExecution: isset($data['stage_execution']) && is_array($data['stage_execution']) ? $data['stage_execution'] : null,
            handoff: isset($data['handoff']) && is_array($data['handoff']) ? $data['handoff'] : null,
            status: (string) ($data['status'] ?? ''),
            reviewStatus: (string) ($data['review_status'] ?? ''),
            revisionCount: (int) ($data['revision_count'] ?? 0),
            lastReviewedAt: ApiValue::optionalString($data, 'last_reviewed_at'),
            lastReviewedBy: ApiValue::optionalInt($data, 'last_reviewed_by'),
            lastReviewer: ApiValue::optionalStringOrUserSummary($data, 'last_reviewer'),
            priority: (string) ($data['priority'] ?? ''),
            project: $project,
            environmentProfile: $environmentProfile,
            createdAt: (string) ($data['created_at'] ?? ''),
            updatedAt: (string) ($data['updated_at'] ?? ''),
            sourcePayload: $data,
        );
    }
}
