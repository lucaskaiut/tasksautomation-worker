<?php

namespace App\Services\Api;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\TaskData;
use App\DTOs\TaskWithApiMessage;
use App\Services\Api\Exceptions\TaskApiAuthenticationException;
use App\Services\Api\Exceptions\TaskApiHttpException;
use App\Services\Api\Exceptions\TaskApiNetworkException;
use App\Services\Api\Exceptions\TaskApiUnexpectedResponseException;
use App\Services\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TaskApiClient extends Service
{
    private ?string $accessToken = null;

    public function claimTask(): ?TaskData
    {
        try {
            $response = $this->pendingRequest()->post($this->claimPath(), [
                'worker_id' => config('worker.worker_id'),
            ]);
        } catch (ConnectionException $e) {
            throw new TaskApiNetworkException($e->getMessage(), $e);
        }

        if ($response->status() === 204) {
            return null;
        }

        $this->throwIfNotSuccessful($response);

        $payload = $response->json();

        return $this->parseTaskEnvelope($payload, allowNullTask: true);
    }

    public function heartbeat(int $taskId): TaskWithApiMessage
    {
        try {
            $response = $this->pendingRequest()->post($this->heartbeatPath($taskId));
        } catch (ConnectionException $e) {
            throw new TaskApiNetworkException($e->getMessage(), $e);
        }

        if ($response->status() === 204) {
            throw new TaskApiUnexpectedResponseException('Heartbeat response was empty (204).');
        }

        $this->throwIfNotSuccessful($response);

        $payload = $response->json();
        $task = $this->parseTaskEnvelope($payload, allowNullTask: false);

        if ($task === null) {
            throw new TaskApiUnexpectedResponseException('Heartbeat response did not include task data.');
        }

        return new TaskWithApiMessage(
            $task,
            $this->parseRootMessage($payload),
        );
    }

    public function finishTask(int $taskId, array $payload): void
    {
        try {
            $response = $this->pendingRequest()->post($this->finishPath($taskId), $payload);
        } catch (ConnectionException $e) {
            throw new TaskApiNetworkException($e->getMessage(), $e);
        }

        $this->throwIfNotSuccessful($response);
    }

    protected function pendingRequest(): PendingRequest
    {
        $pending = Http::baseUrl($this->normalizedBaseUrl())
            ->timeout((int) config('worker.api.timeout_seconds', 30))
            ->connectTimeout((int) config('worker.api.connect_timeout_seconds', 10))
            ->acceptJson()
            ->asJson();

        $pending = $pending->withToken($this->accessToken());

        return $pending;
    }

    protected function accessToken(): string
    {
        if (is_string($this->accessToken) && $this->accessToken !== '') {
            return $this->accessToken;
        }

        try {
            $response = Http::baseUrl($this->normalizedBaseUrl())
                ->timeout((int) config('worker.api.timeout_seconds', 30))
                ->connectTimeout((int) config('worker.api.connect_timeout_seconds', 10))
                ->acceptJson()
                ->asJson()
                ->post($this->tokenPath(), [
                    'email' => $this->apiEmail(),
                    'password' => $this->apiPassword(),
                    'token_name' => (string) config('worker.api.token_name', 'worker'),
                    'abilities' => config('worker.api.abilities', ['*']),
                ]);
        } catch (ConnectionException $e) {
            throw new TaskApiNetworkException($e->getMessage(), $e);
        }

        $this->throwIfNotSuccessful($response);

        $token = $this->parseAccessToken($response->json());

        if ($token === null || $token === '') {
            throw new TaskApiUnexpectedResponseException('Token creation response did not include an access token.');
        }

        return $this->accessToken = $token;
    }

    protected function normalizedBaseUrl(): string
    {
        return rtrim((string) config('worker.api.base_url'), '/');
    }

    protected function claimPath(): string
    {
        return (string) config('worker.api.claim_path');
    }

    protected function tokenPath(): string
    {
        return (string) config('worker.api.token_path');
    }

    protected function heartbeatPath(int $taskId): string
    {
        return sprintf((string) config('worker.api.heartbeat_path_template'), $taskId);
    }

    protected function finishPath(int $taskId): string
    {
        return sprintf((string) config('worker.api.finish_path_template'), $taskId);
    }

    protected function parseTaskEnvelope(mixed $payload, bool $allowNullTask): ?TaskData
    {
        if ($payload === null) {
            throw new TaskApiUnexpectedResponseException('API response body was empty or invalid JSON.');
        }

        if (! is_array($payload)) {
            throw new TaskApiUnexpectedResponseException('API response did not contain a JSON object.');
        }

        $task = $payload['data'] ?? $payload['task'] ?? null;

        if ($task === null) {
            if ($allowNullTask) {
                return null;
            }

            throw new TaskApiUnexpectedResponseException('API response did not include a task object.');
        }

        if (! is_array($task)) {
            throw new TaskApiUnexpectedResponseException('API response task field was not an object.');
        }

        return ApiTaskMapper::map($task);
    }

    protected function parseRootMessage(mixed $payload): ?string
    {
        if (! is_array($payload) || ! array_key_exists('message', $payload) || $payload['message'] === null) {
            return null;
        }

        return (string) $payload['message'];
    }

    protected function parseAccessToken(mixed $payload): ?string
    {
        if (is_string($payload) && trim($payload) !== '') {
            return $payload;
        }

        if (! is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload['token'] ?? null,
            $payload['access_token'] ?? null,
            $payload['data']['token'] ?? null,
            $payload['data']['access_token'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    protected function apiEmail(): string
    {
        $email = (string) config('worker.api.email', '');

        if ($email === '') {
            throw new TaskApiAuthenticationException('Worker API email is not configured.');
        }

        return $email;
    }

    protected function apiPassword(): string
    {
        $password = (string) config('worker.api.password', '');

        if ($password === '') {
            throw new TaskApiAuthenticationException('Worker API password is not configured.');
        }

        return $password;
    }

    protected function throwIfNotSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            throw new TaskApiAuthenticationException(
                sprintf('Task API rejected the request with HTTP %d.', $status),
                $status,
            );
        }

        throw new TaskApiHttpException(
            sprintf('Task API returned HTTP %d: %s', $status, $response->body()),
            $status,
        );
    }
}
