<?php

namespace LaravelAIEngine\Tests\Unit;

use LaravelAIEngine\AIEngineServiceProvider;
use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\Support\Config\AIEngineConfigDefaults;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Services\UnifiedEngineManager;
use LaravelAIEngine\Tests\UnitTestCase;
use ReflectionMethod;

class AIEngineServiceProviderConfigMergeTest extends UnitTestCase
{
    public function test_nested_config_merge_restores_new_engine_sections_for_older_configs(): void
    {
        config()->set('ai-engine', [
            'default' => 'openai',
            'engines' => [
                'openai' => [
                    'api_key' => 'legacy-openai-key',
                ],
            ],
        ]);

        $provider = new AIEngineServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'mergeNestedConfig');
        $method->setAccessible(true);
        $method->invoke($provider, 'ai-engine', AIEngineConfigDefaults::defaults());

        $this->assertSame('legacy-openai-key', config('ai-engine.engines.openai.api_key'));
        $this->assertSame('https://fal.run', config('ai-engine.engines.fal_ai.base_url'));
        $this->assertTrue(config('ai-engine.engines.fal_ai.models.fal-ai/nano-banana-2.enabled'));
    }

    public function test_driver_registry_resolves_fal_ai_driver(): void
    {
        config()->set('ai-engine.engines.fal_ai.api_key', 'test-fal-key');

        $driver = $this->app->make(DriverRegistry::class)->resolve('fal_ai');

        $this->assertInstanceOf(FalAIEngineDriver::class, $driver);
    }

    public function test_ai_engine_alias_resolves_unified_manager(): void
    {
        $this->assertInstanceOf(UnifiedEngineManager::class, $this->app->make('ai-engine'));
    }
}
