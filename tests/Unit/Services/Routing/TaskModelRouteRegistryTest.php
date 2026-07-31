<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Routing;

use LaravelAIEngine\Contracts\TaskModelRouteProvider;
use LaravelAIEngine\DTOs\TaskModelRoute;
use LaravelAIEngine\Services\Routing\ModelRouteReadinessService;
use LaravelAIEngine\Services\Routing\TaskModelRouteRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

final class TaskModelRouteRegistryTest extends UnitTestCase
{
    public function test_configured_and_host_provided_routes_are_resolved_without_implicit_fallbacks(): void
    {
        config()->set('ai-agent.assistant.model_routes.routes', [
            'chat' => ['engine' => 'openai', 'model' => 'gpt-4o'],
        ]);
        config()->set('ai-agent.assistant.model_routes.providers', [
            TestTaskModelRouteProvider::class,
        ]);

        $registry = app(TaskModelRouteRegistry::class);

        self::assertSame('gpt-4o', $registry->route('chat')?->model);
        self::assertNull($registry->route('chat')?->fallbackModel);
        self::assertSame('gpt-4o-mini', $registry->route('summary')?->model);
    }

    public function test_readiness_rejects_incomplete_unknown_and_identical_fallback_routes(): void
    {
        config()->set('ai-engine.engines.openai.models', [
            'gpt-4o' => ['enabled' => true],
        ]);
        config()->set('ai-agent.assistant.model_routes.routes', [
            'valid' => ['engine' => 'openai', 'model' => 'gpt-4o'],
            'incomplete' => ['engine' => 'openai', 'model' => 'gpt-4o', 'fallback_engine' => 'openai'],
            'unknown' => ['engine' => 'missing', 'model' => 'model'],
            'same' => [
                'engine' => 'openai',
                'model' => 'gpt-4o',
                'fallback_engine' => 'openai',
                'fallback_model' => 'gpt-4o',
            ],
        ]);

        $reports = app(ModelRouteReadinessService::class)->inspect();

        self::assertTrue($reports['valid']->ready());
        self::assertContains('fallback_route_incomplete', $reports['incomplete']->issues);
        self::assertContains('primary_engine_unknown', $reports['unknown']->issues);
        self::assertContains('fallback_matches_primary', $reports['same']->issues);
    }
}

final class TestTaskModelRouteProvider implements TaskModelRouteProvider
{
    public function routes(): iterable
    {
        yield new TaskModelRoute('summary', 'openai', 'gpt-4o-mini');
    }
}
