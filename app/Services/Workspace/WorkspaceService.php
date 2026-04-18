<?php

namespace App\Services\Workspace;

use App\DTOs\TaskData;
use App\DTOs\WorkspacePaths;
use App\Services\Prompt\PromptBuilderService;
use App\Services\Service;
use Illuminate\Filesystem\Filesystem;
use JsonException;

class WorkspaceService extends Service
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly PromptBuilderService $promptBuilder,
    ) {}

    public function prepare(TaskData $task, array $rawApiPayload): WorkspacePaths
    {
        $paths = $this->resolvePaths($task->id);

        if ($this->filesystem->isDirectory($paths->root)) {
            $this->filesystem->deleteDirectory($paths->root);
        }

        $this->filesystem->makeDirectory($paths->root, 0755, true);
        $this->filesystem->makeDirectory($paths->repoPath, 0755, true);
        $this->filesystem->makeDirectory($paths->contextPath, 0755, true);
        $this->filesystem->makeDirectory($paths->logsPath, 0755, true);

        $this->filesystem->put(
            $paths->rawTaskResponsePath,
            $this->encodeJson($rawApiPayload)
        );

        $this->filesystem->put(
            $paths->taskJsonPath,
            $this->encodeJson($task->toNormalizedArray())
        );

        $this->writeDockerComposeFile($task, $paths);
        $this->writePrompt($paths, $this->promptBuilder->buildInitialPrompt($task));

        return $paths;
    }

    public function cleanup(int $taskId, bool $succeeded = true): void
    {
        if (! $this->shouldCleanup($succeeded)) {
            return;
        }

        $root = $this->taskRootPath($taskId);

        if ($this->filesystem->isDirectory($root)) {
            $this->filesystem->deleteDirectory($root);
        }
    }

    protected function shouldCleanup(bool $succeeded): bool
    {
        if (! config('worker.cleanup_workspace', true)) {
            return false;
        }

        return $succeeded
            ? (bool) config('worker.cleanup_workspace_on_success', true)
            : (bool) config('worker.cleanup_workspace_on_failure', false);
    }

    protected function resolvePaths(int $taskId): WorkspacePaths
    {
        $root = $this->taskRootPath($taskId);
        $sep = DIRECTORY_SEPARATOR;

        return new WorkspacePaths(
            root: $root,
            repoPath: $root.$sep.'repo',
            contextPath: $root.$sep.'context',
            logsPath: $root.$sep.'logs',
            dockerComposePath: $root.$sep.(string) config('worker.docker.compose_filename', 'docker-compose.yml'),
            rawTaskResponsePath: $root.$sep.'raw-task-response.json',
            taskJsonPath: $root.$sep.'task.json',
            promptMdPath: $root.$sep.'prompt.md',
        );
    }

    protected function taskRootPath(int $taskId): string
    {
        return rtrim((string) config('worker.workspaces_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$taskId;
    }

    public function writePrompt(WorkspacePaths $paths, string $prompt): void
    {
        $this->filesystem->put($paths->promptMdPath, $prompt);
    }

    protected function writeDockerComposeFile(TaskData $task, WorkspacePaths $paths): void
    {
        $dockerComposeYml = $task->environmentProfile?->dockerComposeYml;

        if ($dockerComposeYml === null || trim($dockerComposeYml) === '') {
            return;
        }

        $this->filesystem->put($paths->dockerComposePath, $dockerComposeYml);
    }

    protected function encodeJson(array $data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to encode JSON for workspace: '.$e->getMessage(), 0, $e);
        }
    }
}
