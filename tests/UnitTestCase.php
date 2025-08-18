<?php

namespace LaravelAIEngine\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use LaravelAIEngine\LaravelAIEngineServiceProvider;
use Illuminate\Support\Facades\Config;

abstract class UnitTestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConfig();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelAIEngineServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up cache for testing
        $app['config']->set('cache.default', 'array');
        
        // Set up queue for testing
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUpConfig(): void
    {
        Config::set('ai-engine.default', 'openai');
        Config::set('ai-engine.credits.enabled', true);
        Config::set('ai-engine.credits.default_balance', 100.0);
        Config::set('ai-engine.cache.enabled', true);
        Config::set('ai-engine.webhooks.enabled', false);
        
        // Set test API keys
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');
        Config::set('ai-engine.engines.anthropic.api_key', 'test-anthropic-key');
        Config::set('ai-engine.engines.gemini.api_key', 'test-gemini-key');
        Config::set('ai-engine.engines.stable_diffusion.api_key', 'test-stability-key');
        Config::set('ai-engine.engines.eleven_labs.api_key', 'test-elevenlabs-key');
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
