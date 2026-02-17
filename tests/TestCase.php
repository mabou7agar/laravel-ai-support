<?php

namespace LaravelAIEngine\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use LaravelAIEngine\AIEngineServiceProvider;
use LaravelAIEngine\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase, LoadsParentEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConfig();
        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AIEngineServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up test database
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set app key for encryption
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

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
        Config::set('ai-engine.user_model', User::class);
        Config::set('ai-engine.credits.owner_model', User::class);
        
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

    protected function setUpDatabase(): void
    {
        // Create test tables if needed
        $this->loadLaravelMigrations();
        
        // Add columns needed for CreditManager testing
        $schema = $this->app['db']->getSchemaBuilder();
        if (!$schema->hasColumn('users', 'entity_credits')) {
            $schema->table('users', function ($table) {
                $table->json('entity_credits')->nullable();
            });
        }
        if (!$schema->hasColumn('users', 'my_credits')) {
            $schema->table('users', function ($table) {
                $table->float('my_credits')->default(0);
            });
        }
        if (!$schema->hasColumn('users', 'has_unlimited_credits')) {
            $schema->table('users', function ($table) {
                $table->boolean('has_unlimited_credits')->default(false);
            });
        }
    }

    protected function createTestUser(array $attributes = []): User
    {
        $user = new User();
        $userData = array_merge([
            'name' => 'Test User',
            'email' => 'test' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'entity_credits' => json_encode([
                'openai' => [
                    'gpt-4o' => ['balance' => 100.0, 'is_unlimited' => false],
                    'dall-e-3' => ['balance' => 50.0, 'is_unlimited' => false],
                ],
                'anthropic' => [
                    'claude-3-5-sonnet-20240620' => ['balance' => 75.0, 'is_unlimited' => false],
                ],
            ]),
        ], $attributes);
        
        $user->forceFill($userData);
        $user->save();
        return $user;
    }

    protected function mockHttpClient(array $responses = []): void
    {
        $mock = \Mockery::mock(\GuzzleHttp\Client::class);
        
        foreach ($responses as $response) {
            $mockResponse = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
            $mockResponse->shouldReceive('getBody->getContents')
                ->andReturn(json_encode($response));
            $mockResponse->shouldReceive('getStatusCode')
                ->andReturn(200);
                
            $mock->shouldReceive('post')
                ->withAnyArgs()
                ->andReturn($mockResponse);
            
            $mock->shouldReceive('request')
                ->withAnyArgs()
                ->andReturn($mockResponse);
        }
        
        $this->app->instance(\GuzzleHttp\Client::class, $mock);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
