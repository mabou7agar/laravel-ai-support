<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\SDK\Exporters\HttpObservabilityExporter;
use LaravelAIEngine\Services\SDK\Exporters\LangSmithObservabilityExporter;
use LaravelAIEngine\Services\SDK\Exporters\LogObservabilityExporter;
use LaravelAIEngine\Services\SDK\Exporters\OpenTelemetryObservabilityExporter;
use LaravelAIEngine\Tests\UnitTestCase;

class ObservabilityExportersTest extends UnitTestCase
{
    public function test_http_exporter_posts_configured_payload(): void
    {
        config()->set('ai-engine.observability.http.endpoint', 'https://observability.test/events');
        config()->set('ai-engine.observability.http.headers', ['X-Test' => 'yes']);

        Http::fake(['https://observability.test/events' => Http::response(['ok' => true])]);

        (new HttpObservabilityExporter())->export('trace', ['id' => 'trace-1']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://observability.test/events'
            && $request->header('X-Test')[0] === 'yes'
            && $request->data()['type'] === 'trace'
            && $request->data()['payload']['id'] === 'trace-1');
    }

    public function test_opentelemetry_exporter_posts_trace_payload_to_otlp_endpoint(): void
    {
        config()->set('ai-engine.observability.opentelemetry.endpoint', 'https://otel.test/v1/traces');
        config()->set('ai-engine.observability.opentelemetry.service_name', 'laravel-ai-engine-test');

        Http::fake(['https://otel.test/v1/traces' => Http::response(['ok' => true])]);

        (new OpenTelemetryObservabilityExporter())->export('trace', [
            'id' => 'trace-1',
            'name' => 'agent.run',
            'metadata' => ['tenant' => 'acme'],
            'started_at' => microtime(true),
            'ended_at' => microtime(true),
            'status' => 'ok',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://otel.test/v1/traces'
            && $request->data()['resourceSpans'][0]['resource']['attributes'][0]['value']['stringValue'] === 'laravel-ai-engine-test');
    }

    public function test_langsmith_exporter_posts_run_payload(): void
    {
        config()->set('ai-engine.observability.langsmith.endpoint', 'https://api.smith.langchain.com/runs');
        config()->set('ai-engine.observability.langsmith.api_key', 'ls-key');
        config()->set('ai-engine.observability.langsmith.project', 'ai-engine');

        Http::fake(['https://api.smith.langchain.com/runs' => Http::response(['ok' => true])]);

        (new LangSmithObservabilityExporter())->export('evaluation', [
            'name' => 'answer-check',
            'passed' => true,
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.smith.langchain.com/runs'
            && $request->header('x-api-key')[0] === 'ls-key'
            && $request->data()['project_name'] === 'ai-engine'
            && $request->data()['run_type'] === 'chain');
    }

    public function test_log_exporter_writes_without_throwing(): void
    {
        Log::spy();

        (new LogObservabilityExporter())->export('trace', ['id' => 'trace-1']);

        Log::shouldHaveReceived('channel')->with('ai-engine')->once();
        $this->addToAssertionCount(1);
    }
}
