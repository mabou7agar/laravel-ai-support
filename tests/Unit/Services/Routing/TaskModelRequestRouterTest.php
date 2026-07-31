<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Routing;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\Routing\TaskModelRequestRouter;
use LaravelAIEngine\Tests\UnitTestCase;

final class TaskModelRequestRouterTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-agent.assistant.model_routes.routes', [
            'orchestration' => [
                'engine' => 'openai',
                'model' => 'gpt-4o',
                'fallback_engine' => 'openai',
                'fallback_model' => 'gpt-4o-mini',
                'parameters' => ['temperature' => 0.2, 'timeout' => 20],
            ],
        ]);
    }

    public function test_it_applies_a_task_route_to_implicit_request_defaults(): void
    {
        $request = new AIRequest(
            prompt: 'Plan this',
            parameters: ['timeout' => 40],
            metadata: ['task' => 'orchestration'],
        );

        $routed = app(TaskModelRequestRouter::class)->apply($request);

        self::assertSame('openai', $routed->getEngine()->value);
        self::assertSame('gpt-4o', $routed->getModel()->value);
        self::assertEquals(['temperature' => 0.2, 'timeout' => 40], $routed->getParameters());
        self::assertSame('primary', $routed->getMetadata()['task_model_route']['selected']);
    }

    public function test_it_preserves_explicit_request_selection_by_default(): void
    {
        $request = new AIRequest(
            prompt: 'Plan this',
            engine: 'gemini',
            model: 'gemini-2.5-flash',
            metadata: ['task' => 'orchestration'],
        );

        $routed = app(TaskModelRequestRouter::class)->apply($request);

        self::assertSame($request, $routed);
    }

    public function test_it_applies_only_the_declared_fallback(): void
    {
        $request = new AIRequest(prompt: 'Plan this', metadata: ['task' => 'orchestration']);

        $routed = app(TaskModelRequestRouter::class)->fallback($request);

        self::assertSame('gpt-4o-mini', $routed->getModel()->value);
        self::assertSame('fallback', $routed->getMetadata()['task_model_route']['selected']);
    }
}
