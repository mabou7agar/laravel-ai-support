<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Contracts\ObservabilityExporter;
use LaravelAIEngine\Services\SDK\EvaluationService;
use LaravelAIEngine\Services\SDK\ObservabilityExporterService;
use LaravelAIEngine\Services\SDK\TraceRecorderService;
use LaravelAIEngine\Tests\UnitTestCase;

class ObservabilityExporterServiceTest extends UnitTestCase
{
    public function test_trace_and_evaluation_services_export_when_exporter_is_configured(): void
    {
        ExporterSpy::$exports = [];
        config()->set('ai-engine.observability.exporters', [ExporterSpy::class]);

        $exporter = new ObservabilityExporterService(app());
        $trace = new TraceRecorderService($exporter);
        $evaluation = new EvaluationService($exporter);

        $span = $trace->start('agent.run', ['tenant' => 'acme']);
        $trace->end($span, 'ok', ['tokens' => 12]);
        $evaluation->evaluate('answer-check', 'ok', 'ok');

        $this->assertSame('trace', ExporterSpy::$exports[0]['type']);
        $this->assertSame('evaluation', ExporterSpy::$exports[1]['type']);
    }
}

class ExporterSpy implements ObservabilityExporter
{
    public static array $exports = [];

    public function export(string $type, array $payload): void
    {
        self::$exports[] = ['type' => $type, 'payload' => $payload];
    }
}
