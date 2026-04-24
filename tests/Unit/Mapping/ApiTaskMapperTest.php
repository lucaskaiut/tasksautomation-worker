<?php

namespace Tests\Unit\Mapping;

use App\DTOs\Mapping\ApiTaskMapper;
use App\Services\Api\Exceptions\TaskApiUnexpectedResponseException;
use PHPUnit\Framework\TestCase;

class ApiTaskMapperTest extends TestCase
{
    public function test_maps_full_claim_payload_from_api(): void
    {
        $payload = [
            'id' => 1,
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'worker-1',
            'claimed_at' => '2026-04-17T14:59:06.000000Z',
            'started_at' => null,
            'finished_at' => null,
            'last_heartbeat_at' => null,
            'attempts' => 1,
            'max_attempts' => 3,
            'locked_until' => '2026-04-17T15:09:06.000000Z',
            'failure_reason' => null,
            'execution_summary' => null,
            'run_after' => null,
            'title' => 'Instalar a aplicação',
            'description' => 'Descrição longa',
            'deliverables' => 'Item A',
            'constraints' => null,
            'implementation_type' => 'feature',
            'status' => 'claimed',
            'review_status' => '',
            'revision_count' => 0,
            'last_reviewed_at' => null,
            'last_reviewed_by' => null,
            'last_reviewer' => null,
            'priority' => 'low',
            'project' => [
                'id' => 1,
                'name' => 'Infra',
                'slug' => 'infra',
                'description' => 'docs',
                'repository_url' => 'https://github.com/lucaskaiut/infra',
                'default_branch' => 'main',
                'global_rules' => [
                    'ci_provider' => 'github_actions',
                    'labels' => ['backend', 'urgent'],
                ],
                'is_active' => true,
                'created_at' => '2026-04-16T17:44:50.000000Z',
                'updated_at' => '2026-04-16T17:44:50.000000Z',
            ],
            'environment_profile' => [
                'id' => 1,
                'project_id' => 1,
                'name' => 'light',
                'slug' => 'light',
                'is_default' => false,
                'docker_compose_yml' => null,
            ],
            'created_at' => '2026-04-16T17:54:26.000000Z',
            'updated_at' => '2026-04-17T14:59:06.000000Z',
        ];

        $task = ApiTaskMapper::map($payload);

        $this->assertSame(1, $task->id);
        $this->assertSame('claimed', $task->status);
        $this->assertSame('feature', $task->implementationType);
        $this->assertSame('worker-1', $task->claimedByWorker);
        $this->assertNull($task->startedAt);
        $this->assertNotNull($task->project);
        $this->assertSame('https://github.com/lucaskaiut/infra', $task->project->repositoryUrl);
        $this->assertSame('github_actions', $task->project->globalRules['ci_provider']);
        $this->assertNotNull($task->environmentProfile);
        $this->assertSame('light', $task->environmentProfile->slug);
        $this->assertSame($payload, $task->sourcePayload);
    }

    public function test_missing_id_throws(): void
    {
        $this->expectException(TaskApiUnexpectedResponseException::class);

        ApiTaskMapper::map(['title' => 'x']);
    }

    public function test_maps_last_reviewer_user_summary_to_name_string(): void
    {
        $payload = [
            'id' => 1,
            'project_id' => 1,
            'environment_profile_id' => 0,
            'created_by' => 1,
            'claimed_by_worker' => '',
            'claimed_at' => '',
            'attempts' => 0,
            'max_attempts' => 3,
            'title' => 'T',
            'status' => 'done',
            'review_status' => 'approved',
            'revision_count' => 0,
            'priority' => 'low',
            'last_reviewer' => [
                'id' => 42,
                'name' => 'Pat Reviewer',
            ],
            'created_at' => '2026-04-21T00:00:00.000000Z',
            'updated_at' => '2026-04-21T00:00:00.000000Z',
        ];

        $task = ApiTaskMapper::map($payload);

        $this->assertSame('Pat Reviewer', $task->lastReviewer);
    }
}
