<?php

namespace App\Services\Repository;

use App\DTOs\RepositoryResolution;
use App\DTOs\TaskData;
use App\Services\Repository\Exceptions\ProjectRepositoryNotConfiguredException;
use App\Services\Repository\Exceptions\ProjectRepositoryPathNotFoundException;
use App\Services\Service;
use Illuminate\Filesystem\Filesystem;

class ProjectRepositoryResolver extends Service
{
    public const STRATEGY_LOCAL_EXISTING = 'local_existing';

    public const STRATEGY_AUTOMATIC_CLONE = 'automatic_clone';

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function resolveForTask(TaskData $task): RepositoryResolution
    {
        $project = $task->project;

        if ($project === null) {
            throw new ProjectRepositoryNotConfiguredException(
                sprintf('Task %d does not include project data required for repository resolution.', $task->id)
            );
        }

        $repositoryUrl = trim($project->repositoryUrl);
        $defaultBranch = trim($project->defaultBranch);

        $configuredPath = $this->configuredPathForProject($project->slug, $repositoryUrl);

        if ($configuredPath !== null) {
            $resolvedPath = $this->normalizePath($configuredPath);

            if (! $this->filesystem->isDirectory($resolvedPath)) {
                throw new ProjectRepositoryPathNotFoundException(
                    sprintf(
                        'Configured local repository path does not exist for project "%s": %s',
                        $project->slug,
                        $resolvedPath
                    )
                );
            }

            return new RepositoryResolution(
                strategy: self::STRATEGY_LOCAL_EXISTING,
                expectedBasePath: $resolvedPath,
                repositoryUrl: $repositoryUrl,
                defaultBranch: $defaultBranch,
            );
        }

        if ($repositoryUrl === '' || $defaultBranch === '') {
            throw new ProjectRepositoryNotConfiguredException(
                sprintf(
                    'Repository resolution requires project.repository_url and project.default_branch when no local mapping exists for project "%s".',
                    $project->slug
                )
            );
        }

        return new RepositoryResolution(
            strategy: self::STRATEGY_AUTOMATIC_CLONE,
            expectedBasePath: $this->automaticCloneBasePath($project->slug, $repositoryUrl),
            repositoryUrl: $repositoryUrl,
            defaultBranch: $defaultBranch,
        );
    }

    private function configuredPathForProject(string $projectSlug, string $repositoryUrl): ?string
    {
        $slugMap = config('worker.repositories.by_project_slug', []);
        $urlMap = config('worker.repositories.by_repository_url', []);

        $slugPath = $slugMap[$projectSlug] ?? null;

        if (is_string($slugPath) && trim($slugPath) !== '') {
            return $slugPath;
        }

        $urlPath = $urlMap[$repositoryUrl] ?? null;

        if (is_string($urlPath) && trim($urlPath) !== '') {
            return $urlPath;
        }

        return null;
    }

    private function automaticCloneBasePath(string $projectSlug, string $repositoryUrl): string
    {
        $basePath = rtrim((string) config('worker.repositories.automatic_clone_base_path'), DIRECTORY_SEPARATOR);

        if ($basePath === '') {
            throw new ProjectRepositoryNotConfiguredException(
                'Repository resolution requires worker.repositories.automatic_clone_base_path for automatic clone strategy.'
            );
        }

        return $basePath.DIRECTORY_SEPARATOR.$this->repositoryDirectoryName($projectSlug, $repositoryUrl);
    }

    private function repositoryDirectoryName(string $projectSlug, string $repositoryUrl): string
    {
        $projectSlug = trim($projectSlug);

        if ($projectSlug !== '') {
            return $projectSlug;
        }

        $path = parse_url($repositoryUrl, PHP_URL_PATH);
        $candidate = is_string($path) ? basename($path) : '';
        $candidate = preg_replace('/\.git$/', '', $candidate) ?? '';
        $candidate = trim($candidate);

        if ($candidate !== '') {
            return $candidate;
        }

        return 'repository-'.substr(sha1($repositoryUrl), 0, 12);
    }

    private function normalizePath(string $path): string
    {
        $realPath = realpath($path);

        if ($realPath !== false) {
            return $realPath;
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
