<?php

namespace App\DTOs;

readonly class ProjectData extends DataTransferObject
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public string $repositoryUrl,
        public string $defaultBranch,
        public mixed $globalRules,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public function toNormalizedArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'repository_url' => $this->repositoryUrl,
            'default_branch' => $this->defaultBranch,
            'global_rules' => $this->globalRules,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
