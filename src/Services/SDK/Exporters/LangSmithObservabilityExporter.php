<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK\Exporters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelAIEngine\Contracts\ObservabilityExporter;

class LangSmithObservabilityExporter implements ObservabilityExporter
{
    public function export(string $type, array $payload): void
    {
        $endpoint = (string) config('ai-engine.observability.langsmith.endpoint', '');
        $apiKey = (string) config('ai-engine.observability.langsmith.api_key', '');

        if ($endpoint === '' || $apiKey === '') {
            return;
        }

        Http::timeout((int) config('ai-engine.observability.langsmith.timeout', 10))
            ->withHeaders([
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($endpoint, [
                'id' => (string) ($payload['id'] ?? Str::uuid()),
                'name' => (string) ($payload['name'] ?? $type),
                'run_type' => 'chain',
                'project_name' => (string) config('ai-engine.observability.langsmith.project', config('app.name', 'laravel-ai-engine')),
                'inputs' => ['type' => $type],
                'outputs' => $payload,
                'extra' => ['metadata' => (array) ($payload['metadata'] ?? [])],
            ]);
    }
}
