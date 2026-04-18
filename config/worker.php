<?php

$decodeJsonMap = static function (string $envKey): array {
    $value = env($envKey);

    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
};

$decodeJsonList = static function (string $envKey): array {
    $value = env($envKey);

    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    if (! is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
};

return [
    'api' => [
        'base_url' => env('WORKER_API_BASE_URL', 'http://localhost'),
        'timeout_seconds' => (int) env('WORKER_API_TIMEOUT', 30),
        'connect_timeout_seconds' => (int) env('WORKER_API_CONNECT_TIMEOUT', 10),
        'token_path' => env('WORKER_API_TOKEN_PATH', 'api/tokens/create'),
        'email' => env('WORKER_API_EMAIL'),
        'password' => env('WORKER_API_PASSWORD'),
        'token_name' => env('WORKER_API_TOKEN_NAME', 'worker'),
        'abilities' => $decodeJsonList('WORKER_API_ABILITIES') ?: ['*'],
        'claim_path' => env('WORKER_API_CLAIM_PATH', 'api/tasks/claim'),
        'heartbeat_path_template' => env('WORKER_API_HEARTBEAT_PATH_TEMPLATE', 'api/tasks/%d/heartbeat'),
        'finish_path_template' => env('WORKER_API_FINISH_PATH_TEMPLATE', 'api/tasks/%d/finish'),
    ],
    'worker_id' => env('WORKER_ID', 'worker-1'),
    'polling_interval_seconds' => (int) env('WORKER_POLLING_INTERVAL', 30),
    'heartbeat_interval_seconds' => (int) env('WORKER_HEARTBEAT_INTERVAL', 10),
    'max_attempts_per_execution' => (int) env('WORKER_MAX_ATTEMPTS_PER_EXECUTION', 5),
    'workspaces_path' => env('WORKER_WORKSPACES_PATH') ?: storage_path('workspaces'),
    'repositories' => [
        'by_project_slug' => $decodeJsonMap('WORKER_REPOSITORIES_BY_PROJECT_SLUG'),
        'by_repository_url' => $decodeJsonMap('WORKER_REPOSITORIES_BY_URL'),
        'automatic_clone_base_path' => env('WORKER_REPOSITORIES_AUTOMATIC_CLONE_BASE_PATH')
            ?: storage_path('repositories'),
        'git_binary' => env('WORKER_REPOSITORIES_GIT_BINARY', 'git'),
    ],
    'validation' => [
        'global_commands' => $decodeJsonList('WORKER_VALIDATION_GLOBAL_COMMANDS'),
        'commands_by_environment_profile_slug' => $decodeJsonMap('WORKER_VALIDATION_COMMANDS_BY_ENVIRONMENT_PROFILE_SLUG'),
        'stop_on_failure' => filter_var(env('WORKER_VALIDATION_STOP_ON_FAILURE', true), FILTER_VALIDATE_BOOLEAN),
    ],
    'heartbeat' => [
        'fail_on_error' => filter_var(env('WORKER_HEARTBEAT_FAIL_ON_ERROR', false), FILTER_VALIDATE_BOOLEAN),
        'poll_interval_milliseconds' => (int) env('WORKER_HEARTBEAT_POLL_INTERVAL_MS', 250),
    ],
    'docker' => [
        'binary' => env('WORKER_DOCKER_BINARY', 'docker'),
        'compose_filename' => env('WORKER_DOCKER_COMPOSE_FILENAME', 'docker-compose.yml'),
        'default_exec_service' => env('WORKER_DOCKER_DEFAULT_EXEC_SERVICE', 'app'),
        'exec_service_by_environment_profile_slug' => $decodeJsonMap('WORKER_DOCKER_EXEC_SERVICE_BY_ENVIRONMENT_PROFILE_SLUG'),
        'shutdown_after_task' => filter_var(env('WORKER_DOCKER_SHUTDOWN_AFTER_TASK', true), FILTER_VALIDATE_BOOLEAN),
    ],
    'publication' => [
        'enabled' => filter_var(env('WORKER_PUBLICATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'git_user_name' => env('WORKER_PUBLICATION_GIT_USER_NAME', 'Tasks Automation Worker'),
        'git_user_email' => env('WORKER_PUBLICATION_GIT_USER_EMAIL', 'worker@example.com'),
        'remote_name' => env('WORKER_PUBLICATION_REMOTE_NAME', 'origin'),
    ],
    'codex' => [
        'binary' => env('WORKER_CODEX_BINARY', 'codex'),
        'sandbox' => env('WORKER_CODEX_SANDBOX', 'workspace-write'),
        'ephemeral' => filter_var(env('WORKER_CODEX_EPHEMERAL', true), FILTER_VALIDATE_BOOLEAN),
        'skip_git_repo_check' => filter_var(env('WORKER_CODEX_SKIP_GIT_REPO_CHECK', false), FILTER_VALIDATE_BOOLEAN),
    ],
    'process_timeout_seconds' => (int) env('WORKER_PROCESS_TIMEOUT', 3600),
    'cleanup_workspace' => filter_var(env('WORKER_CLEANUP_WORKSPACE', true), FILTER_VALIDATE_BOOLEAN),
    'cleanup_workspace_on_success' => filter_var(env('WORKER_CLEANUP_WORKSPACE_ON_SUCCESS', true), FILTER_VALIDATE_BOOLEAN),
    'cleanup_workspace_on_failure' => filter_var(env('WORKER_CLEANUP_WORKSPACE_ON_FAILURE', false), FILTER_VALIDATE_BOOLEAN),
];
