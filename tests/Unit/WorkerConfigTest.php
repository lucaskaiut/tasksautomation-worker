<?php

namespace Tests\Unit;

use Tests\TestCase;

class WorkerConfigTest extends TestCase
{
    public function test_worker_config_loads_with_expected_structure(): void
    {
        $worker = config('worker');

        $this->assertIsArray($worker);
        $this->assertArrayHasKey('api', $worker);
        $this->assertArrayHasKey('base_url', $worker['api']);
        $this->assertArrayHasKey('timeout_seconds', $worker['api']);
        $this->assertArrayHasKey('connect_timeout_seconds', $worker['api']);
        $this->assertArrayHasKey('token_path', $worker['api']);
        $this->assertArrayHasKey('email', $worker['api']);
        $this->assertArrayHasKey('password', $worker['api']);
        $this->assertArrayHasKey('token_name', $worker['api']);
        $this->assertArrayHasKey('abilities', $worker['api']);
        $this->assertArrayHasKey('claim_path', $worker['api']);
        $this->assertArrayHasKey('heartbeat_path_template', $worker['api']);
        $this->assertArrayHasKey('finish_path_template', $worker['api']);
        $this->assertArrayHasKey('worker_id', $worker);
        $this->assertArrayHasKey('polling_interval_seconds', $worker);
        $this->assertArrayHasKey('heartbeat_interval_seconds', $worker);
        $this->assertArrayHasKey('max_concurrent_tasks', $worker);
        $this->assertArrayHasKey('max_attempts_per_execution', $worker);
        $this->assertArrayHasKey('workspaces_path', $worker);
        $this->assertArrayHasKey('repositories', $worker);
        $this->assertArrayHasKey('by_project_slug', $worker['repositories']);
        $this->assertArrayHasKey('by_repository_url', $worker['repositories']);
        $this->assertArrayHasKey('automatic_clone_base_path', $worker['repositories']);
        $this->assertArrayHasKey('git_binary', $worker['repositories']);
        $this->assertArrayHasKey('validation', $worker);
        $this->assertArrayHasKey('global_commands', $worker['validation']);
        $this->assertArrayHasKey('commands_by_environment_profile_slug', $worker['validation']);
        $this->assertArrayHasKey('stop_on_failure', $worker['validation']);
        $this->assertArrayHasKey('heartbeat', $worker);
        $this->assertArrayHasKey('fail_on_error', $worker['heartbeat']);
        $this->assertArrayHasKey('poll_interval_milliseconds', $worker['heartbeat']);
        $this->assertArrayHasKey('docker', $worker);
        $this->assertArrayHasKey('binary', $worker['docker']);
        $this->assertArrayHasKey('compose_filename', $worker['docker']);
        $this->assertArrayHasKey('default_exec_service', $worker['docker']);
        $this->assertArrayHasKey('exec_service_by_environment_profile_slug', $worker['docker']);
        $this->assertArrayHasKey('shutdown_after_task', $worker['docker']);
        $this->assertArrayHasKey('publication', $worker);
        $this->assertArrayHasKey('enabled', $worker['publication']);
        $this->assertArrayHasKey('git_user_name', $worker['publication']);
        $this->assertArrayHasKey('git_user_email', $worker['publication']);
        $this->assertArrayHasKey('remote_name', $worker['publication']);
        $this->assertArrayHasKey('codex', $worker);
        $this->assertArrayHasKey('binary', $worker['codex']);
        $this->assertArrayHasKey('sandbox', $worker['codex']);
        $this->assertArrayHasKey('ephemeral', $worker['codex']);
        $this->assertArrayHasKey('skip_git_repo_check', $worker['codex']);
        $this->assertArrayHasKey('process_timeout_seconds', $worker);
        $this->assertArrayHasKey('cleanup_workspace', $worker);
        $this->assertArrayHasKey('cleanup_workspace_on_success', $worker);
        $this->assertArrayHasKey('cleanup_workspace_on_failure', $worker);
    }

    public function test_application_bootstraps(): void
    {
        $this->assertNotNull($this->app);
        $this->assertTrue($this->app->isBooted());
    }
}
