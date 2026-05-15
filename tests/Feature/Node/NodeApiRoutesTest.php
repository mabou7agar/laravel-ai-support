<?php

namespace LaravelAIEngine\Tests\Feature\Node;

use Illuminate\Http\Request;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Http\Middleware\NodeAuthMiddleware;
use LaravelAIEngine\Http\Middleware\NodeRateLimitMiddleware;
use LaravelAIEngine\Http\Controllers\Node\NodeApiController;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\Actions\ActionManager;
use LaravelAIEngine\Services\Node\NodeManifestService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class NodeApiRoutesTest extends UnitTestCase
{
    protected ActionManager $actions;
    protected ChatService $chat;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('ai-engine.nodes.enabled', true);
        $app['config']->set('ai-engine.nodes.jwt.secret', 'test-jwt-secret');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $manifest = Mockery::mock(NodeManifestService::class);
        $manifest->shouldReceive('health')->andReturn([
            'status' => 'healthy',
            'version' => '2.0.0',
        ]);
        $manifest->shouldReceive('manifest')->andReturn([
            'node' => ['slug' => 'billing'],
            'collections' => [],
            'autonomous_collectors' => [],
        ]);
        $manifest->shouldReceive('collections')->andReturn([]);
        $manifest->shouldReceive('autonomousCollectors')->andReturn([]);

        $actions = Mockery::mock(ActionManager::class);

        $chat = Mockery::mock(ChatService::class);

        $this->app->instance(NodeManifestService::class, $manifest);
        $this->actions = $actions;
        $this->chat = $chat;

        $this->app->instance(ActionManager::class, $actions);
        $this->app->instance(ChatService::class, $chat);
    }

    public function test_public_manifest_endpoints_are_available(): void
    {
        $this->getJson('/api/ai-engine/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy');

        $this->getJson('/api/ai-engine/manifest')
            ->assertOk()
            ->assertJsonPath('data.node.slug', 'billing');
    }

    public function test_tool_execution_uses_explicit_tool_endpoint(): void
    {
        $route = $this->app['router']->getRoutes()->match(
            Request::create('/api/ai-engine/tools/execute', 'POST')
        );

        $this->assertSame(NodeApiController::class . '@executeTool', $route->getActionName());

        $this->actions
            ->shouldReceive('executeById')
            ->once()
            ->with('view_source', ['model_id' => 10], null, 'session-1')
            ->andReturn(ActionResult::success('Opened.', ['opened' => true]));

        $request = new class extends Request {
            public function validate(array $rules, ...$params): array
            {
                return [
                    'action_type' => 'view_source',
                    'data' => ['model_id' => 10],
                    'session_id' => 'session-1',
                ];
            }

            public function user($guard = null)
            {
                return null;
            }
        };

        $response = $this->app->make(NodeApiController::class)->executeTool($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(true, $response->getData(true)['success']);
        $this->assertSame(true, $response->getData(true)['result']['data']['opened']);
    }

    public function test_generic_execute_route_is_removed(): void
    {
        $this->withoutMiddleware([NodeAuthMiddleware::class, NodeRateLimitMiddleware::class]);

        $this->postJson('/api/ai-engine/execute', [
            'model_class' => 'App\\Models\\Invoice',
            'action' => 'create',
        ])->assertNotFound();
    }

    public function test_active_protected_federation_routes_are_registered(): void
    {
        $searchRoute = $this->app['router']->getRoutes()->match(
            Request::create('/api/ai-engine/search', 'POST')
        );
        $chatRoute = $this->app['router']->getRoutes()->match(
            Request::create('/api/ai-engine/chat', 'POST')
        );

        $this->assertSame(NodeApiController::class . '@search', $searchRoute->getActionName());
        $this->assertSame(NodeApiController::class . '@chat', $chatRoute->getActionName());
    }

    public function test_node_management_routes_are_removed_from_public_api(): void
    {
        $this->withoutMiddleware([NodeAuthMiddleware::class, NodeRateLimitMiddleware::class]);

        $this->postJson('/api/ai-engine/register', [])->assertNotFound();
        $this->postJson('/api/ai-engine/aggregate', [])->assertNotFound();
        $this->getJson('/api/ai-engine/status')->assertNotFound();
        $this->postJson('/api/ai-engine/refresh-token', [])->assertNotFound();
        $this->getJson('/api/ai-engine/dashboard')->assertNotFound();
    }

    public function test_chat_endpoint_returns_guarded_error_when_required_migration_tables_are_missing(): void
    {
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', true);
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.required_tables', ['__missing_guard_table__']);

        $this->chat->shouldNotReceive('processMessage');

        $request = new class extends Request {
            public function validate(array $rules, ...$params): array
            {
                return [
                    'message' => 'hello',
                    'session_id' => 'session-1',
                    'options' => [],
                ];
            }
        };

        $response = $this->app->make(NodeApiController::class)->chat($request);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('infra.migrations_missing', $response->getData(true)['code']);
    }

    public function test_chat_endpoint_forwards_selected_entity_context_into_chat_options(): void
    {
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', false);

        $this->chat
            ->shouldReceive('processMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $extraOptions = $args[11] ?? [];

                return ($args[0] ?? null) === 'show details'
                    && ($args[1] ?? null) === 'session-1'
                    && (($extraOptions['selected_entity']['entity_id'] ?? null) === 77)
                    && (($extraOptions['selected_entity']['entity_type'] ?? null) === 'invoice');
            })
            ->andReturn(AIResponse::success('ok', 'openai', 'gpt-4o-mini'));

        $request = new class extends Request {
            public function validate(array $rules, ...$params): array
            {
                return [
                    'message' => 'show details',
                    'session_id' => 'session-1',
                    'user_id' => 5,
                    'options' => [
                        'selected_entity' => [
                            'entity_id' => 77,
                            'entity_type' => 'invoice',
                        ],
                    ],
                ];
            }
        };

        $response = $this->app->make(NodeApiController::class)->chat($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getData(true)['response']);
    }
}
