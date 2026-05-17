<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK\Exporters;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Contracts\ObservabilityExporter;

class HttpObservabilityExporter implements ObservabilityExporter
{
    public function export(string $type, array $payload): void
    {
        $endpoint = (string) config('ai-engine.observability.http.endpoint', '');
        if ($endpoint === '') {
            return;
        }

        Http::timeout((int) config('ai-engine.observability.http.timeout', 10))
            ->withHeaders((array) config('ai-engine.observability.http.headers', []))
            ->post($endpoint, [
                'type' => $type,
                'payload' => $payload,
                'exported_at' => now()->toISOString(),
            ]);
    }
}
