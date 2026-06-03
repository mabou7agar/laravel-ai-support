<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use LaravelAIEngine\Tests\UnitTestCase;

class OrchestrationFixtureCommandsTest extends UnitTestCase
{
    public function test_rag_fixture_command_evaluates_default_fixtures(): void
    {
        $this->artisan('ai:evaluate-rag-fixtures')
            ->expectsOutputToContain('vector_document_citation')
            ->expectsOutputToContain('hybrid_graph_vector_context')
            ->expectsOutputToContain('provider_file_search_citation')
            ->assertSuccessful();
    }

    public function test_runtime_fixture_command_evaluates_tool_approval_and_langgraph_fixtures(): void
    {
        $this->artisan('ai:evaluate-runtime-fixtures')
            ->expectsOutputToContain('deny_browser_control')
            ->expectsOutputToContain('mcp_tool_approval_interrupt')
            ->expectsOutputToContain('completed_run_maps_to_success')
            ->assertSuccessful();
    }
}
