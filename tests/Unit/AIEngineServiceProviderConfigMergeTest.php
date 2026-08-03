<?php

namespace LaravelAIEngine\Tests\Unit;

use Illuminate\Support\ServiceProvider;
use LaravelAIEngine\AIEngineServiceProvider;
use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\Events\AIRequestCompleted;
use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Support\Config\AIEngineConfigDefaults;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Services\UnifiedEngineManager;
use LaravelAIEngine\Tests\UnitTestCase;
use ReflectionMethod;

class AIEngineServiceProviderConfigMergeTest extends UnitTestCase
{
    public function test_nested_config_merge_preserves_published_engine_values(): void
    {
        config()->set('ai-engine', [
            'default' => 'openai',
            'engines' => [
                'openai' => [
                    'api_key' => 'custom-openai-key',
                ],
            ],
        ]);

        $provider = new AIEngineServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'mergeNestedConfig');
        $method->setAccessible(true);
        $method->invoke($provider, 'ai-engine', AIEngineConfigDefaults::defaults());

        $this->assertSame('custom-openai-key', config('ai-engine.engines.openai.api_key'));
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

    public function test_request_cost_logging_listeners_are_registered(): void
    {
        $events = $this->app->make('events');

        $this->assertNotEmpty($events->getListeners(AIRequestStarted::class));
        $this->assertNotEmpty($events->getListeners(AIRequestCompleted::class));
    }

    public function test_assistant_client_publish_tag_includes_chat_and_voice_modules(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            AIEngineServiceProvider::class,
            'ai-engine-assistant-client'
        );
        $sources = array_map(
            static fn (string $path): string|false => realpath($path),
            array_keys($paths)
        );

        $this->assertContains(
            realpath(__DIR__ . '/../../resources/assets/assistant-client.js'),
            $sources
        );
        $this->assertContains(
            realpath(__DIR__ . '/../../resources/assets/assistant-voice-client.js'),
            $sources
        );
    }

    public function test_missing_optional_component_directory_is_not_registered(): void
    {
        $componentPath = realpath(__DIR__ . '/../../src')
            . '/../resources/views/components';
        $registeredPaths = array_column(
            $this->app->make('blade.compiler')->getAnonymousComponentPaths(),
            'path'
        );

        $this->assertDirectoryDoesNotExist($componentPath);
        $this->assertNotContains($componentPath, $registeredPaths);
    }
}
