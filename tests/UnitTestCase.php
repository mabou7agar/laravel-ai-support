<?php

namespace LaravelAIEngine\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use LaravelAIEngine\AIEngineServiceProvider;
use Illuminate\Support\Facades\Config;

abstract class UnitTestCase extends Orchestra
{
    use LoadsParentEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConfig();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AIEngineServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up cache for testing
        $app['config']->set('cache.default', 'array');
        
        // Set up queue for testing
        $app['config']->set('queue.default', 'sync');

        // Disable nodes or provide JWT secret (must be set before provider boots)
        $app['config']->set('ai-engine.nodes.enabled', false);
        $app['config']->set('ai-engine.nodes.jwt.secret', 'test-jwt-secret-for-testing');
    }

    protected function setUpConfig(): void
    {
        Config::set('ai-engine.default', 'openai');
        Config::set('ai-engine.credits.enabled', true);
        Config::set('ai-engine.credits.default_balance', 100.0);
        Config::set('ai-engine.cache.enabled', true);
        Config::set('ai-engine.webhooks.enabled', false);
        Config::set('ai-engine.user_model', '\LaravelAIEngine\Tests\Models\User');
        Config::set('ai-engine.credits.owner_model', '\LaravelAIEngine\Tests\Models\User');
        
        // Disable nodes or provide JWT secret for testing
        Config::set('ai-engine.nodes.enabled', false);
        Config::set('ai-engine.nodes.jwt.secret', 'test-jwt-secret-for-testing');

        // Set placeholder API keys (overridden by parent .env if available)
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');
        Config::set('ai-engine.engines.anthropic.api_key', 'test-anthropic-key');
        Config::set('ai-engine.engines.gemini.api_key', 'test-gemini-key');
        Config::set('ai-engine.engines.stable_diffusion.api_key', 'test-stability-key');
        Config::set('ai-engine.engines.eleven_labs.api_key', 'test-elevenlabs-key');

        // Wire real API keys from parent project .env
        $this->wireParentEnvKeys();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
