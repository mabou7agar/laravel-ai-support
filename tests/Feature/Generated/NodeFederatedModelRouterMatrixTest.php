<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Generated;

use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeRouterService;
use LaravelAIEngine\Tests\Concerns\RequiresFederation;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * Exercises NodeFederatedModelRouter::routeForModel across the ownership / health
 * matrix using real AINode rows in the (in-memory) DB.
 *
 * routeForModel resolves a model name -> owning node via the real
 * NodeOwnershipResolver / NodeRegistryService stack, then forwards the chat through
 * NodeRouterService. We seed real AINode rows for each case and mock only the final
 * NodeRouterService::forwardChat hop so no real network happens. Every assertion
 * checks the documented contract: routeForModel returns a failure ARRAY (never null)
 * when it cannot route, and a success array naming the owning node when it can.
 *
 * The suite runs in the "production" environment so the active()/healthy() node
 * scopes and AINode::isHealthy() actually gate (they are no-ops elsewhere).
 */
class NodeFederatedModelRouterMatrixTest extends TestCase
{
    use RequiresFederation;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai-engine.nodes.enabled', true);
        $app['config']->set('ai-engine.nodes.is_master', true);
        $app['config']->set('ai-engine.nodes.jwt.secret', 'test-jwt-secret');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(
            dirname(__DIR__, 4) . '/laravel-ai-engine-federation/database/migrations'
        );

        // The node-aware RAG bindings are only registered by NodeServiceRegistrar when
        // nodes are enabled at provider-register time; the base test harness disables
        // nodes, so wire the contract -> concrete router (and its real collaborators)
        // explicitly here. These are the exact bindings the registrar would install.
        $this->app->singleton(
            \LaravelAIEngine\Services\Node\NodeRegistryService::class,
            fn ($app) => new \LaravelAIEngine\Services\Node\NodeRegistryService(
                $app->make(\LaravelAIEngine\Services\Node\CircuitBreakerService::class),
                $app->make(\LaravelAIEngine\Services\Node\NodeAuthService::class)
            )
        );
        $this->app->singleton(
            \LaravelAIEngine\Services\Node\NodeOwnershipResolver::class,
            fn ($app) => new \LaravelAIEngine\Services\Node\NodeOwnershipResolver(
                $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class)
            )
        );
        $this->app->singleton(
            \LaravelAIEngine\Contracts\RAG\FederatedModelRouter::class,
            fn ($app) => new \LaravelAIEngine\Services\Node\NodeFederatedModelRouter(
                $app->make(\LaravelAIEngine\Services\RAG\RAGModelMetadataService::class)
            )
        );

        // Flip to "production" AFTER the DB/migrations are set up (the base TestCase
        // builds the users table under the testing env). AINode health scopes and
        // isHealthy() only gate outside local/non-production, so this makes the
        // ownership+health matrix meaningful without disturbing schema bootstrapping.
        $this->app['env'] = 'production';
    }

    protected function tearDown(): void
    {
        // Restore the testing env before RefreshDatabase rolls back: migration rollback
        // prompts for confirmation under the production env and would hang/fail.
        if (isset($this->app)) {
            $this->app['env'] = 'testing';
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function seedNode(array $overrides = []): AINode
    {
        return AINode::query()->create(array_merge([
            'name' => 'Node ' . uniqid(),
            'slug' => 'node-' . uniqid(),
            'type' => 'child',
            'url' => 'https://node.example.test',
            'status' => 'active',
            'capabilities' => ['search'],
            'collections' => [],
            'weight' => 1,
            'ping_failures' => 0,
            'last_ping_at' => now(),
        ], $overrides));
    }

    /**
     * @return array{0: array, 1: AINode}  [routeForModel result, owning node]
     */
    protected function routeFor(string $modelName): array
    {
        $router = $this->app->make(\LaravelAIEngine\Contracts\RAG\FederatedModelRouter::class);

        $result = $router->routeForModel(
            ['model' => $modelName],
            "Tell me about {$modelName}",
            'sess-' . uniqid(),
            42,
            [],
            []
        );

        return [$result, $router];
    }

    public function test_active_node_owning_model_routes_to_that_node(): void
    {
        $node = $this->seedNode([
            'slug' => 'orders-node',
            'status' => 'active',
            'last_ping_at' => now(),
            'ping_failures' => 0,
            'collections' => ['Order'],
        ]);

        // Mock the final forwarding hop so no real network occurs.
        $forwarder = Mockery::mock(NodeRouterService::class);
        $forwarder->shouldReceive('forwardChat')
            ->once()
            ->andReturnUsing(function (AINode $target) {
                return [
                    'success' => true,
                    'response' => 'Order #1 is shipped.',
                    'metadata' => ['node' => $target->slug],
                ];
            });
        $this->app->instance(NodeRouterService::class, $forwarder);

        [$result] = $this->routeFor('Order');

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('orders-node', $result['node']);
        $this->assertSame('Order #1 is shipped.', $result['response']);
    }

    public function test_no_node_owns_the_model_returns_failure_array(): void
    {
        // An active healthy node that owns a DIFFERENT collection.
        $this->seedNode([
            'slug' => 'billing-node',
            'status' => 'active',
            'collections' => ['Invoice'],
        ]);

        [$result] = $this->routeFor('Order');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No node found with model Order', $result['error']);
    }

    public function test_inactive_node_owning_model_is_not_routed_to(): void
    {
        // Node owns the model but is inactive -> excluded by the active() scope, so the
        // resolver finds no owner and routeForModel must report a failure array.
        $this->seedNode([
            'slug' => 'dormant-node',
            'status' => 'inactive',
            'collections' => ['Shipment'],
        ]);

        [$result] = $this->routeFor('Shipment');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No node found with model Shipment', $result['error']);
    }

    public function test_unhealthy_active_node_owning_model_is_not_routed_to(): void
    {
        // Active but unhealthy (too many ping failures, stale ping) -> excluded by the
        // healthy() scope in production, so again no owner is resolved.
        $this->seedNode([
            'slug' => 'flaky-node',
            'status' => 'active',
            'ping_failures' => 5,
            'last_ping_at' => now()->subHour(),
            'collections' => ['Ticket'],
        ]);

        [$result] = $this->routeFor('Ticket');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No node found with model Ticket', $result['error']);
    }

    public function test_missing_model_param_returns_failure_array(): void
    {
        $router = $this->app->make(\LaravelAIEngine\Contracts\RAG\FederatedModelRouter::class);

        $result = $router->routeForModel([], 'hello', 'sess-x', 1, [], []);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('No model specified', $result['error']);
    }
}
