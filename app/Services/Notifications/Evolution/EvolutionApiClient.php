<?php

namespace App\Services\Notifications\Evolution;

use App\Services\Notifications\Evolution\Exceptions\EvolutionApiException;
use App\Services\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class EvolutionApiClient extends Service
{
    public function sendText(string $message): void
    {
        $url = $this->sendTextUrl();

        try {
            $response = $this->pendingRequest()->post($url, [
                'number' => $this->destinationNumber(),
                'text' => $message,
            ]);
        } catch (ConnectionException $e) {
            throw new EvolutionApiException('Failed to connect to Evolution API.', previous: $e);
        }

        if ($response->successful()) {
            return;
        }

        throw new EvolutionApiException(sprintf(
            'Evolution API returned HTTP %d while sending WhatsApp notification.',
            $response->status(),
        ));
    }

    public function sendTextUrl(): string
    {
        return rtrim($this->requiredConfig('evolution.whatsapp.base_url', 'Evolution API base URL'), '/')
            .'/message/sendText/'
            .$this->instanceName();
    }

    public function destinationNumber(): string
    {
        return $this->requiredConfig('evolution.whatsapp.destination_number', 'Evolution destination number');
    }

    private function instanceName(): string
    {
        return $this->requiredConfig('evolution.whatsapp.instance_name', 'Evolution instance name');
    }

    private function pendingRequest(): PendingRequest
    {
        return Http::timeout((int) config('evolution.whatsapp.timeout_seconds', 10))
            ->connectTimeout((int) config('evolution.whatsapp.connect_timeout_seconds', 5))
            ->withHeaders([
                'apikey' => $this->apiKey(),
            ])
            ->acceptJson()
            ->asJson();
    }

    private function apiKey(): string
    {
        return $this->requiredConfig('evolution.whatsapp.api_key', 'Evolution API key');
    }

    private function requiredConfig(string $key, string $label): string
    {
        $value = trim((string) config($key, ''));

        if ($value === '') {
            throw new EvolutionApiException(sprintf('%s is not configured.', $label));
        }

        return $value;
    }
}
