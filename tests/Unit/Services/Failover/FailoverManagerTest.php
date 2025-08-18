<?php

namespace LaravelAIEngine\Tests\Unit\Services\Failover;

use LaravelAIEngine\Services\Failover\FailoverManager;
use LaravelAIEngine\Services\Failover\CircuitBreaker;
use LaravelAIEngine\Services\Failover\Strategies\PriorityStrategy;
use LaravelAIEngine\Services\Failover\Strategies\RoundRobinStrategy;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FailoverManagerTest extends TestCase
{
    protected FailoverManager $failoverManager;
    protected $mockCircuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockCircuitBreaker = Mockery::mock(CircuitBreaker::class);
        $this->failoverManager = new FailoverManager();
    }

    public function test_can_register_strategy()
    {
        $strategy = new PriorityStrategy();
        
        $this->failoverManager->registerStrategy('priority', $strategy);
        
        $strategies = $this->failoverManager->getAvailableStrategies();
        $this->assertArrayHasKey('priority', $strategies);
        $this->assertInstanceOf(PriorityStrategy::class, $strategies['priority']);
    }

    public function test_can_execute_with_failover_success()
    {
        $providers = ['openai', 'anthropic'];
        $callback = function($provider) {
            if ($provider === 'openai') {
                return 'success';
            }
            throw new \Exception('Provider failed');
        };

        $result = $this->failoverManager->executeWithFailover($callback, $providers);
        
        $this->assertEquals('success', $result);
    }

    public function test_can_execute_with_failover_fallback()
    {
        $providers = ['openai', 'anthropic'];
        $callback = function($provider) {
            if ($provider === 'openai') {
                throw new \Exception('OpenAI failed');
            }
            return 'anthropic_success';
        };

        $result = $this->failoverManager->executeWithFailover($callback, $providers);
        
        $this->assertEquals('anthropic_success', $result);
    }

    public function test_throws_exception_when_all_providers_fail()
    {
        $providers = ['openai', 'anthropic'];
        $callback = function($provider) {
            throw new \Exception('All providers failed');
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All providers failed after exhausting failover options');

        $this->failoverManager->executeWithFailover($callback, $providers);
    }

    public function test_can_get_provider_health()
    {
        $health = $this->failoverManager->getProviderHealth('openai');
        
        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('last_check', $health);
        $this->assertArrayHasKey('failure_count', $health);
    }

    public function test_can_get_all_providers_health()
    {
        $health = $this->failoverManager->getProviderHealth();
        
        $this->assertIsArray($health);
        // Should return health for all known providers
    }

    public function test_can_get_system_health()
    {
        $health = $this->failoverManager->getSystemHealth();
        
        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('healthy_providers', $health);
        $this->assertArrayHasKey('total_providers', $health);
        $this->assertArrayHasKey('timestamp', $health);
    }

    public function test_can_update_provider_health()
    {
        $this->failoverManager->updateProviderHealth('openai', true);
        
        $health = $this->failoverManager->getProviderHealth('openai');
        $this->assertEquals('healthy', $health['status']);
    }

    public function test_can_update_provider_health_failure()
    {
        $this->failoverManager->updateProviderHealth('openai', false);
        
        $health = $this->failoverManager->getProviderHealth('openai');
        $this->assertEquals('unhealthy', $health['status']);
        $this->assertGreaterThan(0, $health['failure_count']);
    }

    public function test_can_reset_provider_health()
    {
        // First mark as unhealthy
        $this->failoverManager->updateProviderHealth('openai', false);
        
        // Then reset
        $this->failoverManager->resetProviderHealth('openai');
        
        $health = $this->failoverManager->getProviderHealth('openai');
        $this->assertEquals('healthy', $health['status']);
        $this->assertEquals(0, $health['failure_count']);
    }

    public function test_can_get_available_strategies()
    {
        $strategies = $this->failoverManager->getAvailableStrategies();
        
        $this->assertIsArray($strategies);
        $this->assertArrayHasKey('priority', $strategies);
        $this->assertArrayHasKey('round_robin', $strategies);
    }

    public function test_priority_strategy_orders_by_priority()
    {
        $providers = [
            'openai' => ['priority' => 2],
            'anthropic' => ['priority' => 1],
            'gemini' => ['priority' => 3]
        ];

        $strategy = new PriorityStrategy();
        $ordered = $strategy->orderProviders($providers, []);
        
        $this->assertEquals(['anthropic', 'openai', 'gemini'], $ordered);
    }

    public function test_round_robin_strategy_rotates_providers()
    {
        $providers = ['openai', 'anthropic', 'gemini'];
        $strategy = new RoundRobinStrategy();
        
        $first = $strategy->orderProviders($providers, []);
        $second = $strategy->orderProviders($providers, []);
        
        // Should rotate the order
        $this->assertNotEquals($first, $second);
    }

    public function test_can_get_failover_stats()
    {
        $stats = $this->failoverManager->getFailoverStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('failover_count', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        $this->assertArrayHasKey('provider_usage', $stats);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
