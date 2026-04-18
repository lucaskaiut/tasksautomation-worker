<?php

namespace Tests\Unit\Execution;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\WorkspacePaths;
use App\Services\Execution\DockerComposeEnvironmentService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DockerComposeEnvironmentServiceTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir().'/tasksautomation-worker-docker-'.uniqid('', true);
        File::ensureDirectoryExists($this->tempBasePath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempBasePath)) {
            File::deleteDirectory($this->tempBasePath);
        }

        parent::tearDown();
    }

    public function test_bootstrap_starts_docker_compose_when_profile_has_compose(): void
    {
        $binary = $this->makeFakeDockerBinary();
        config(['worker.docker.binary' => $binary]);

        $paths = $this->makeWorkspacePaths('bootstrap');
        file_put_contents($paths->dockerComposePath, "services:\n  app:\n    image: php:8.3-cli\n");

        $context = app(DockerComposeEnvironmentService::class)->bootstrap($this->makeTask(), $paths);

        $this->assertTrue($context->enabled);
        $this->assertSame('app', $context->execService);
        $this->assertStringContainsString('compose|-f|'.$paths->dockerComposePath.'|up|-d', file_get_contents($this->dockerLogPath()));
    }

    public function test_bootstrap_supports_standalone_docker_compose_binary(): void
    {
        $binary = $this->makeFakeDockerComposeBinary();
        config(['worker.docker.binary' => $binary]);

        $paths = $this->makeWorkspacePaths('bootstrap-standalone');
        file_put_contents($paths->dockerComposePath, "services:\n  app:\n    image: php:8.3-cli\n");

        app(DockerComposeEnvironmentService::class)->bootstrap($this->makeTask(), $paths);

        $this->assertSame(
            '-f|'.$paths->dockerComposePath.'|up|-d'."\n",
            file_get_contents($this->dockerLogPath())
        );
    }

    public function test_wrap_command_uses_docker_compose_exec_when_compose_exists(): void
    {
        $paths = $this->makeWorkspacePaths('wrap');
        file_put_contents($paths->dockerComposePath, "services:\n  app:\n    image: php:8.3-cli\n");

        $wrapped = app(DockerComposeEnvironmentService::class)->wrapCommandForTask(
            $this->makeTask(),
            $paths,
            'php artisan test'
        );

        $this->assertStringContainsString('compose -f', $wrapped);
        $this->assertStringContainsString('exec -T', $wrapped);
        $this->assertStringContainsString("'app'", $wrapped);
        $this->assertStringContainsString("'php artisan test'", $wrapped);
    }

    public function test_teardown_stops_compose_when_enabled(): void
    {
        $binary = $this->makeFakeDockerBinary();
        config(['worker.docker.binary' => $binary]);

        $paths = $this->makeWorkspacePaths('teardown');
        file_put_contents($paths->dockerComposePath, "services:\n  app:\n    image: php:8.3-cli\n");

        app(DockerComposeEnvironmentService::class)->teardown($paths);

        $this->assertStringContainsString('compose|-f|'.$paths->dockerComposePath.'|down|--remove-orphans', file_get_contents($this->dockerLogPath()));
    }

    private function makeTask(): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map([
            'id' => 1,
            'title' => 'Docker task',
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

    private function makeFakeDockerBinary(): string
    {
        $path = $this->tempBasePath.'/fake-docker.sh';
        file_put_contents($path, "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" | tr ' ' '|' >> ".escapeshellarg($this->dockerLogPath())."\n");
        chmod($path, 0755);

        return $path;
    }

    private function makeFakeDockerComposeBinary(): string
    {
        $path = $this->tempBasePath.'/docker-compose';
        file_put_contents($path, "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" | tr ' ' '|' >> ".escapeshellarg($this->dockerLogPath())."\n");
        chmod($path, 0755);

        return $path;
    }

    private function dockerLogPath(): string
    {
        return $this->tempBasePath.'/docker.log';
    }
}
