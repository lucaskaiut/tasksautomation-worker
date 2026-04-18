<?php

namespace Tests\Unit\Execution;

use App\DTOs\WorkspacePaths;
use App\Services\Execution\CursorExecutorService;
use App\Services\Execution\Exceptions\CursorBinaryNotFoundException;
use App\Services\Execution\Exceptions\CursorExecutionTimeoutException;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CursorExecutorServiceTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir().'/tasksautomation-worker-cursor-'.uniqid('', true);
        File::ensureDirectoryExists($this->tempBasePath);
        config(['worker.process_timeout_seconds' => 5]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempBasePath)) {
            File::deleteDirectory($this->tempBasePath);
        }

        parent::tearDown();
    }

    public function test_build_command_uses_binary_and_prompt_file(): void
    {
        $binary = $this->makeExecutableScript(<<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);
        config([
            'worker.codex.binary' => $binary,
            'worker.codex.sandbox' => 'workspace-write',
            'worker.codex.ephemeral' => true,
            'worker.codex.skip_git_repo_check' => false,
        ]);
        $paths = $this->makeWorkspacePaths('build');

        $command = app(CursorExecutorService::class)->buildCommand($paths);

        $this->assertSame($binary, $command[0]);
        $this->assertSame('exec', $command[1]);
        $this->assertSame('--full-auto', $command[2]);
        $this->assertSame('--sandbox', $command[3]);
        $this->assertSame('workspace-write', $command[4]);
        $this->assertSame('--ephemeral', $command[5]);
        $this->assertSame("# Prompt\n\nConteudo\n", $command[6]);
    }

    public function test_execute_successfully_captures_output_and_writes_logs(): void
    {
        $binary = $this->makeExecutableScript(<<<'BASH'
#!/usr/bin/env bash
echo "stdout ok"
echo "stderr ok" >&2
exit 0
BASH);
        config(['worker.codex.binary' => $binary]);
        $paths = $this->makeWorkspacePaths('success');

        $result = app(CursorExecutorService::class)->execute($paths);

        $this->assertTrue($result->succeeded);
        $this->assertSame(0, $result->exitCode);
        $this->assertStringContainsString('stdout ok', $result->stdout);
        $this->assertStringContainsString('stderr ok', $result->stderr);
        $this->assertFileExists($paths->logsPath.'/codex-command.txt');
        $this->assertFileExists($paths->logsPath.'/codex-stdout.log');
        $this->assertFileExists($paths->logsPath.'/codex-stderr.log');
        $this->assertStringContainsString('stdout ok', file_get_contents($paths->logsPath.'/codex-stdout.log'));
        $this->assertStringContainsString('stderr ok', file_get_contents($paths->logsPath.'/codex-stderr.log'));
    }

    public function test_execute_returns_failed_result_for_non_zero_exit_code(): void
    {
        $binary = $this->makeExecutableScript(<<<'BASH'
#!/usr/bin/env bash
echo "failing"
echo "something went wrong" >&2
exit 7
BASH);
        config(['worker.codex.binary' => $binary]);
        $paths = $this->makeWorkspacePaths('failure');

        $result = app(CursorExecutorService::class)->execute($paths);

        $this->assertFalse($result->succeeded);
        $this->assertSame(7, $result->exitCode);
        $this->assertStringContainsString('failing', $result->stdout);
        $this->assertStringContainsString('something went wrong', $result->stderr);
    }

    public function test_execute_throws_when_binary_does_not_exist(): void
    {
        config(['worker.codex.binary' => $this->tempBasePath.'/missing-codex']);

        $this->expectException(CursorBinaryNotFoundException::class);

        app(CursorExecutorService::class)->execute($this->makeWorkspacePaths('missing-binary'));
    }

    public function test_execute_throws_timeout_exception_when_process_exceeds_limit(): void
    {
        $binary = $this->makeExecutableScript(<<<'BASH'
#!/usr/bin/env bash
sleep 2
echo "done"
BASH);
        config([
            'worker.codex.binary' => $binary,
            'worker.process_timeout_seconds' => 1,
        ]);

        $this->expectException(CursorExecutionTimeoutException::class);

        app(CursorExecutorService::class)->execute($this->makeWorkspacePaths('timeout'));
    }

    public function test_execute_invokes_tick_callback_while_process_is_running(): void
    {
        $binary = $this->makeExecutableScript(<<<'BASH'
#!/usr/bin/env bash
sleep 1
echo "done"
BASH);
        config([
            'worker.codex.binary' => $binary,
            'worker.process_timeout_seconds' => 5,
            'worker.heartbeat.poll_interval_milliseconds' => 100,
        ]);

        $ticks = 0;

        $result = app(CursorExecutorService::class)->execute(
            $this->makeWorkspacePaths('ticks'),
            function () use (&$ticks): void {
                $ticks++;
            }
        );

        $this->assertTrue($result->succeeded);
        $this->assertGreaterThan(0, $ticks);
    }

    private function makeWorkspacePaths(string $name): WorkspacePaths
    {
        $root = $this->tempBasePath.'/workspace-'.$name;
        File::ensureDirectoryExists($root.'/repo');
        File::ensureDirectoryExists($root.'/context');
        File::ensureDirectoryExists($root.'/logs');
        file_put_contents($root.'/prompt.md', "# Prompt\n\nConteudo\n");

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

    private function makeExecutableScript(string $contents): string
    {
        $path = $this->tempBasePath.'/script-'.uniqid('', true).'.sh';
        file_put_contents($path, $contents);
        chmod($path, 0755);

        return $path;
    }
}
