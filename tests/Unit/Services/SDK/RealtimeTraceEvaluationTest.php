<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\DTOs\RealtimeSessionConfig;
use LaravelAIEngine\Services\SDK\EvaluationService;
use LaravelAIEngine\Services\SDK\RealtimeSessionService;
use LaravelAIEngine\Services\SDK\TraceRecorderService;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Tools\Provider\WebSearch;

class RealtimeTraceEvaluationTest extends UnitTestCase
{
    public function test_realtime_service_builds_openai_and_gemini_session_descriptors(): void
    {
        $service = new RealtimeSessionService();

        $openai = $service->create(
            RealtimeSessionConfig::make('openai', 'gpt-4o-realtime-preview')
                ->withVoice('alloy')
                ->withTools([(new WebSearch())->toArray()])
        );

        $gemini = $service->create([
            'provider' => 'gemini',
            'model' => 'gemini-live-2.5-flash-preview',
            'modalities' => ['audio'],
        ]);

        $this->assertSame('/v1/realtime/sessions', $openai['endpoint']);
        $this->assertSame('alloy', $openai['payload']['voice']);
        $this->assertStringContainsString('BidiGenerateContent', $gemini['endpoint']);
        $this->assertSame(['audio'], $gemini['payload']['response_modalities']);
    }

    public function test_trace_recorder_and_evaluation_services_record_results(): void
    {
        $traces = new TraceRecorderService();
        $spanId = $traces->start('agent.run', ['agent' => 'support']);
        $span = $traces->end($spanId, metadata: ['tokens' => 100]);

        $this->assertSame('agent.run', $span['name']);
        $this->assertSame('ok', $span['status']);
        $this->assertSame(100, $span['metadata']['tokens']);
        $this->assertCount(1, $traces->all());

        $evaluations = new EvaluationService();
        $run = $evaluations->evaluate('contains citation', 'Answer [1]', null, fn (string $actual): bool => str_contains($actual, '['));

        $this->assertTrue($run['passed']);
        $this->assertCount(1, $evaluations->runs());
    }
}
