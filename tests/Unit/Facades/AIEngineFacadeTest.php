<?php

namespace LaravelAIEngine\Tests\Unit\Facades;

use LaravelAIEngine\Facades\AIEngine;
use LaravelAIEngine\Services\UnifiedEngineManager;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AIEngineFacadeTest extends TestCase
{
    public function test_ai_engine_facade_uses_unified_manager(): void
    {
        $manager = Mockery::mock(UnifiedEngineManager::class);
        $this->app->instance('unified-engine', $manager);

        $manager->shouldReceive('getAvailableModels')
            ->with('openai')
            ->once()
            ->andReturn(['gpt-4o']);

        $this->assertSame(['gpt-4o'], AIEngine::getAvailableModels('openai'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
