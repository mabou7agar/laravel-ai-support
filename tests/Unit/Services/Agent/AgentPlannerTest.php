<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\AgentPlanner;
use PHPUnit\Framework\TestCase;

class AgentPlannerTest extends TestCase
{
    public function test_plan_normalizes_unknown_action_to_search_rag(): void
    {
        $planner = new AgentPlanner();

        $plan = $planner->plan([
            'action' => 'something_else',
            'resource_name' => 'none',
        ]);

        $this->assertSame('search_rag', $plan['action']);
        $this->assertNull($plan['resource_name']);
    }

    public function test_dispatch_invokes_matching_handler(): void
    {
        $planner = new AgentPlanner();

        $result = $planner->dispatch([
            'action' => 'route_to_node',
            'resource_name' => 'billing',
        ], [
            'route_to_node' => fn (array $plan) => $plan['resource_name'],
            'search_rag' => fn (array $plan) => 'fallback',
        ]);

        $this->assertSame('billing', $result);
    }

    public function test_dispatch_uses_fallback_handler_for_unknown_action(): void
    {
        $planner = new AgentPlanner();

        $result = $planner->dispatch([
            'action' => 'invalid_action',
        ], [
            'search_rag' => fn (array $plan) => 'fallback',
        ]);

        $this->assertSame('fallback', $result);
    }
}
