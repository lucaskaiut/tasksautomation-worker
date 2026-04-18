<?php

namespace App\DTOs;

readonly class EnvironmentProfileData extends DataTransferObject
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $name,
        public string $slug,
        public bool $isDefault,
        public ?string $dockerComposeYml,
    ) {}

    public function toNormalizedArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_default' => $this->isDefault,
            'docker_compose_yml' => $this->dockerComposeYml,
        ];
    }
}
