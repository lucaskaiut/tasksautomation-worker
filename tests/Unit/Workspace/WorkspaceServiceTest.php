<?php

namespace Tests\Unit\Workspace;

use App\DTOs\Mapping\ApiTaskMapper;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkspaceServiceTest extends TestCase
{
    private string $tempWorkspacesBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempWorkspacesBase = sys_get_temp_dir().'/tasksautomation-worker-ws-'.uniqid('', true);
        config(['worker.workspaces_path' => $this->tempWorkspacesBase]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempWorkspacesBase)) {
            File::deleteDirectory($this->tempWorkspacesBase);
        }
        parent::tearDown();
    }

    public function test_prepare_creates_workspace_directories_and_files(): void
    {
        $task = ApiTaskMapper::map([
            'id' => 42,
            'title' => 'Teste workspace',
            'description' => 'Descricao do prompt',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'w1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'low',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'project' => [
                'id' => 1,
                'name' => 'Project',
                'slug' => 'project-slug',
                'description' => null,
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
                'global_rules' => null,
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ],
        ]);

        $raw = ['data' => $task->sourcePayload, 'message' => 'ok'];

        $paths = app(WorkspaceService::class)->prepare($task, $raw);

        $this->assertSame($this->tempWorkspacesBase.'/42', $paths->root);
        $this->assertDirectoryExists($paths->repoPath);
        $this->assertFalse(is_link($paths->repoPath));
        $this->assertDirectoryExists($paths->contextPath);
        $this->assertDirectoryExists($paths->logsPath);
        $this->assertSame($this->tempWorkspacesBase.'/42/docker-compose.yml', $paths->dockerComposePath);
        $this->assertFileExists($paths->rawTaskResponsePath);
        $this->assertFileExists($paths->taskJsonPath);
        $this->assertFileExists($paths->promptMdPath);

        $prompt = file_get_contents($paths->promptMdPath);
        $this->assertStringContainsString('# Prompt de Execucao', $prompt);
        $this->assertStringContainsString('Teste workspace', $prompt);
        $this->assertStringContainsString('Descricao do prompt', $prompt);
    }

    public function test_prepare_writes_docker_compose_file_when_environment_profile_provides_it(): void
    {
        $task = ApiTaskMapper::map([
            'id' => 43,
            'title' => 'Workspace com compose',
            'status' => 'claimed',
            'environment_profile' => [
                'id' => 7,
                'project_id' => 1,
                'name' => 'web',
                'slug' => 'web',
                'is_default' => true,
                'docker_compose_yml' => "services:\n  app:\n    image: php:8.3-cli\n",
            ],
        ]);

        $paths = app(WorkspaceService::class)->prepare($task, ['data' => []]);

        $this->assertFileExists($paths->dockerComposePath);
        $this->assertStringContainsString('services:', file_get_contents($paths->dockerComposePath));
    }

    public function test_write_prompt_overwrites_prompt_md_with_incremental_content(): void
    {
        $task = ApiTaskMapper::map([
            'id' => 999,
            'title' => 'Prompt customizado',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'w1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'low',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ]);

        $paths = app(WorkspaceService::class)->prepare($task, ['data' => []]);

        app(WorkspaceService::class)->writePrompt($paths, "# Prompt de Correcao Incremental\n\nnovo conteudo\n");

        $this->assertSame(
            "# Prompt de Correcao Incremental\n\nnovo conteudo\n",
            file_get_contents($paths->promptMdPath)
        );
    }

    public function test_json_files_match_payload_and_normalized_task(): void
    {
        $payload = [
            'id' => 7,
            'title' => 'T',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'w1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'low',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'project' => [
                'id' => 1,
                'name' => 'Project',
                'slug' => 'project-slug',
                'description' => null,
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
                'global_rules' => null,
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ],
        ];

        $task = ApiTaskMapper::map($payload);
        $raw = ['data' => $payload, 'message' => 'claim ok'];

        $paths = app(WorkspaceService::class)->prepare($task, $raw);

        $rawDecoded = json_decode(file_get_contents($paths->rawTaskResponsePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(7, $rawDecoded['data']['id']);
        $this->assertSame('claim ok', $rawDecoded['message']);

        $taskDecoded = json_decode(file_get_contents($paths->taskJsonPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(7, $taskDecoded['id']);
        $this->assertSame('claimed', $taskDecoded['status']);
        $this->assertArrayNotHasKey('source_payload', $taskDecoded);
    }

    public function test_cleanup_enabled_removes_workspace_directory(): void
    {
        config([
            'worker.cleanup_workspace' => true,
            'worker.cleanup_workspace_on_success' => true,
        ]);

        $task = ApiTaskMapper::map([
            'id' => 100,
            'title' => 'X',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'w1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'low',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'project' => [
                'id' => 1,
                'name' => 'Project',
                'slug' => 'project-slug',
                'description' => null,
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
                'global_rules' => null,
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ],
        ]);

        $paths = app(WorkspaceService::class)->prepare($task, ['data' => []]);

        $this->assertDirectoryExists($paths->root);

        app(WorkspaceService::class)->cleanup(100, true);

        $this->assertDirectoryDoesNotExist($paths->root);
    }

    public function test_cleanup_disabled_leaves_workspace_intact(): void
    {
        config(['worker.cleanup_workspace' => false]);

        $task = ApiTaskMapper::map([
            'id' => 200,
            'title' => 'Y',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'w1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'low',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'project' => [
                'id' => 1,
                'name' => 'Project',
                'slug' => 'project-slug',
                'description' => null,
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
                'global_rules' => null,
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ],
        ]);

        $paths = app(WorkspaceService::class)->prepare($task, ['data' => []]);

        app(WorkspaceService::class)->cleanup(200, true);

        $this->assertDirectoryExists($paths->root);
        $this->assertFileExists($paths->taskJsonPath);
    }

    public function test_cleanup_failure_default_preserves_workspace_directory(): void
    {
        config([
            'worker.cleanup_workspace' => true,
            'worker.cleanup_workspace_on_success' => true,
            'worker.cleanup_workspace_on_failure' => false,
        ]);

        $task = ApiTaskMapper::map([
            'id' => 201,
            'title' => 'Falha preservada',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'w1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'low',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ]);

        $paths = app(WorkspaceService::class)->prepare($task, ['data' => []]);

        app(WorkspaceService::class)->cleanup(201, false);

        $this->assertDirectoryExists($paths->root);
    }

    public function test_prepare_recreates_workspace_when_task_id_directory_already_exists(): void
    {
        $task = ApiTaskMapper::map([
            'id' => 300,
            'title' => 'Primeiro',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'w1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'low',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'project' => [
                'id' => 1,
                'name' => 'Project',
                'slug' => 'project-slug',
                'description' => null,
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
                'global_rules' => null,
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ],
        ]);

        app(WorkspaceService::class)->prepare($task, ['data' => ['title' => 'Primeiro']]);

        $task2 = ApiTaskMapper::map([
            'id' => 300,
            'title' => 'Segundo',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 1,
            'created_by' => 1,
            'claimed_by_worker' => 'w1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 3,
            'review_status' => '',
            'revision_count' => 0,
            'priority' => 'low',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'project' => [
                'id' => 1,
                'name' => 'Project',
                'slug' => 'project-slug',
                'description' => null,
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
                'global_rules' => null,
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ],
        ]);

        $paths = app(WorkspaceService::class)->prepare($task2, ['data' => ['title' => 'Segundo']]);

        $taskDecoded = json_decode(file_get_contents($paths->taskJsonPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Segundo', $taskDecoded['title']);
    }
}
