<?php

namespace Tests\Unit\Notifications\Evolution;

use App\Services\Notifications\Evolution\EvolutionApiClient;
use App\Services\Notifications\Evolution\Exceptions\EvolutionApiException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionApiClientTest extends TestCase
{
    public function test_send_text_uses_configured_url_and_payload(): void
    {
        config([
            'evolution.whatsapp.base_url' => 'https://evolution.example.com/',
            'evolution.whatsapp.api_key' => 'secret-key',
            'evolution.whatsapp.instance_name' => 'worker-instance',
            'evolution.whatsapp.destination_number' => '5511999999999',
            'evolution.whatsapp.timeout_seconds' => 10,
            'evolution.whatsapp.connect_timeout_seconds' => 5,
        ]);

        Http::fake([
            'https://evolution.example.com/message/sendText/worker-instance' => Http::response(['ok' => true], 200),
        ]);

        app(EvolutionApiClient::class)->sendText('mensagem final');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://evolution.example.com/message/sendText/worker-instance'
                && $request->method() === 'POST'
                && $request->hasHeader('apikey', 'secret-key')
                && $request['number'] === '5511999999999'
                && $request['text'] === 'mensagem final';
        });
    }

    public function test_send_text_exposes_computed_url(): void
    {
        config([
            'evolution.whatsapp.base_url' => 'https://evolution.example.com/',
            'evolution.whatsapp.api_key' => 'secret-key',
            'evolution.whatsapp.instance_name' => 'worker-instance',
        ]);

        $this->assertSame(
            'https://evolution.example.com/message/sendText/worker-instance',
            app(EvolutionApiClient::class)->sendTextUrl(),
        );
    }

    public function test_send_text_throws_clear_exception_on_http_error(): void
    {
        config([
            'evolution.whatsapp.base_url' => 'https://evolution.example.com',
            'evolution.whatsapp.api_key' => 'secret-key',
            'evolution.whatsapp.instance_name' => 'worker-instance',
            'evolution.whatsapp.destination_number' => '5511999999999',
        ]);

        Http::fake([
            '*' => Http::response(['message' => 'bad gateway'], 502),
        ]);

        $this->expectException(EvolutionApiException::class);
        $this->expectExceptionMessage('Evolution API returned HTTP 502 while sending WhatsApp notification.');

        app(EvolutionApiClient::class)->sendText('mensagem final');
    }

    public function test_send_text_requires_api_key_configuration(): void
    {
        config([
            'evolution.whatsapp.base_url' => 'https://evolution.example.com',
            'evolution.whatsapp.instance_name' => 'worker-instance',
            'evolution.whatsapp.destination_number' => '5511999999999',
            'evolution.whatsapp.api_key' => '',
        ]);

        $this->expectException(EvolutionApiException::class);
        $this->expectExceptionMessage('Evolution API key is not configured.');

        app(EvolutionApiClient::class)->sendText('mensagem final');
    }
}
