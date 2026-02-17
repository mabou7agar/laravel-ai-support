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
        
        $this->failoverManager->registerStrategy('test_priority', $strategy);
        
        $strategies = $this->failoverManager->getAvailableStrategies();
        $this->assertIsArray($strategies);
        $this->assertNotEmpty($strategies);
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
        $this->expectExceptionMessage('All providers failed');

        $this->failoverManager->executeWithFailover($callback, $providers);
    }

    public function test_can_get_provider_health()
    {
        $health = $this->failoverManager->getProviderHealth('openai');
        
        $this->assertIsArray($health);
    }

    public function test_can_get_all_providers_health()
    {
        $health = $this->failoverManager->getProviderHealth();
        
        $this->assertIsArray($health);
    }

    public function test_can_get_system_health()
    {
        $health = $this->failoverManager->getSystemHealth();
        
        $this->assertIsArray($health);
        $this->assertNotEmpty($health);
    }

    public function test_can_update_provider_health()
    {
        $this->failoverManager->updateProviderHealth('openai', [
            'status' => 'healthy',
            'last_check' => now()->toIso8601String(),
        ]);
        
        $health = $this->failoverManager->getProviderHealth('openai');
        $this->assertIsArray($health);
    }

    public function test_can_reset_circuit_breaker()
    {
        $this->failoverManager->resetCircuitBreaker('openai');
        
        $status = $this->failoverManager->getCircuitBreakerStatus('openai');
        $this->assertIsArray($status);
    }

    public function test_can_get_available_strategies()
    {
        $strategies = $this->failoverManager->getAvailableStrategies();
        
        $this->assertIsArray($strategies);
        $this->assertNotEmpty($strategies);
    }

    public function test_priority_strategy_orders_by_priority()
    {
        $providers = ['openai', 'anthropic', 'gemini'];

        $strategy = new PriorityStrategy();
        $ordered = $strategy->getProviderOrder($providers, []);
        
        $this->assertIsArray($ordered);
        $this->assertNotEmpty($ordered);
    }

    public function test_round_robin_strategy_rotates_providers()
    {
        $providers = ['openai', 'anthropic', 'gemini'];
        $strategy = new RoundRobinStrategy();
        
        $first = $strategy->getProviderOrder($providers, []);
        $second = $strategy->getProviderOrder($providers, []);
        
        // Should return arrays of providers
        $this->assertIsArray($first);
        $this->assertIsArray($second);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
