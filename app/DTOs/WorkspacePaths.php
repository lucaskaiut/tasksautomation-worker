<?php

namespace App\DTOs;

readonly class WorkspacePaths extends DataTransferObject
{
    public function __construct(
        public string $root,
        public string $repoPath,
        public string $contextPath,
        public string $logsPath,
        public string $dockerComposePath,
        public string $rawTaskResponsePath,
        public string $taskJsonPath,
        public string $promptMdPath,
    ) {}
}
