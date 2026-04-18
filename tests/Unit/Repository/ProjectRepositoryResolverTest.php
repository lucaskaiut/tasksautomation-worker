<?php

namespace Tests\Unit\Repository;

use App\DTOs\Mapping\ApiTaskMapper;
use App\Services\Repository\Exceptions\ProjectRepositoryNotConfiguredException;
use App\Services\Repository\Exceptions\ProjectRepositoryPathNotFoundException;
use App\Services\Repository\ProjectRepositoryResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProjectRepositoryResolverTest extends TestCase
{
    private string $tempRepositoryBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRepositoryBase = sys_get_temp_dir().'/tasksautomation-worker-repos-'.uniqid('', true);
        File::ensureDirectoryExists($this->tempRepositoryBase);

        config([
            'worker.repositories.by_project_slug' => [],
            'worker.repositories.by_repository_url' => [],
            'worker.repositories.automatic_clone_base_path' => $this->tempRepositoryBase.'/cache',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRepositoryBase)) {
            File::deleteDirectory($this->tempRepositoryBase);
        }

        parent::tearDown();
    }

    public function test_resolves_local_repository_by_project_slug(): void
    {
        $repositoryPath = $this->tempRepositoryBase.'/billing-app';
        File::ensureDirectoryExists($repositoryPath);

        config([
            'worker.repositories.by_project_slug' => [
                'billing-app' => $repositoryPath,
            ],
        ]);

        $task = $this->makeTask([
            'slug' => 'billing-app',
            'repository_url' => 'https://github.com/acme/billing-app',
            'default_branch' => 'main',
        ]);

        $resolved = app(ProjectRepositoryResolver::class)->resolveForTask($task);

        $this->assertSame(ProjectRepositoryResolver::STRATEGY_LOCAL_EXISTING, $resolved->strategy);
        $this->assertSame(realpath($repositoryPath), $resolved->expectedBasePath);
        $this->assertSame('https://github.com/acme/billing-app', $resolved->repositoryUrl);
        $this->assertSame('main', $resolved->defaultBranch);
    }

    public function test_resolves_automatic_clone_strategy_when_local_mapping_is_missing(): void
    {
        $task = $this->makeTask([
            'slug' => 'infra-worker',
            'repository_url' => 'https://github.com/acme/infra-worker.git',
            'default_branch' => 'develop',
        ]);

        $resolved = app(ProjectRepositoryResolver::class)->resolveForTask($task);

        $this->assertSame(ProjectRepositoryResolver::STRATEGY_AUTOMATIC_CLONE, $resolved->strategy);
        $this->assertSame(
            $this->tempRepositoryBase.'/cache/infra-worker',
            $resolved->expectedBasePath
        );
        $this->assertSame('https://github.com/acme/infra-worker.git', $resolved->repositoryUrl);
        $this->assertSame('develop', $resolved->defaultBranch);
    }

    public function test_throws_clear_error_when_project_data_is_missing_for_resolution(): void
    {
        $task = ApiTaskMapper::map([
            'id' => 55,
            'title' => 'Resolve repo',
            'status' => 'claimed',
            'project_id' => 9,
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
        ]);

        $this->expectException(ProjectRepositoryNotConfiguredException::class);
        $this->expectExceptionMessage('does not include project data');

        app(ProjectRepositoryResolver::class)->resolveForTask($task);
    }

    public function test_throws_clear_error_when_local_mapping_points_to_missing_directory(): void
    {
        $missingPath = $this->tempRepositoryBase.'/does-not-exist';

        config([
            'worker.repositories.by_project_slug' => [
                'broken-project' => $missingPath,
            ],
        ]);

        $task = $this->makeTask([
            'slug' => 'broken-project',
            'repository_url' => 'https://github.com/acme/broken-project',
            'default_branch' => 'main',
        ]);

        $this->expectException(ProjectRepositoryPathNotFoundException::class);
        $this->expectExceptionMessage($missingPath);

        app(ProjectRepositoryResolver::class)->resolveForTask($task);
    }

    public function test_throws_clear_error_when_repository_url_or_branch_are_missing_for_automatic_clone(): void
    {
        $task = $this->makeTask([
            'slug' => 'missing-data',
            'repository_url' => '',
            'default_branch' => '',
        ]);

        $this->expectException(ProjectRepositoryNotConfiguredException::class);
        $this->expectExceptionMessage('project.repository_url and project.default_branch');

        app(ProjectRepositoryResolver::class)->resolveForTask($task);
    }

    private function makeTask(array $projectOverrides): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map([
            'id' => 55,
            'title' => 'Resolve repo',
            'status' => 'claimed',
            'project_id' => 9,
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
            'project' => array_merge([
                'id' => 9,
                'name' => 'Project',
                'slug' => 'project',
                'description' => null,
                'repository_url' => 'https://github.com/acme/project',
                'default_branch' => 'main',
                'global_rules' => null,
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ], $projectOverrides),
        ]);
    }
}
