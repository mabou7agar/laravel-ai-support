<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools\Selectors;

use LaravelAIEngine\Services\Agent\Tools\AgentTool;

/**
 * Chooses which of the registered tools are exposed to the AiNative planner for a given
 * turn. Every tool's full JSON schema is injected into the prompt, so as the registry
 * grows (e.g. via AiResource) the context grows with it; a selector trims that to the
 * tools that matter this turn without changing how tools execute.
 *
 * Strategies are configured under `ai-agent.ai_native.tool_selection.strategy`.
 */
interface ToolSelectorContract
{
    /**
     * @param array<string, AgentTool> $tools   name => tool (already excluded-filtered)
     * @param array<string, mixed>     $state   current AiNative runtime state
     * @param array<string, mixed>     $options request options
     * @return array<string, AgentTool> the subset to expose this turn
     */
    public function select(array $tools, string $message, array $state, array $options): array;
}
