<?php

namespace App\Support\Enums;

enum TaskAnalysisDomain: string
{
    case Backend = 'backend';
    case Frontend = 'frontend';
    case Infra = 'infra';

    public static function fromStage(TaskStage $stage): ?self
    {
        return match ($stage) {
            TaskStage::ImplementationBackend => self::Backend,
            TaskStage::ImplementationFrontend => self::Frontend,
            TaskStage::ImplementationInfra => self::Infra,
            TaskStage::Analysis => null,
        };
    }
}
