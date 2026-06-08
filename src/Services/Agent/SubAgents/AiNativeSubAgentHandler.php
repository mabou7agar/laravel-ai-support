<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\SubAgents;

use LaravelAIEngine\Contracts\SubAgentHandler;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;

/**
 * A true "domain agent" sub-agent: runs the full AiNative planner (natural-language → tool
 * call with parameters, guards, confirmation) but scoped to ONLY the sub-agent's declared
 * tools, with its description as a persona.
 *
 * Unlike ToolCallingSubAgentHandler (which executes a pre-planned tool spec) and
 * ConversationalSubAgentHandler (no tools), this handler lets a sub-agent reason and act over
 * its own toolset — e.g. an "invoice agent" that answers "how much has X spent" by planning an
 * aggregate_data call itself. Select it with `'handler' => 'ai_native'` on a sub-agent that
 * declares `'tools'`.
 *
 * Scoping is enforced by building a ToolRegistry containing only the declared tools and
 * constructing a fresh AiNativeRuntime around it — the planner literally cannot see or call a
 * tool the sub-agent didn't declare. Skills are not scoped in this version.
 */
class AiNativeSubAgentHandler implements SubAgentHandler
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ?SubAgentRegistry $registry = null,
        private readonly ?AIEngineService $ai = null,
        private readonly ?AgentSkillRegistry $skills = null,
        private readonly ?IntentSignalService $signals = null,
    ) {
    }

    public function handle(
        SubAgentTask $task,
        UnifiedActionContext $context,
        array $previousResults = [],
        array $options = []
    ): SubAgentResult {
        $scopedTools = $this->scopedTools($task, $options);
        if ($scopedTools === []) {
            return SubAgentResult::failure(
                $task->id,
                $task->agentId,
                "Sub-agent '{$task->agentId}' has no usable tools to run as a domain agent."
            );
        }

        $registry = new ToolRegistry();
        foreach ($scopedTools as $name) {
            $tool = $this->tools->get($name);
            if ($tool !== null) {
                $registry->register($name, $tool);
            }
        }

        $runtime = new AiNativeRuntime(
            $this->ai ?? app(AIEngineService::class),
            $registry,
            $this->skills ?? app(AgentSkillRegistry::class),
            $this->signals ?? app(IntentSignalService::class),
        );

        // The domain agent is a self-contained run: it owns its tools and must not re-enter
        // the global skill flow that delegated to it.
        $response = $runtime->process($this->buildPrompt($task, $previousResults), $context, array_merge($options, [
            'runtime_scope' => 'sub_agent',
        ]));

        $metadata = array_merge($response->metadata ?? [], ['sub_agent' => $task->agentId, 'scoped_tools' => $scopedTools]);

        // Convergence safety net. The planner occasionally exhausts its step budget without
        // emitting a final answer even though a tool already produced the result — it loops on
        // "I have already calculated that" instead of finalizing. The runtime then returns a
        // generic "I need more information to continue." (needsUserInput with no specific
        // requiredInputs). For a domain agent that dead-ends a question whose answer is already
        // in hand, so we salvage the last successful tool result rather than asking the user for
        // nothing. A *genuine* ask (confirmation / a missing field) carries requiredInputs and is
        // left untouched.
        if ($response->needsUserInput && empty($response->requiredInputs)) {
            $salvaged = $this->lastSuccessfulToolResult($response);
            if ($salvaged !== null) {
                return SubAgentResult::success(
                    $task->id,
                    $task->agentId,
                    $salvaged['message'],
                    $salvaged['data'],
                    array_merge($metadata, ['converged_via' => 'tool_result_fallback'])
                );
            }
        }

        if ($response->needsUserInput) {
            return SubAgentResult::needsUserInput($task->id, $task->agentId, $response->message, $response->data, $metadata);
        }

        return $response->success
            ? SubAgentResult::success($task->id, $task->agentId, $response->message, $response->data, $metadata)
            : SubAgentResult::failure($task->id, $task->agentId, $response->message, $response->data, $metadata);
    }

    /**
     * The most recent successfully-executed tool result from the run, as a usable answer.
     * Returns null when no tool succeeded (nothing to salvage).
     *
     * @return array{message: string, data: array<string, mixed>|null}|null
     */
    private function lastSuccessfulToolResult(AgentResponse $response): ?array
    {
        $results = $response->metadata['ai_native']['tool_results'] ?? null;
        if (!is_array($results)) {
            return null;
        }

        for ($i = count($results) - 1; $i >= 0; $i--) {
            $result = $results[$i]['result'] ?? null;
            if (!is_array($result) || ($result['success'] ?? false) !== true) {
                continue;
            }

            $message = trim((string) ($result['message'] ?? ''));
            $data = is_array($result['data'] ?? null) ? $result['data'] : null;

            if ($message === '' && $data !== null) {
                $message = 'Result: ' . json_encode($data, JSON_UNESCAPED_UNICODE);
            }

            if ($message !== '') {
                return ['message' => $message, 'data' => $data];
            }
        }

        return null;
    }

    /**
     * The sub-agent's declared tools are a capability BOUND. A per-task/options tool list may
     * SELECT a subset but never escalate beyond what the sub-agent declared.
     *
     * @return array<int, string>
     */
    private function scopedTools(SubAgentTask $task, array $options): array
    {
        $definition = $this->registry?->get($task->agentId) ?? [];
        $declared = array_values(array_filter((array) ($definition['tools'] ?? []), 'is_string'));

        $requested = $task->input['tools']
            ?? $task->metadata['tools']
            ?? $options['tools']
            ?? null;
        $requested = is_array($requested) ? array_values(array_filter($requested, 'is_string')) : null;

        if ($declared === []) {
            // No declared bound — run with whatever was explicitly requested (open mode).
            return $requested ?? [];
        }

        if ($requested === null || $requested === []) {
            return $declared;
        }

        return array_values(array_intersect($requested, $declared));
    }

    /**
     * Lead with the objective as the primary instruction — the closest shape to a normal user
     * turn, which the planner finalizes most reliably. The persona is kept terse and trailing:
     * a long capability list ("…and create invoices") tends to make the planner believe there is
     * unfinished work and loop instead of answering. A persona is context, not the task.
     */
    private function buildPrompt(SubAgentTask $task, array $previousResults): string
    {
        $definition = $this->registry?->get($task->agentId) ?? [];
        $lines = [$task->objective];

        if ($task->input !== []) {
            $lines[] = 'Input: ' . json_encode($task->input, JSON_UNESCAPED_UNICODE);
        }
        if ($previousResults !== []) {
            $lines[] = 'Prior results: ' . json_encode(
                array_map(static fn (SubAgentResult $r): array => $r->toArray(), $previousResults),
                JSON_UNESCAPED_UNICODE
            );
        }

        $persona = trim((string) ($definition['description'] ?? $definition['name'] ?? ''));
        $note = $persona !== '' ? "You are the {$task->agentId} agent — {$persona} " : '';
        $lines[] = "\n({$note}Use your tools to answer, then give the final answer directly.)";

        return implode("\n", $lines);
    }
}
