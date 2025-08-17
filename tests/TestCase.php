<?php

namespace LaravelAIEngine\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use LaravelAIEngine\LaravelAIEngineServiceProvider;
use LaravelAIEngine\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConfig();
        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelAIEngineServiceProvider::class,
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
        Config::set('ai-engine.user_model', User::class);
        
        // Set test API keys
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');
        Config::set('ai-engine.engines.anthropic.api_key', 'test-anthropic-key');
        Config::set('ai-engine.engines.gemini.api_key', 'test-gemini-key');
        Config::set('ai-engine.engines.stable_diffusion.api_key', 'test-stability-key');
        Config::set('ai-engine.engines.eleven_labs.api_key', 'test-elevenlabs-key');
    }

    protected function setUpDatabase(): void
    {
        // Create test tables if needed
        $this->loadLaravelMigrations();
        
        // Add entity_credits column to users table for testing
        if (!$this->app['db']->getSchemaBuilder()->hasColumn('users', 'entity_credits')) {
            $this->app['db']->getSchemaBuilder()->table('users', function ($table) {
                $table->json('entity_credits')->nullable();
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
        
        $user->fill($userData);
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
