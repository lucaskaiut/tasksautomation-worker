<?php

namespace App\Support\Enums;

enum TaskStage: string
{
    case Analysis = 'analysis';
    case ImplementationBackend = 'implementation:backend';
    case ImplementationFrontend = 'implementation:frontend';
    case ImplementationInfra = 'implementation:infra';

    public static function fromTaskValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::ImplementationBackend;
    }

    public function isAnalysis(): bool
    {
        return $this === self::Analysis;
    }

    public function implementationDomain(): ?string
    {
        return match ($this) {
            self::ImplementationBackend => 'backend',
            self::ImplementationFrontend => 'frontend',
            self::ImplementationInfra => 'infra',
            self::Analysis => null,
        };
    }
}
