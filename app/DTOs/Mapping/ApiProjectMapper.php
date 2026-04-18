<?php

namespace App\DTOs\Mapping;

use App\DTOs\ProjectData;

final class ApiProjectMapper
{
    public static function map(array $data): ProjectData
    {
        return new ProjectData(
            id: (int) ($data['id'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
            description: ApiValue::optionalString($data, 'description'),
            repositoryUrl: (string) ($data['repository_url'] ?? ''),
            defaultBranch: (string) ($data['default_branch'] ?? ''),
            globalRules: ApiValue::optionalMixed($data, 'global_rules'),
            isActive: (bool) ($data['is_active'] ?? false),
            createdAt: (string) ($data['created_at'] ?? ''),
            updatedAt: (string) ($data['updated_at'] ?? ''),
        );
    }
}
