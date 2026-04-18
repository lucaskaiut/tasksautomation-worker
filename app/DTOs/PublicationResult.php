<?php

namespace App\DTOs;

readonly class PublicationResult extends DataTransferObject
{
    /**
     * @param  list<string>  $changedFiles
     */
    public function __construct(
        public string $branchName,
        public string $commitSha,
        public string $commitMessage,
        public array $changedFiles,
    ) {}
}
