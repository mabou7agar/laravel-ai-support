<?php

namespace LaravelAIEngine\Tests\Unit\Services\DataCollector;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AutonomousCollectorServiceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        AutonomousCollectorRegistry::clear();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        AutonomousCollectorRegistry::clear();
        parent::tearDown();
    }

    public function test_process_restores_named_config_from_registry_after_service_recreation(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success('Acknowledged.', 'openai', 'gpt-4o'));

        $config = new AutonomousCollectorConfig(
            name: 'invoice_auto',
            goal: 'Create invoice',
            description: 'Collect invoice details',
            tools: [
                'find_customer' => [
                    'description' => 'Find customer',
                    'parameters' => ['query' => 'string|required'],
                    'handler' => fn (array $args): array => ['query' => $args['query'] ?? null],
                ],
            ],
            outputSchema: ['customer_id' => 'integer|required'],
        );

        $serviceA = new AutonomousCollectorService($ai);
        $start = $serviceA->start('ac-session-1', $config);

        $this->assertTrue($start->success);
        $this->assertSame('collecting', $start->status);

        // New service instance simulates a fresh request lifecycle.
        $serviceB = new AutonomousCollectorService($ai);
        $response = $serviceB->process('ac-session-1', 'Continue please');

        $this->assertTrue($response->success);
        $this->assertSame('collecting', $response->status);
        $this->assertSame('Acknowledged.', $response->message);
    }

    public function test_process_returns_explicit_error_when_tools_cannot_be_restored(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generate');

        $config = new AutonomousCollectorConfig(
            name: 'invoice_auto',
            goal: 'Create invoice',
            description: 'Collect invoice details',
            tools: [
                'find_customer' => [
                    'description' => 'Find customer',
                    'parameters' => ['query' => 'string|required'],
                    'handler' => fn (array $args): array => ['query' => $args['query'] ?? null],
                ],
            ],
            outputSchema: ['customer_id' => 'integer|required'],
        );

        $serviceA = new AutonomousCollectorService($ai);
        $serviceA->start('ac-session-2', $config);

        // Simulate app restart where static registry has not been rebuilt yet.
        AutonomousCollectorRegistry::clear();

        $serviceB = new AutonomousCollectorService($ai);
        $response = $serviceB->process('ac-session-2', 'Continue please');

        $this->assertFalse($response->success);
        $this->assertSame('error', $response->status);
        $this->assertSame('collector_config_unavailable', $response->error);
    }
}
