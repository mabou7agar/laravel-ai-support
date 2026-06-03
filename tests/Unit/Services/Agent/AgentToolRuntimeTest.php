<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool;
use LaravelAIEngine\Services\Agent\Tools\SearchKnowledgeTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentToolRuntimeTest extends UnitTestCase
{
    public function test_default_tool_registry_registers_run_sub_agent_tool(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->has('run_sub_agent'));
        $this->assertInstanceOf(RunSubAgentTool::class, $registry->get('run_sub_agent'));
        $this->assertArrayHasKey('target', $registry->get('run_sub_agent')->getParameters());
    }

    public function test_default_tool_registry_registers_search_knowledge_tool(): void
    {
        // Without this tool the AiNative loop has no path to the vector/document RAG
        // store, so force_rag rerouted into AiNative would have nothing to call.
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->has('search_knowledge'));
        $this->assertInstanceOf(SearchKnowledgeTool::class, $registry->get('search_knowledge'));
        $this->assertArrayHasKey('query', $registry->get('search_knowledge')->getParameters());
    }

    public function test_run_sub_agent_tool_requires_target(): void
    {
        $result = (new RunSubAgentTool())->execute([], new UnifiedActionContext('tool-runtime-missing-target', 1));

        $this->assertFalse($result->success);
        $this->assertTrue($result->requiresUserInput());
        $this->assertSame('A target is required to run a sub-agent.', $result->message);
        $this->assertSame(['target'], $result->metadata['required_inputs']);
    }
}
