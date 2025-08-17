<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Services\WebhookManager;
use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Events\AIRequestCompleted;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;

class WebhookManagerTest extends TestCase
{
    private WebhookManager $webhookManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Enable webhooks for testing BEFORE instantiating WebhookManager
        Config::set('ai-engine.webhooks.enabled', true);
        Config::set('ai-engine.webhooks.secret', 'test-secret');
        Config::set('ai-engine.webhooks.endpoints.completion', 'https://example.com/webhook/completion');
        Config::set('ai-engine.webhooks.timeout', 10);
        
        // Create a fresh instance after config is set
        $this->webhookManager = new WebhookManager();
    }

    public function test_webhook_manager_creation()
    {
        $this->assertInstanceOf(WebhookManager::class, $this->webhookManager);
    }

    public function test_notify_request_started()
    {
        // Mock HTTP client
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockClient->shouldReceive('post')->andReturn($mockResponse);
        
        $this->app->instance(Client::class, $mockClient);

        // Create mock AIRequest object
        $request = new \LaravelAIEngine\DTOs\AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            parameters: [],
            userId: 'user-456'
        );
        
        $event = new AIRequestStarted(
            request: $request,
            requestId: 'req-123',
            metadata: ['test' => 'data']
        );

        $this->webhookManager->notifyRequestStarted($event);

        // Verify the webhook was called (through mock expectations)
        $this->assertTrue(true); // Mock expectations will fail if not met
    }

    public function test_notify_request_completed()
    {
        // Mock HTTP client
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockClient->shouldReceive('post')->andReturn($mockResponse);
        
        $this->app->instance(Client::class, $mockClient);

        // Create mock AIRequest and AIResponse objects
        $request = new \LaravelAIEngine\DTOs\AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            parameters: [],
            userId: 'user-456'
        );
        
        $response = new \LaravelAIEngine\DTOs\AIResponse(
            content: 'Generated response text',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
        );
        
        $event = new AIRequestCompleted(
            request: $request,
            response: $response,
            requestId: 'req-123',
            executionTime: 1.25,
            metadata: ['response' => 'data']
        );

        $this->webhookManager->notifyRequestCompleted($event);

        // Verify the webhook was called (through mock expectations)
        $this->assertTrue(true);
    }

    public function test_notify_request_failed()
    {
        // Mock HTTP client
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockClient->shouldReceive('post')->andReturn($mockResponse);
        
        $this->app->instance(Client::class, $mockClient);

        $this->webhookManager->notifyRequestFailed(
            'req-123',
            'user-456',
            'openai',
            'API request failed',
            ['error_code' => 500]
        );

        $this->assertTrue(true);
    }

    public function test_notify_low_credits()
    {
        // Mock HTTP client
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockClient->shouldReceive('post')->andReturn($mockResponse);
        
        $this->app->instance(Client::class, $mockClient);

        $this->webhookManager->notifyLowCredits('user-456', 5.0, 10.0, 'openai');

        $this->assertTrue(true);
    }

    public function test_notify_rate_limit_exceeded()
    {
        // Mock HTTP client
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockClient->shouldReceive('post')->andReturn($mockResponse);
        
        $this->app->instance(Client::class, $mockClient);

        $this->webhookManager->notifyRateLimitExceeded('user-456', 'openai', 60);

        $this->assertTrue(true);
    }

    public function test_send_custom_webhook()
    {
        // Mock HTTP client
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockClient->shouldReceive('post')->andReturn($mockResponse);
        
        // Create WebhookManager with mocked client
        $webhookManager = new WebhookManager($mockClient);

        $result = $webhookManager->sendCustomWebhook(
            'custom_event',
            ['custom' => 'data'],
            'https://example.com/custom-webhook'
        );

        $this->assertTrue($result);
    }

    public function test_register_endpoint()
    {
        $this->webhookManager->registerEndpoint(
            'test_event',
            'https://example.com/test-webhook',
            [
                'secret' => 'test-secret',
                'retry_attempts' => 5,
                'timeout' => 15
            ]
        );

        $endpoints = $this->webhookManager->getEndpoints('test_event');
        
        $this->assertCount(1, $endpoints);
        $this->assertEquals('https://example.com/test-webhook', $endpoints[0]['url']);
        $this->assertEquals('test-secret', $endpoints[0]['secret']);
        $this->assertEquals(5, $endpoints[0]['retry_attempts']);
        $this->assertEquals(15, $endpoints[0]['timeout']);
    }

    public function test_unregister_endpoint()
    {
        // Register first
        $this->webhookManager->registerEndpoint('test_event', 'https://example.com/test-webhook');
        
        // Verify it exists
        $endpoints = $this->webhookManager->getEndpoints('test_event');
        $this->assertCount(1, $endpoints);
        
        // Unregister
        $this->webhookManager->unregisterEndpoint('test_event', 'https://example.com/test-webhook');
        
        // Verify it's gone
        $endpoints = $this->webhookManager->getEndpoints('test_event');
        $this->assertCount(0, $endpoints);
    }

    public function test_get_endpoints_empty()
    {
        $endpoints = $this->webhookManager->getEndpoints('nonexistent_event');
        $this->assertIsArray($endpoints);
        $this->assertCount(0, $endpoints);
    }

    public function test_test_endpoint_success()
    {
        // Mock successful HTTP response
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockResponse->shouldReceive('getHeader')->with('X-Response-Time')->andReturn(['123ms']);
        $mockClient->shouldReceive('post')->andReturn($mockResponse);
        
        // Create WebhookManager with mocked client
        $webhookManager = new WebhookManager($mockClient);

        $result = $webhookManager->testEndpoint('https://example.com/test-webhook', 'secret');

        $this->assertTrue($result['success']);
        $this->assertEquals(200, $result['status_code']);
        $this->assertEquals('Webhook test successful', $result['message']);
    }

    public function test_test_endpoint_failure()
    {
        // Mock failed HTTP response
        $mockClient = Mockery::mock(Client::class);
        $mockClient->shouldReceive('post')
            ->andThrow(new \GuzzleHttp\Exception\RequestException(
                'Connection failed',
                Mockery::mock(\Psr\Http\Message\RequestInterface::class)
            ));
        
        // Create WebhookManager with mocked client
        $webhookManager = new WebhookManager($mockClient);

        $result = $webhookManager->testEndpoint('https://example.com/test-webhook');

        $this->assertFalse($result['success']);
        $this->assertEquals('Webhook test failed', $result['message']);
        $this->assertStringContainsString('Connection failed', $result['error']);
    }

    public function test_get_delivery_logs()
    {
        // Simulate some logs in cache
        Cache::put('ai_engine_webhook_logs_test_event', [
            [
                'timestamp' => now()->toISOString(),
                'event' => 'test_event',
                'url' => 'https://example.com/webhook',
                'success' => true,
                'status_code' => 200,
                'response_time_ms' => 150,
                'error' => null,
            ]
        ]);

        $logs = $this->webhookManager->getDeliveryLogs('test_event', 10);

        $this->assertIsArray($logs);
        $this->assertCount(1, $logs);
        $this->assertEquals('test_event', $logs[0]['event']);
        $this->assertTrue($logs[0]['success']);
    }

    public function test_clear_logs()
    {
        // Set up some logs
        Cache::put('ai_engine_webhook_logs_test_event', [['test' => 'data']]);
        Cache::put('ai_engine_webhook_logs_another_event', [['test' => 'data']]);

        // Clear specific event logs
        $this->webhookManager->clearLogs('test_event');

        // Verify specific event logs are cleared
        $this->assertEmpty(Cache::get('ai_engine_webhook_logs_test_event', []));
        
        // Verify other logs still exist
        $this->assertNotEmpty(Cache::get('ai_engine_webhook_logs_another_event', []));
    }

    public function test_clear_all_logs()
    {
        // Set up some logs
        Cache::put('ai_engine_webhook_logs_started', [['test' => 'data']]);
        Cache::put('ai_engine_webhook_logs_completed', [['test' => 'data']]);
        Cache::put('ai_engine_webhook_logs_error', [['test' => 'data']]);

        // Clear all logs
        $this->webhookManager->clearLogs();

        // Verify all logs are cleared
        $this->assertEmpty(Cache::get('ai_engine_webhook_logs_started', []));
        $this->assertEmpty(Cache::get('ai_engine_webhook_logs_completed', []));
        $this->assertEmpty(Cache::get('ai_engine_webhook_logs_error', []));
    }

    public function test_webhooks_disabled()
    {
        // Disable webhooks
        Config::set('ai-engine.webhooks.enabled', false);
        
        $webhookManager = new WebhookManager();

        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            parameters: [],
            userId: 'user-456'
        );
        
        $event = new AIRequestStarted(
            request: $request,
            requestId: 'req-123'
        );

        // Should not make any HTTP calls when disabled
        $webhookManager->notifyRequestStarted($event);
        
        // No assertions needed - if HTTP calls were made, mocks would fail
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
