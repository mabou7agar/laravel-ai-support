<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Collection;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRouterService;
use LaravelAIEngine\Tests\UnitTestCase;

class NodeSessionManagerTest extends UnitTestCase
{
    public function test_should_continue_session_when_remote_pending_action_exists(): void
    {
        $node = new AINode();
        $node->forceFill([
            'slug' => 'inbusiness',
            'collections' => ['invoice', 'product'],
        ]);

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->with('inbusiness')->willReturn($node);

        $ai = $this->createMock(AIEngineService::class);
        $ai->expects($this->never())->method('generate');

        $manager = new NodeSessionManager(
            $ai,
            $registry,
            $this->createMock(NodeRouterService::class),
            $this->createMock(AgentResponseFinalizer::class)
        );

        $context = new UnifiedActionContext('session-1', 5);
        $context->set('routed_to_node', ['node_slug' => 'inbusiness']);
        $context->set('remote_pending_action', [
            'status' => 'awaiting_input',
            'node_slug' => 'inbusiness',
        ]);

        $this->assertTrue($manager->shouldContinueSession('Google hat i wear on face', $context));
    }

    public function test_should_continue_session_for_follow_up_answer_to_previous_question(): void
    {
        $node = new AINode();
        $node->forceFill([
            'slug' => 'inbusiness',
            'collections' => ['invoice', 'product'],
        ]);

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->with('inbusiness')->willReturn($node);

        $ai = $this->createMock(AIEngineService::class);
        $ai->expects($this->never())->method('generate');

        $manager = new NodeSessionManager(
            $ai,
            $registry,
            $this->createMock(NodeRouterService::class),
            $this->createMock(AgentResponseFinalizer::class)
        );

        $context = new UnifiedActionContext('session-1', 5);
        $context->set('routed_to_node', ['node_slug' => 'inbusiness']);
        $context->conversationHistory = [
            ['role' => 'assistant', 'content' => 'Could you please specify which Google product you mean?'],
        ];

        $this->assertTrue($manager->shouldContinueSession('Google hat i wear on face', $context));
    }

    public function test_route_to_node_tracks_remote_pending_state_and_entity_slot(): void
    {
        $node = new AINode();
        $node->slug = 'inbusiness';
        $node->name = 'InBusiness';
        $node->url = 'https://inbusiness.test';

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->with('inbusiness')->willReturn($node);

        $router = $this->createMock(NodeRouterService::class);
        $router->expects($this->once())
            ->method('forwardChat')
            ->willReturn([
                'success' => true,
                'response' => 'Let\'s proceed with the "Google" product. Please specify more details.',
                'metadata' => [
                    'session_active' => true,
                    'current_step' => 'resolve_products',
                ],
            ]);

        $manager = new NodeSessionManager(
            $this->createMock(AIEngineService::class),
            $registry,
            $router,
            $this->createMock(AgentResponseFinalizer::class)
        );

        $context = new UnifiedActionContext('session-1', 5);

        $response = $manager->routeToNode('inbusiness', 'yes', $context, []);

        $this->assertTrue($response->success);
        $this->assertTrue($response->needsUserInput);
        $this->assertFalse($response->isComplete);
        $this->assertTrue($context->has('remote_pending_action'));
        $pending = $context->get('remote_pending_action');
        $this->assertSame('awaiting_input', $pending['status']);
        $this->assertSame('inbusiness', $pending['node_slug']);
        $this->assertSame('product', $pending['pending_entity_slot']['entity'] ?? null);
        $this->assertSame('Google', $pending['pending_entity_slot']['value'] ?? null);
        $this->assertIsArray($context->pendingAction);
        $this->assertSame('remote_node_session', $context->pendingAction['type'] ?? null);
    }

    public function test_continue_session_clears_remote_pending_state_when_session_completes(): void
    {
        $node = new AINode();
        $node->slug = 'inbusiness';
        $node->name = 'InBusiness';
        $node->url = 'https://inbusiness.test';

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->with('inbusiness')->willReturn($node);

        $router = $this->createMock(NodeRouterService::class);
        $router->expects($this->once())
            ->method('forwardChat')
            ->willReturn([
                'success' => true,
                'response' => 'Product created successfully.',
                'metadata' => [
                    'session_active' => false,
                    'session_completed' => true,
                ],
            ]);

        $finalizer = $this->createMock(AgentResponseFinalizer::class);
        $finalizer->expects($this->once())
            ->method('persistMessage');

        $manager = new NodeSessionManager(
            $this->createMock(AIEngineService::class),
            $registry,
            $router,
            $finalizer
        );

        $context = new UnifiedActionContext('session-1', 5);
        $context->set('routed_to_node', ['node_slug' => 'inbusiness']);
        $context->set('remote_pending_action', [
            'status' => 'awaiting_input',
            'node_slug' => 'inbusiness',
        ]);
        $context->pendingAction = [
            'type' => 'remote_node_session',
        ];

        $response = $manager->continueSession('yes', $context, []);

        $this->assertNotNull($response);
        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertFalse($context->has('remote_pending_action'));
        $this->assertNull($context->pendingAction);
    }

    public function test_should_continue_session_detects_obvious_domain_shift(): void
    {
        $node = new AINode();
        $node->forceFill([
            'slug' => 'billing',
            'collections' => ['invoice'],
        ]);

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->with('billing')->willReturn($node);

        $manager = new NodeSessionManager(
            $this->createMock(AIEngineService::class),
            $registry,
            $this->createMock(NodeRouterService::class),
            $this->createMock(AgentResponseFinalizer::class)
        );

        $context = new UnifiedActionContext('session-1', 5);
        $context->set('routed_to_node', ['node_slug' => 'billing']);

        $this->assertFalse($manager->shouldContinueSession('list emails', $context));
    }

    public function test_route_to_node_resolves_collection_owner_and_marks_context(): void
    {
        app()->setLocale('ar');

        $node = new AINode();
        $node->slug = 'billing';
        $node->name = 'Billing';
        $node->url = 'https://billing.test';

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->with('invoice')->willReturn(null);
        $registry->method('getAllNodes')->willReturn(new Collection());
        $registry->method('findNodeForCollection')->willReturnCallback(function (string $resource) use ($node) {
            return $resource === 'invoice' ? $node : null;
        });

        $router = $this->createMock(NodeRouterService::class);
        $router->expects($this->once())
            ->method('forwardChat')
            ->with(
                $node,
                'list invoices',
                'session-1',
                $this->callback(function (array $options): bool {
                    return ($options['headers']['X-Locale'] ?? null) === 'ar'
                        && ($options['selected_entity']['entity_id'] ?? null) === 42
                        && ($options['selected_entity']['entity_type'] ?? null) === 'invoice';
                }),
                5
            )
            ->willReturn([
                'success' => true,
                'response' => 'Invoices from billing',
            ]);

        $manager = new NodeSessionManager(
            $this->createMock(AIEngineService::class),
            $registry,
            $router,
            $this->createMock(AgentResponseFinalizer::class)
        );

        $context = new UnifiedActionContext('session-1', 5);
        $context->metadata['selected_entity_context'] = [
            'entity_id' => 42,
            'entity_type' => 'invoice',
            'model_class' => 'App\\Models\\Invoice',
        ];

        $response = $manager->routeToNode('invoice', 'list invoices', $context, []);

        $this->assertTrue($response->success);
        $this->assertSame('billing', $context->get('routed_to_node')['node_slug']);
    }
}
