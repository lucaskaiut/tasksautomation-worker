<?php

namespace Tests\Unit\Notifications;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\TaskStatusNotification;
use App\Services\Notifications\TaskStatusNotificationFormatter;
use Tests\TestCase;

class TaskStatusNotificationFormatterTest extends TestCase
{
    public function test_format_success_message_with_friendly_visual_structure(): void
    {
        $message = app(TaskStatusNotificationFormatter::class)->format(new TaskStatusNotification(
            task: $this->makeTask(),
            result: 'success',
            reportedStatus: 'review',
            executionSummary: 'Task concluida com publicacao da branch.',
            branchName: 'feat/501',
            commitSha: 'abc123',
            pullRequestUrl: 'https://github.com/acme/project/pull/10',
            logsPath: 'storage/workspaces/501/logs',
            occurredAt: '2026-04-18T10:11:12Z',
        ));

        $this->assertStringContainsString('🚀 Task Finalizada', $message);
        $this->assertStringContainsString('✅ Resultado: SUCCESS', $message);
        $this->assertStringContainsString('📌 Status enviado: REVIEW', $message);
        $this->assertStringContainsString('🆔 Task: #501', $message);
        $this->assertStringContainsString('🌿 Branch: feat/501', $message);
    }

    public function test_format_failure_message_includes_failure_reason(): void
    {
        $message = app(TaskStatusNotificationFormatter::class)->format(new TaskStatusNotification(
            task: $this->makeTask(),
            result: 'failure',
            reportedStatus: 'failed',
            executionSummary: 'Task falhou durante a validacao automatica.',
            failureReason: 'php artisan test falhou',
            occurredAt: '2026-04-18T10:11:12Z',
        ));

        $this->assertStringContainsString('🚨 Task Falhou', $message);
        $this->assertStringContainsString('❌ Resultado: FAILURE', $message);
        $this->assertStringContainsString('📌 Status enviado: FAILED', $message);
        $this->assertStringContainsString('⚠️ Motivo: php artisan test falhou', $message);
    }

    private function makeTask(): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map([
            'id' => 501,
            'title' => 'Executar task',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'worker-1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'implementation_type' => 'feature',
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'medium',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'project' => [
                'id' => 1,
                'name' => 'TaskAutomation',
                'slug' => 'taskautomation-worker',
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
            ],
            'environment_profile' => [
                'id' => 1,
                'project_id' => 1,
                'name' => 'Complete',
                'slug' => 'complete',
                'is_default' => true,
            ],
        ]);
    }
}
