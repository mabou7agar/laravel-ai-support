<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Failover;

use LaravelAIEngine\Services\Failover\FailoverManager;
use LaravelAIEngine\Services\Failover\Strategies\PriorityStrategy;
use LaravelAIEngine\Services\Failover\Strategies\RoundRobinStrategy;
use LaravelAIEngine\Tests\UnitTestCase;

class FailoverManagerTest extends UnitTestCase
{
    private FailoverManager $failoverManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->failoverManager = new FailoverManager();
    }

    public function test_can_register_a_strategy(): void
    {
        $strategy = new PriorityStrategy();

        $this->failoverManager->registerStrategy('priority', $strategy);

        $strategies = $this->failoverManager->getAvailableStrategies();

        $this->assertArrayHasKey('priority', $strategies);
        $this->assertInstanceOf(PriorityStrategy::class, $strategies['priority']);
    }

    public function test_executes_successfully_with_first_working_provider(): void
    {
        $result = $this->failoverManager->executeWithFailover(
            static function (string $provider): string {
                if ($provider === 'openai') {
                    return 'success';
                }

                throw new \Exception('Provider failed');
            },
            ['openai', 'anthropic']
        );

        $this->assertSame('success', $result);
    }

    public function test_falls_back_to_next_provider_when_first_fails(): void
    {
        $result = $this->failoverManager->executeWithFailover(
            static function (string $provider): string {
                if ($provider === 'openai') {
                    throw new \Exception('OpenAI failed');
                }

                return 'anthropic_success';
            },
            ['openai', 'anthropic']
        );

        $this->assertSame('anthropic_success', $result);
    }

    public function test_throws_exception_when_all_providers_fail(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All providers failed after exhausting failover options');

        $this->failoverManager->executeWithFailover(
            static fn (): never => throw new \Exception('All providers failed'),
            ['openai', 'anthropic']
        );
    }

    public function test_returns_health_array_for_a_single_provider(): void
    {
        $health = $this->failoverManager->getProviderHealth('openai');

        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('last_check', $health);
        $this->assertArrayHasKey('failure_count', $health);
    }

    public function test_returns_health_for_all_known_providers_when_no_provider_specified(): void
    {
        $this->assertIsArray($this->failoverManager->getProviderHealth());
    }

    public function test_returns_full_system_health_report(): void
    {
        $health = $this->failoverManager->getSystemHealth();

        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('healthy_providers', $health);
        $this->assertArrayHasKey('total_providers', $health);
        $this->assertArrayHasKey('timestamp', $health);
    }

    public function test_marks_provider_as_healthy_after_successful_update(): void
    {
        $this->failoverManager->updateProviderHealth('openai', true);

        $this->assertSame('healthy', $this->failoverManager->getProviderHealth('openai')['status']);
    }

    public function test_marks_provider_as_unhealthy_after_failure_update(): void
    {
        $this->failoverManager->updateProviderHealth('openai', false);

        $health = $this->failoverManager->getProviderHealth('openai');

        $this->assertSame('unhealthy', $health['status']);
        $this->assertGreaterThan(0, $health['failure_count']);
    }

    public function test_resets_provider_health_back_to_healthy_with_zero_failures(): void
    {
        $this->failoverManager->updateProviderHealth('openai', false);
        $this->failoverManager->resetProviderHealth('openai');

        $health = $this->failoverManager->getProviderHealth('openai');

        $this->assertSame('healthy', $health['status']);
        $this->assertSame(0, $health['failure_count']);
    }

    public function test_returns_available_strategies_including_priority_and_round_robin(): void
    {
        $strategies = $this->failoverManager->getAvailableStrategies();

        $this->assertIsArray($strategies);
        $this->assertArrayHasKey('priority', $strategies);
        $this->assertArrayHasKey('round_robin', $strategies);
    }

    public function test_priority_strategy_orders_providers_by_descending_priority_value(): void
    {
        config()->set('ai-engine.failover.provider_priorities', [
            'openai' => 20,
            'anthropic' => 10,
            'gemini' => 30,
        ]);

        $ordered = (new PriorityStrategy())->getProviderOrder(['openai', 'anthropic', 'gemini'], []);

        $this->assertSame(['gemini', 'openai', 'anthropic'], $ordered);
    }

    public function test_round_robin_strategy_rotates_provider_order_on_each_call(): void
    {
        $strategy = new RoundRobinStrategy();
        $providers = ['openai', 'anthropic', 'gemini'];

        $this->assertNotSame(
            $strategy->getProviderOrder($providers, []),
            $strategy->getProviderOrder($providers, [])
        );
    }

    public function test_returns_failover_stats_with_expected_keys(): void
    {
        $stats = $this->failoverManager->getFailoverStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('failover_count', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        $this->assertArrayHasKey('provider_usage', $stats);
    }
}
