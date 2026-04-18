<?php

namespace App\Services\Notifications;

use App\DTOs\TaskStatusNotification;
use App\Services\Service;
use Carbon\CarbonImmutable;

class TaskStatusNotificationFormatter extends Service
{
    public function format(TaskStatusNotification $notification): string
    {
        $isSuccess = $notification->result === 'success';
        $header = $isSuccess ? '🚀 Task Finalizada' : '🚨 Task Falhou';
        $resultLine = $isSuccess ? '✅ Resultado: SUCCESS' : '❌ Resultado: FAILURE';

        $lines = array_filter([
            $header,
            $this->optionalLine('📦 Projeto', $notification->task->project?->slug ?? $notification->task->project?->name),
            '🆔 Task: #'.$notification->task->id,
            $this->optionalLine('📝 Titulo', $notification->task->title),
            $resultLine,
            '📌 Status enviado: '.strtoupper($notification->reportedStatus),
            $this->optionalLine('🏷️ Ambiente', $notification->task->environmentProfile?->slug ?? $notification->task->environmentProfile?->name),
            $this->optionalLine('🌿 Branch', $notification->branchName),
            $this->optionalLine('🔀 Commit', $notification->commitSha),
            $this->optionalLine('🔗 PR', $notification->pullRequestUrl),
            $this->optionalLine('📄 Logs', $notification->logsPath),
            $this->optionalLine('🕒 Data/Hora', $this->formatTimestamp($notification->occurredAt)),
            $this->optionalLine('📋 Resumo', $notification->executionSummary),
            $this->optionalLine('⚠️ Motivo', $notification->failureReason),
        ], static fn (?string $line): bool => $line !== null && $line !== '');

        return implode("\n", $lines);
    }

    private function optionalLine(string $label, ?string $value): ?string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        return $label.': '.$trimmed;
    }

    private function formatTimestamp(?string $timestamp): ?string
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }

        return CarbonImmutable::parse($timestamp)->utc()->format('Y-m-d H:i:s').' UTC';
    }
}
