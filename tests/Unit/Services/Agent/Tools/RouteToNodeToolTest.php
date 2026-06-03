<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Tools\RouteToNodeTool;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RouteToNodeToolTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    public function test_it_routes_the_turn_to_the_named_node(): void
    {
        $context = new UnifiedActionContext('route-node', 1);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('routeToNode')
            ->once()
            ->with('billing', 'show overdue invoices', $context, Mockery::on(fn (array $o): bool => ($o['source'] ?? null) === 'ai_native'))
            ->andReturn(AgentResponse::conversational('Routed to billing node.', $context));

        $tool = new RouteToNodeTool($nodes);

        $result = $tool->execute(['node' => 'billing', 'query' => 'show overdue invoices'], $context);

        $this->assertTrue($result->success);
        $this->assertSame('Routed to billing node.', $result->message);
        $this->assertSame('billing', $result->data['node']);
    }

    public function test_it_falls_back_to_the_latest_user_message_when_no_query_given(): void
    {
        $context = new UnifiedActionContext('route-node-fallback', 1, conversationHistory: [
            ['role' => 'assistant', 'content' => 'How can I help?'],
            ['role' => 'user', 'content' => 'what is my balance on the billing node'],
        ]);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('routeToNode')
            ->once()
            ->with('billing', 'what is my balance on the billing node', $context, Mockery::type('array'))
            ->andReturn(AgentResponse::conversational('Balance is $0.', $context));

        $tool = new RouteToNodeTool($nodes);

        $result = $tool->execute(['node' => 'billing'], $context);

        $this->assertTrue($result->success);
        $this->assertSame('Balance is $0.', $result->message);
    }

    public function test_it_rejects_an_empty_node_without_routing(): void
    {
        $context = new UnifiedActionContext('route-node-empty', 1);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldNotReceive('routeToNode');

        $tool = new RouteToNodeTool($nodes);

        $result = $tool->execute(['node' => '   '], $context);

        $this->assertFalse($result->success);
    }

    public function test_it_surfaces_a_routing_failure(): void
    {
        $context = new UnifiedActionContext('route-node-fail', 1);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('routeToNode')
            ->once()
            ->andReturn(AgentResponse::failure("I couldn't find a remote node matching 'ghost'.", context: $context));

        $tool = new RouteToNodeTool($nodes);

        $result = $tool->execute(['node' => 'ghost', 'query' => 'anything'], $context);

        $this->assertFalse($result->success);
    }
}
