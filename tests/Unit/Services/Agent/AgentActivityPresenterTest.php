<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\AgentActivityPresenter;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService as E;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentActivityPresenterTest extends UnitTestCase
{
    private function presenter(): AgentActivityPresenter
    {
        return new AgentActivityPresenter();
    }

    public function test_tool_started_humanizes_verb_and_entity(): void
    {
        $p = $this->presenter();

        $this->assertSame('Searching for customer', $p->describe(E::TOOL_STARTED, ['tool_name' => 'find_customer'])['label']);
        $this->assertSame('Creating invoice', $p->describe(E::TOOL_STARTED, ['tool_name' => 'create_invoice'])['label']);
        $this->assertSame('Modifying invoice', $p->describe(E::TOOL_STARTED, ['tool_name' => 'update_invoice'])['label']);
        $this->assertSame('Enhancing image', $p->describe(E::TOOL_STARTED, ['tool_name' => 'enhance_image'])['label']);
        $this->assertSame('Removing product', $p->describe(E::TOOL_STARTED, ['tool_name' => 'delete_product'])['label']);
    }

    public function test_special_tools_get_friendly_labels(): void
    {
        $p = $this->presenter();

        $this->assertSame('Looking up your data', $p->describe(E::TOOL_STARTED, ['tool_name' => 'data_query'])['label']);
        $this->assertSame('Running a skill', $p->describe(E::TOOL_STARTED, ['tool_name' => 'run_skill'])['label']);
        $this->assertSame('Delegating to a sub-agent', $p->describe(E::TOOL_STARTED, ['tool_name' => 'run_sub_agent'])['label']);
    }

    public function test_tool_icons_match_the_verb(): void
    {
        $p = $this->presenter();

        $this->assertSame('🔎', $p->describe(E::TOOL_STARTED, ['tool_name' => 'find_customer'])['icon']);
        $this->assertSame('✚', $p->describe(E::TOOL_STARTED, ['tool_name' => 'create_invoice'])['icon']);
        $this->assertSame('✎', $p->describe(E::TOOL_STARTED, ['tool_name' => 'update_invoice'])['icon']);
        $this->assertSame('✨', $p->describe(E::TOOL_STARTED, ['tool_name' => 'enhance_image'])['icon']);
    }

    public function test_tool_completed_and_failed(): void
    {
        $p = $this->presenter();

        $this->assertSame('Searching for customer — done', $p->describe(E::TOOL_COMPLETED, ['tool_name' => 'find_customer'])['label']);
        $this->assertSame('error', $p->describe(E::TOOL_FAILED, ['tool_name' => 'create_invoice'])['phase']);
    }

    public function test_routing_decision_labels_by_action(): void
    {
        $p = $this->presenter();

        $this->assertSame('Choosing the right action', $p->describe(E::ROUTING_DECIDED, ['decision' => ['action' => 'use_tool']])['label']);
        $this->assertSame('Looking for relevant information', $p->describe(E::ROUTING_DECIDED, ['decision' => ['action' => 'search_rag']])['label']);
        $this->assertSame('thinking', $p->describe(E::ROUTING_STAGE_STARTED)['phase']);
    }

    public function test_rag_sources_count(): void
    {
        $p = $this->presenter();
        $this->assertSame('Found 3 sources', $p->describe(E::RAG_SOURCES_FOUND, ['result_count' => 3])['label']);
        $this->assertSame('Found 1 source', $p->describe(E::RAG_SOURCES_FOUND, ['result_count' => 1])['label']);
    }

    public function test_lifecycle_and_terminal_flags(): void
    {
        $p = $this->presenter();

        $this->assertFalse($p->describe(E::RUN_STARTED)['terminal']);
        $this->assertSame('waiting', $p->describe(E::RUN_WAITING_INPUT)['phase']);

        $done = $p->describe(E::RUN_COMPLETED);
        $this->assertTrue($done['terminal']);
        $this->assertSame('Done', $done['label']);

        $failed = $p->describe(E::RUN_FAILED);
        $this->assertTrue($failed['terminal']);
        $this->assertSame('error', $failed['phase']);
    }

    public function test_reasoning_event_uses_reasoning_text_as_label(): void
    {
        $a = $this->presenter()->describe(E::AGENT_REASONING, ['reasoning' => 'Looking up the customer record']);

        $this->assertSame('Looking up the customer record', $a['label']);
        $this->assertSame('✶', $a['icon']);
        $this->assertSame('thinking', $a['phase']);
        $this->assertFalse($a['terminal']);
    }

    public function test_reasoning_event_falls_back_to_thinking_when_empty(): void
    {
        $this->assertSame('Thinking', $this->presenter()->describe(E::AGENT_REASONING)['label']);
        $this->assertSame('Thinking', $this->presenter()->describe(E::AGENT_REASONING, ['reasoning' => '   '])['label']);
    }

    public function test_plan_updated_event_builds_step_x_of_y_label(): void
    {
        $a = $this->presenter()->describe(E::PLAN_UPDATED, ['steps' => ['a', 'b', 'c'], 'current' => 2]);

        $this->assertSame('Planning (step 2 of 3)', $a['label']);
        $this->assertSame('thinking', $a['phase']);
        $this->assertSame('✶', $a['icon']);
        $this->assertFalse($a['terminal']);
    }

    public function test_plan_updated_event_clamps_current_index(): void
    {
        $p = $this->presenter();

        // current below 1 clamps up to 1, above the count clamps to the count.
        $this->assertSame('Planning (step 1 of 2)', $p->describe(E::PLAN_UPDATED, ['steps' => ['a', 'b'], 'current' => 0])['label']);
        $this->assertSame('Planning (step 2 of 2)', $p->describe(E::PLAN_UPDATED, ['steps' => ['a', 'b'], 'current' => 9])['label']);
    }

    public function test_plan_updated_event_falls_back_to_planning_when_empty(): void
    {
        $a = $this->presenter()->describe(E::PLAN_UPDATED);

        $this->assertSame('Planning', $a['label']);
        $this->assertSame('thinking', $a['phase']);
        $this->assertSame('✶', $a['icon']);
        $this->assertSame('Planning', $this->presenter()->describe(E::PLAN_UPDATED, ['steps' => []])['label']);
    }

    public function test_unknown_event_has_a_safe_default(): void
    {
        $a = $this->presenter()->describe('something.unknown');
        $this->assertSame('Working', $a['label']);
        $this->assertFalse($a['terminal']);
    }
}
