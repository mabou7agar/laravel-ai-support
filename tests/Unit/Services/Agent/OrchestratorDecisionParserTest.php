<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\OrchestratorDecisionParser;
use PHPUnit\Framework\TestCase;

class OrchestratorDecisionParserTest extends TestCase
{
    public function test_parses_action_resource_and_reason(): void
    {
        $parser = new OrchestratorDecisionParser([
            'default_action' => 'conversational',
            'allowed_actions' => ['conversational', 'search_rag', 'route_to_node'],
        ]);

        $decision = $parser->parse("ACTION: route_to_node\nRESOURCE: billing-node\nREASON: Node owns invoices");

        $this->assertSame('route_to_node', $decision['action']);
        $this->assertSame('billing-node', $decision['resource_name']);
        $this->assertSame('Node owns invoices', $decision['reasoning']);
    }

    public function test_falls_back_when_action_is_not_allowed(): void
    {
        $parser = new OrchestratorDecisionParser([
            'default_action' => 'conversational',
            'allowed_actions' => ['conversational', 'search_rag'],
        ]);

        $decision = $parser->parse("ACTION: delete_everything\nRESOURCE: none\nREASON: invalid");

        $this->assertSame('conversational', $decision['action']);
        $this->assertNull($decision['resource_name']);
    }
}
