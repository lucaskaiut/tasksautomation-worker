<?php

namespace App\DTOs;

readonly class RepositoryResolution extends DataTransferObject
{
    public function __construct(
        public string $strategy,
        public string $expectedBasePath,
        public string $repositoryUrl,
        public string $defaultBranch,
    ) {}

    public function toNormalizedArray(): array
    {
        return [
            'strategy' => $this->strategy,
            'expected_base_path' => $this->expectedBasePath,
            'repository_url' => $this->repositoryUrl,
            'default_branch' => $this->defaultBranch,
        ];
    }
}
