<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Events\AgentStructuredCollectionCompleted;

class StructuredCollectionCallbackService
{
    public function dispatch(array $callback, array $payload): void
    {
        Event::dispatch(new AgentStructuredCollectionCompleted($payload));

        if (($callback['type'] ?? null) !== 'url') {
            return;
        }

        $url = trim((string) ($callback['url'] ?? ''));
        if ($url === '') {
            return;
        }

        $method = strtolower((string) ($callback['method'] ?? 'POST'));
        $headers = is_array($callback['headers'] ?? null) ? $callback['headers'] : [];
        $timeout = (int) ($callback['timeout'] ?? config('ai-agent.structured_collection.callback_timeout', 10));

        Http::withHeaders($headers)
            ->timeout(max(1, $timeout))
            ->send(in_array($method, ['post', 'put', 'patch'], true) ? strtoupper($method) : 'POST', $url, [
                'json' => $payload,
            ]);
    }
}
