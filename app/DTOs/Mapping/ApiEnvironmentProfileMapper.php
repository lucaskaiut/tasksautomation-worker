<?php

namespace App\DTOs\Mapping;

use App\DTOs\EnvironmentProfileData;

final class ApiEnvironmentProfileMapper
{
    public static function map(array $data): EnvironmentProfileData
    {
        return new EnvironmentProfileData(
            id: (int) ($data['id'] ?? 0),
            projectId: (int) ($data['project_id'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
            isDefault: (bool) ($data['is_default'] ?? false),
            dockerComposeYml: ApiValue::optionalString($data, 'docker_compose_yml'),
        );
    }
}
