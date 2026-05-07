<?php

namespace LaravelAIEngine\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use LaravelAIEngine\AIEngineServiceProvider;
use LaravelAIEngine\Tests\Models\User;
use Illuminate\Support\Facades\Config;

abstract class UnitTestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\App\Models\User::class)) {
            class_alias(User::class, \App\Models\User::class);
        }

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
        $app['config']->set('ai-engine.nodes.enabled', false);
        $app['config']->set('ai-engine.nodes.jwt.secret', 'test-jwt-secret');
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUpConfig(): void
    {
        Config::set('ai-engine.default', 'openai');
        Config::set('ai-engine.credits.enabled', true);
        Config::set('ai-engine.credits.default_balance', 100.0);
        Config::set('ai-engine.cache.enabled', true);
        Config::set('ai-engine.webhooks.enabled', false);
        Config::set('ai-engine.nodes.enabled', false);
        Config::set('ai-engine.nodes.jwt.secret', 'test-jwt-secret');
        Config::set('ai-engine.infrastructure.startup_health_gate.enabled', false);
        Config::set('ai-engine.infrastructure.qdrant_self_check.enabled', false);
        Config::set('ai-engine.user_model', User::class);
        Config::set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        Config::set('ai-engine.graph.neo4j.database', 'neo4j');
        Config::set('ai-engine.graph.neo4j.username', 'neo4j');
        Config::set('ai-engine.graph.neo4j.password', 'test-secret');
        Config::set('auth.providers.users.model', User::class);
        
        // Set test API keys
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');
        Config::set('ai-engine.engines.anthropic.api_key', 'test-anthropic-key');
        Config::set('ai-engine.engines.gemini.api_key', 'test-gemini-key');
        Config::set('ai-engine.engines.stable_diffusion.api_key', 'test-stability-key');
        Config::set('ai-engine.engines.eleven_labs.api_key', 'test-elevenlabs-key');
        Config::set('ai-engine.engines.nvidia_nim.api_key', 'test-nvidia-nim-key');
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
