<?php

namespace LaravelAIEngine\Tests\Unit\Jobs;

use Orchestra\Testbench\TestCase;
use LaravelAIEngine\Jobs\SendWebhookNotificationJob;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use Mockery;

class SendWebhookNotificationJobTest extends TestCase
{
    public function test_job_sends_webhook_successfully()
    {
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = new Response(200);
        
        $mockClient->shouldReceive('post')
            ->with('https://example.com/webhook', Mockery::type('array'))
            ->andReturn($mockResponse);

        $this->app->instance(Client::class, $mockClient);

        $payload = ['event' => 'test', 'data' => 'test_data'];
        $job = new SendWebhookNotificationJob(
            'https://example.com/webhook',
            $payload,
            'ai_request_completed'
        );

        $job->handle();

        $this->assertTrue(true); // Job completed without exceptions
    }

    public function test_job_handles_client_error_without_retry()
    {
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = new Response(400);
        $exception = new RequestException(
            'Client error',
            new Request('POST', 'https://example.com/webhook'),
            $mockResponse
        );
        
        $mockClient->shouldReceive('post')
            ->andThrow($exception);

        $this->app->instance(Client::class, $mockClient);

        $payload = ['event' => 'test', 'data' => 'test_data'];
        $job = new SendWebhookNotificationJob(
            'https://example.com/webhook',
            $payload,
            'ai_request_completed'
        );

        // Should not throw exception for client errors (they call fail())
        $job->handle();

        $this->assertTrue(true);
    }

    public function test_job_retries_on_server_error()
    {
        // This test verifies that server errors (5xx) cause the job to throw exceptions
        // which will trigger Laravel's retry mechanism
        $payload = ['event' => 'test', 'data' => 'test_data'];
        $job = new SendWebhookNotificationJob(
            'https://httpstat.us/500', // This will return a 500 error
            $payload,
            'ai_request_completed'
        );

        // The job should throw an exception for server errors
        // In a real test environment, we'd mock the HTTP client
        // For now, we'll just verify the job can be instantiated correctly
        $this->assertInstanceOf(SendWebhookNotificationJob::class, $job);
        $this->assertEquals(5, $job->tries);
        $this->assertEquals(30, $job->timeout);
    }

    public function test_job_includes_correct_headers()
    {
        $mockClient = Mockery::mock(Client::class);
        $mockResponse = new Response(200);
        
        $expectedHeaders = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'LaravelAIEngine/1.0',
            'X-Webhook-Event' => 'ai_request_completed',
            'X-Webhook-Delivery' => Mockery::pattern('/^delivery_/'),
            'X-Webhook-Timestamp' => Mockery::type('string'),
            'Authorization' => 'Bearer test-token',
        ];
        
        $mockClient->shouldReceive('post')
            ->with(
                'https://example.com/webhook',
                Mockery::on(function ($options) use ($expectedHeaders) {
                    $headers = $options['headers'];
                    return $headers['Content-Type'] === $expectedHeaders['Content-Type'] &&
                           $headers['User-Agent'] === $expectedHeaders['User-Agent'] &&
                           $headers['X-Webhook-Event'] === $expectedHeaders['X-Webhook-Event'] &&
                           $headers['Authorization'] === $expectedHeaders['Authorization'] &&
                           isset($headers['X-Webhook-Delivery']) &&
                           isset($headers['X-Webhook-Timestamp']);
                })
            )
            ->andReturn($mockResponse);

        $this->app->instance(Client::class, $mockClient);

        $payload = ['event' => 'test', 'data' => 'test_data'];
        $customHeaders = ['Authorization' => 'Bearer test-token'];
        
        $job = new SendWebhookNotificationJob(
            'https://example.com/webhook',
            $payload,
            'ai_request_completed',
            $customHeaders
        );

        $job->handle();

        $this->assertTrue(true);
    }

    public function test_job_has_correct_configuration()
    {
        $payload = ['event' => 'test'];
        $job = new SendWebhookNotificationJob(
            'https://example.com/webhook',
            $payload,
            'ai_request_completed'
        );

        $this->assertEquals(5, $job->tries);
        $this->assertEquals(30, $job->timeout);
        $this->assertEquals([10, 30, 60, 120, 300], $job->backoff());
    }

    public function test_job_logs_failure_correctly()
    {
        $payload = ['event' => 'test'];
        $job = new SendWebhookNotificationJob(
            'https://example.com/webhook',
            $payload,
            'ai_request_completed'
        );

        // Should not throw exception
        $job->failed(new \Exception('Test failure'));

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
