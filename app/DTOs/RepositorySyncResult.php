<?php

namespace App\DTOs;

readonly class RepositorySyncResult extends DataTransferObject
{
    public function __construct(
        public string $strategy,
        public string $cachePath,
        public string $workspaceRepositoryPath,
        public string $defaultBranch,
    ) {}
}
