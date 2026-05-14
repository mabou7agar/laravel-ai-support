<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LangGraphRuntimeClient
{
    public function startRun(array $payload): array
    {
        return $this->request('post', '/runs', $payload);
    }

    public function getRun(string $runId): array
    {
        return $this->request('get', "/runs/{$runId}");
    }

    public function resumeRun(string $runId, array $payload = []): array
    {
        return $this->request('post', "/runs/{$runId}/resume", $payload);
    }

    public function cancelRun(string $runId): array
    {
        return $this->request('post', "/runs/{$runId}/cancel");
    }

    public function events(string $runId): array
    {
        return $this->request('get', "/runs/{$runId}/events");
    }

    public function health(): array
    {
        return $this->request('get', '/health');
    }

    protected function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->url($path);
        $request = $this->http($payload ?? []);
        $response = $payload === null
            ? $request->{$method}($url)
            : $request->{$method}($url, $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('LangGraph runtime request failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    protected function http(array $payload): PendingRequest
    {
        $request = Http::timeout((int) config('ai-agent.runtime.langgraph.timeout', 120))
            ->retry(
                max(0, (int) config('ai-agent.runtime.langgraph.retry_times', 1)),
                max(0, (int) config('ai-agent.runtime.langgraph.retry_sleep_ms', 100))
            )
            ->acceptJson()
            ->asJson();

        $token = trim((string) config('ai-agent.runtime.langgraph.api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $secret = trim((string) config('ai-agent.runtime.langgraph.signature_secret', ''));
        if ($secret !== '') {
            $request = $request->withHeaders([
                'X-AI-Agent-Signature' => hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '[]', $secret),
            ]);
        }

        return $request;
    }

    protected function url(string $path): string
    {
        $baseUrl = rtrim((string) config('ai-agent.runtime.langgraph.base_url'), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('LangGraph base URL is not configured.');
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }
}
