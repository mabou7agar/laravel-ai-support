<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use LaravelAIEngine\Tests\UnitTestCase;

class OrchestrationFixtureCommandsTest extends UnitTestCase
{
    public function test_routing_fixture_command_evaluates_default_fixtures(): void
    {
        $this->artisan('ai-engine:evaluate-routing-fixtures')
            ->expectsTable(['Fixture', 'Status', 'Expected', 'Actual'], [
                ['conversational_greeting', 'PASS', 'classifier:conversational', 'classifier:conversational'],
                ['semantic_rag_question', 'PASS', 'classifier:search_rag', 'classifier:search_rag'],
                ['numbered_option_selection_wins_before_classifier', 'PASS', 'selection:handle_selection', 'selection:handle_selection'],
                ['explicit_sub_agent_request', 'PASS', 'explicit:run_sub_agent', 'explicit:run_sub_agent'],
                ['forced_rag_request', 'PASS', 'explicit:search_rag', 'explicit:search_rag'],
            ])
            ->assertSuccessful();
    }

    public function test_rag_fixture_command_evaluates_default_fixtures(): void
    {
        $this->artisan('ai-engine:evaluate-rag-fixtures')
            ->expectsOutputToContain('vector_document_citation')
            ->expectsOutputToContain('hybrid_graph_vector_context')
            ->expectsOutputToContain('provider_file_search_citation')
            ->assertSuccessful();
    }

    public function test_benchmark_command_reports_thresholds_without_failing_on_warnings(): void
    {
        $this->artisan('ai-engine:benchmark-orchestration-v2', ['--iterations' => 1])
            ->expectsOutputToContain('classifier baseline')
            ->expectsOutputToContain('v2 runtime routing')
            ->expectsOutputToContain('v2 RAG context')
            ->assertSuccessful();
    }

    public function test_runtime_fixture_command_evaluates_tool_approval_and_langgraph_fixtures(): void
    {
        $this->artisan('ai-engine:evaluate-runtime-fixtures')
            ->expectsOutputToContain('deny_browser_control')
            ->expectsOutputToContain('mcp_tool_approval_interrupt')
            ->expectsOutputToContain('completed_run_maps_to_success')
            ->assertSuccessful();
    }
}
