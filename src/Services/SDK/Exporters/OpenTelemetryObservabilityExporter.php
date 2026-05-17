<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK\Exporters;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Contracts\ObservabilityExporter;

class OpenTelemetryObservabilityExporter implements ObservabilityExporter
{
    public function export(string $type, array $payload): void
    {
        $endpoint = (string) config('ai-engine.observability.opentelemetry.endpoint', '');
        if ($endpoint === '') {
            return;
        }

        Http::timeout((int) config('ai-engine.observability.opentelemetry.timeout', 10))
            ->withHeaders((array) config('ai-engine.observability.opentelemetry.headers', []))
            ->post($endpoint, $this->payload($type, $payload));
    }

    protected function payload(string $type, array $payload): array
    {
        $name = (string) ($payload['name'] ?? $type);
        $traceId = substr(hash('sha256', (string) ($payload['id'] ?? $name)), 0, 32);
        $spanId = substr(hash('sha256', $traceId . $name), 0, 16);

        return [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [[
                        'key' => 'service.name',
                        'value' => ['stringValue' => (string) config('ai-engine.observability.opentelemetry.service_name', 'laravel-ai-engine')],
                    ]],
                ],
                'scopeSpans' => [[
                    'scope' => ['name' => 'laravel-ai-engine'],
                    'spans' => [[
                        'traceId' => $traceId,
                        'spanId' => $spanId,
                        'name' => $name,
                        'kind' => 1,
                        'attributes' => $this->attributes(array_merge(['ai.export.type' => $type], (array) ($payload['metadata'] ?? []))),
                        'status' => ['code' => ($payload['status'] ?? 'ok') === 'ok' ? 1 : 2],
                    ]],
                ]],
            ]],
        ];
    }

    protected function attributes(array $metadata): array
    {
        $attributes = [];
        foreach ($metadata as $key => $value) {
            $attributes[] = [
                'key' => (string) $key,
                'value' => ['stringValue' => is_scalar($value) ? (string) $value : json_encode($value)],
            ];
        }

        return $attributes;
    }
}
