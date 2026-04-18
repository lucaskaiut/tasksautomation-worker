<?php

namespace Tests\Unit\Validation;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\WorkspacePaths;
use App\Services\Validation\AutomaticValidationService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AutomaticValidationServiceTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir().'/tasksautomation-worker-validation-'.uniqid('', true);
        File::ensureDirectoryExists($this->tempBasePath);
        config([
            'worker.validation.global_commands' => [],
            'worker.validation.commands_by_environment_profile_slug' => [],
            'worker.validation.stop_on_failure' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempBasePath)) {
            File::deleteDirectory($this->tempBasePath);
        }

        parent::tearDown();
    }

    public function test_validate_passes_when_all_commands_succeed(): void
    {
        config([
            'worker.validation.global_commands' => [
                'php -r "echo \'first\';"',
            ],
            'worker.validation.commands_by_environment_profile_slug' => [
                'web' => [
                    'php -r "echo \'second\';"',
                ],
            ],
        ]);

        $result = app(AutomaticValidationService::class)->validate($this->makeTask(), $this->makeWorkspacePaths('pass'));

        $this->assertTrue($result->passed);
        $this->assertCount(2, $result->commands);
        $this->assertSame('php -r "echo \'first\';"', $result->commands[0]->command);
        $this->assertSame('first', $result->commands[0]->output);
        $this->assertSame('second', $result->commands[1]->output);
    }

    public function test_validate_returns_failed_result_when_a_command_fails(): void
    {
        config([
            'worker.validation.global_commands' => [
                'php -r "fwrite(STDERR, \'broken\'); exit(9);"',
                'php -r "echo \'should not run\';"',
            ],
            'worker.validation.stop_on_failure' => true,
        ]);

        $result = app(AutomaticValidationService::class)->validate($this->makeTask(), $this->makeWorkspacePaths('fail'));

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->commands);
        $this->assertSame(9, $result->commands[0]->exitCode);
        $this->assertStringContainsString('broken', $result->firstFailureOutput ?? '');
    }

    public function test_validate_captures_output_and_writes_logs(): void
    {
        config([
            'worker.validation.global_commands' => [
                'php -r "echo \'hello\'; fwrite(STDERR, \' world\');"',
            ],
        ]);

        $paths = $this->makeWorkspacePaths('logs');
        $result = app(AutomaticValidationService::class)->validate($this->makeTask(), $paths);

        $this->assertTrue($result->passed);
        $this->assertStringContainsString('hello', $result->commands[0]->output);
        $this->assertStringContainsString('world', $result->commands[0]->output);
        $this->assertFileExists($paths->logsPath.'/validation-01.log');
        $this->assertFileExists($paths->logsPath.'/validation-summary.log');
        $this->assertStringContainsString('passed: true', file_get_contents($paths->logsPath.'/validation-summary.log'));
    }

    public function test_validate_uses_docker_compose_exec_when_profile_has_compose(): void
    {
        $dockerScript = $this->tempBasePath.'/fake-docker.sh';
        file_put_contents($dockerScript, "#!/usr/bin/env bash\nprintf 'docker:%s\n' \"\$*\"\n");
        chmod($dockerScript, 0755);

        config([
            'worker.docker.binary' => $dockerScript,
            'worker.validation.global_commands' => [
                'php artisan test',
            ],
        ]);

        $paths = $this->makeWorkspacePaths('docker-validation');
        file_put_contents($paths->dockerComposePath, "services:\n  app:\n    image: php:8.3-cli\n");

        $result = app(AutomaticValidationService::class)->validate($this->makeTask(withCompose: true), $paths);

        $this->assertTrue($result->passed);
        $this->assertStringContainsString('docker:compose -f', $result->commands[0]->output);
        $this->assertStringContainsString('exec -T app sh -lc php artisan test', $result->commands[0]->output);
    }

    public function test_validate_with_empty_command_list_returns_success(): void
    {
        $paths = $this->makeWorkspacePaths('empty');
        $result = app(AutomaticValidationService::class)->validate($this->makeTask(), $paths);

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->commands);
        $this->assertNull($result->firstFailureOutput);
        $this->assertStringContainsString('No validation commands configured.', file_get_contents($paths->logsPath.'/validation-summary.log'));
    }

    private function makeTask(bool $withCompose = false): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map([
            'id' => 21,
            'title' => 'Validar task',
            'status' => 'claimed',
            'project_id' => 1,
            'environment_profile_id' => 5,
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
            'environment_profile' => [
                'id' => 5,
                'project_id' => 1,
                'name' => 'Web',
                'slug' => 'web',
                'is_default' => true,
                'docker_compose_yml' => $withCompose ? "services:\n  app:\n    image: php:8.3-cli\n" : null,
            ],
        ]);
    }

    private function makeWorkspacePaths(string $name): WorkspacePaths
    {
        $root = $this->tempBasePath.'/workspace-'.$name;
        File::ensureDirectoryExists($root.'/repo');
        File::ensureDirectoryExists($root.'/context');
        File::ensureDirectoryExists($root.'/logs');

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
