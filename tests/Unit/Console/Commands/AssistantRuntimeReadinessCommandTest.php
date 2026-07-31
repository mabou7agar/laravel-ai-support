<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Tests\UnitTestCase;

final class AssistantRuntimeReadinessCommandTest extends UnitTestCase
{
    public function test_it_reports_valid_model_routes_as_json(): void
    {
        config()->set('ai-agent.assistant.model_routes.routes', [
            'orchestration' => [
                'engine' => 'openai',
                'model' => 'gpt-4o',
                'fallback_engine' => 'openai',
                'fallback_model' => 'gpt-4o-mini',
            ],
        ]);

        self::assertSame(0, Artisan::call('ai:assistant-readiness', ['--json' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('"ready": true', $output);
        self::assertStringContainsString('"task": "orchestration"', $output);
        self::assertStringContainsString('"knowledge_index"', $output);
        self::assertStringContainsString('InMemoryScopedKnowledgeIndex', $output);
    }

    public function test_it_fails_for_an_unregistered_route_model(): void
    {
        config()->set('ai-agent.assistant.model_routes.routes', [
            'orchestration' => [
                'engine' => 'openai',
                'model' => 'missing-model',
            ],
        ]);

        self::assertSame(1, Artisan::call('ai:assistant-readiness', ['--json' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('"ready": false', $output);
        self::assertStringContainsString('"primary_model_unregistered"', $output);
    }
}
