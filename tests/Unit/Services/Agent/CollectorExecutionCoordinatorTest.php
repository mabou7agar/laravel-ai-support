<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\CollectorExecutionCoordinator;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

class CollectorExecutionCoordinatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Facade::clearResolvedInstances();

        $this->resetRegistry();

        $app = new Container();
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->andReturnSelf();
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('warning')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $app->instance('log', $logger);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        $this->resetRegistry();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_failure_when_collector_name_missing(): void
    {
        $coordinator = new CollectorExecutionCoordinator(
            Mockery::mock(AutonomousCollectorDiscoveryService::class),
            Mockery::mock(AutonomousCollectorHandler::class)
        );

        $response = $coordinator->execute(
            null,
            'create invoice',
            new UnifiedActionContext('session-1', 1),
            [],
            fn () => AgentResponse::failure('unexpected')
        );

        $this->assertFalse($response->success);
        $this->assertSame('No collector specified.', $response->message);
    }

    public function test_routes_remote_collector_to_node_callback(): void
    {
        $discovery = Mockery::mock(AutonomousCollectorDiscoveryService::class);
        $handler = Mockery::mock(AutonomousCollectorHandler::class);

        $discovery->shouldReceive('discoverCollectors')
            ->once()
            ->with(true, true)
            ->andReturn([
                'invoice' => [
                    'source' => 'remote',
                    'node_slug' => 'billing-node',
                    'node_name' => 'Billing Node',
                ],
            ]);

        $coordinator = new CollectorExecutionCoordinator($discovery, $handler);
        $called = false;

        $response = $coordinator->execute(
            'invoice',
            'create invoice',
            new UnifiedActionContext('session-2', 1),
            [],
            function (array $decision, string $message) use (&$called): AgentResponse {
                $called = true;
                $this->assertSame('billing-node', $decision['resource_name']);
                $this->assertSame('create invoice', $message);
                return AgentResponse::success('routed');
            }
        );

        $this->assertTrue($called);
        $this->assertTrue($response->success);
        $this->assertSame('routed', $response->message);
    }

    public function test_starts_local_collector_via_handler(): void
    {
        $discovery = Mockery::mock(AutonomousCollectorDiscoveryService::class);
        $handler = Mockery::mock(AutonomousCollectorHandler::class);
        $context = new UnifiedActionContext('session-3', 1);

        $discovery->shouldReceive('discoverCollectors')
            ->once()
            ->with(true, true)
            ->andReturn([
                'invoice' => [
                    'source' => 'local',
                    'class' => TestCollectorConfig::class,
                    'description' => 'Create invoice',
                ],
            ]);

        $handler->shouldReceive('handle')
            ->once()
            ->with(
                'create invoice',
                $context,
                Mockery::on(function (array $payload): bool {
                    $match = $payload['collector_match'] ?? [];
                    return ($payload['action'] ?? null) === 'start_autonomous_collector'
                        && ($match['name'] ?? null) === 'invoice'
                        && ($match['config'] ?? null) instanceof TestCollectorConfig
                        && ($match['description'] ?? null) === 'Create invoice';
                })
            )
            ->andReturn(AgentResponse::success('started', context: $context));

        $coordinator = new CollectorExecutionCoordinator($discovery, $handler);
        $response = $coordinator->execute(
            'invoice',
            'create invoice',
            $context,
            [],
            fn () => AgentResponse::failure('unexpected')
        );

        $this->assertTrue($response->success);
        $this->assertSame('started', $response->message);
    }

    protected function resetRegistry(): void
    {
        $ref = new \ReflectionClass(AutonomousCollectorRegistry::class);
        $prop = $ref->getProperty('configs');
        $prop->setAccessible(true);
        $prop->setValue([]);
    }
}

class TestCollectorConfig
{
    public static function create(): self
    {
        return new self();
    }
}
