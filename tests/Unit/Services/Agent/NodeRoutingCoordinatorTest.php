<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Agent\NodeRoutingCoordinator;
use LaravelAIEngine\Services\Node\NodeForwarder;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use Mockery;
use PHPUnit\Framework\TestCase;

class NodeRoutingCoordinatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Facade::clearResolvedInstances();

        $app = new Container();
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->andReturnSelf();
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('warning')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $app->instance('log', $logger);
        $app->instance('config', new Repository([
            'app' => [
                'name' => 'test-app',
            ],
        ]));
        $app->instance('request', Request::create('/chat', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-token',
            'HTTP_X_REQUEST_ID' => 'req-123',
        ]));

        Container::setInstance($app);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_route_decision_falls_back_when_resource_missing(): void
    {
        $coordinator = new NodeRoutingCoordinator(
            Mockery::mock(NodeRegistryService::class),
            Mockery::mock(NodeForwarder::class)
        );
        $context = new UnifiedActionContext('session-1', 1);
        $fallbackCalled = false;

        $response = $coordinator->routeDecision(
            ['action' => 'route_to_node'],
            'list invoices',
            $context,
            [],
            function () use (&$fallbackCalled, $context): AgentResponse {
                $fallbackCalled = true;
                return AgentResponse::success('fallback response', context: $context);
            }
        );

        $this->assertTrue($fallbackCalled);
        $this->assertTrue($response->success);
        $this->assertSame('fallback response', $response->message);
    }

    public function test_route_decision_returns_failure_when_node_unresolved(): void
    {
        $registry = Mockery::mock(NodeRegistryService::class);
        $router = Mockery::mock(NodeForwarder::class);
        $context = new UnifiedActionContext('session-2', 1);

        $registry->shouldReceive('getNode')
            ->once()
            ->with('unknown-node')
            ->andReturn(null);
        $registry->shouldReceive('getAllNodes')
            ->once()
            ->andReturn(collect());
        $registry->shouldReceive('findNodeForCollection')
            ->once()
            ->with('unknown-node')
            ->andReturn(null);

        $coordinator = new NodeRoutingCoordinator($registry, $router);

        $response = $coordinator->routeDecision(
            ['resource_name' => 'unknown-node'],
            'list unknown data',
            $context,
            [],
            fn () => AgentResponse::failure('unexpected', context: $context)
        );

        $this->assertFalse($response->success);
        $this->assertSame("I couldn't find a remote node matching 'unknown-node'.", $response->message);
    }

    public function test_route_decision_forwards_and_sets_routed_context_on_success(): void
    {
        $registry = Mockery::mock(NodeRegistryService::class);
        $router = Mockery::mock(NodeForwarder::class);
        $context = new UnifiedActionContext('session-3', 42);
        $node = new AINode([
            'slug' => 'billing-node',
            'name' => 'Billing Node',
            'url' => 'https://billing.example.com',
        ]);

        $registry->shouldReceive('getNode')
            ->once()
            ->with('billing-node')
            ->andReturn($node);

        $router->shouldReceive('forwardChat')
            ->once()
            ->with(
                $node,
                'list invoices',
                'session-3',
                Mockery::on(function (array $forwardOptions): bool {
                    return ($forwardOptions['headers']['X-Forwarded-From-Node'] ?? null) === 'test-app'
                        && ($forwardOptions['headers']['X-User-Token'] ?? null) === 'test-token'
                        && ($forwardOptions['user_token'] ?? null) === 'test-token';
                }),
                42
            )
            ->andReturn([
                'success' => true,
                'response' => 'remote invoices',
                'metadata' => ['source' => 'node'],
            ]);

        $coordinator = new NodeRoutingCoordinator($registry, $router);
        $response = $coordinator->routeDecision(
            ['resource_name' => 'billing-node'],
            'list invoices',
            $context,
            [],
            fn () => AgentResponse::failure('unexpected', context: $context)
        );

        $this->assertTrue($response->success);
        $this->assertSame('remote invoices', $response->message);
        $this->assertSame('billing-node', $context->get('routed_to_node')['node_slug']);
    }

    public function test_forward_as_agent_response_returns_failure_message_from_router_error(): void
    {
        $registry = Mockery::mock(NodeRegistryService::class);
        $router = Mockery::mock(NodeForwarder::class);
        $context = new UnifiedActionContext('session-4', 7);
        $node = new AINode([
            'slug' => 'billing-node',
            'name' => 'Billing Node',
            'url' => 'https://billing.example.com',
        ]);

        $router->shouldReceive('forwardChat')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Gateway timeout',
            ]);

        $coordinator = new NodeRoutingCoordinator($registry, $router);
        $response = $coordinator->forwardAsAgentResponse($node, 'show invoice 1', $context, []);

        $this->assertFalse($response->success);
        $this->assertStringContainsString("I couldn't reach remote node 'billing-node'", $response->message);
        $this->assertFalse($context->has('routed_to_node'));
    }
}
