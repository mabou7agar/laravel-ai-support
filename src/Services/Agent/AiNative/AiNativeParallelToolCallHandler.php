<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Executes a planner-returned tool_calls[] batch in a single planning step.
 *
 * Each independent entry is reshaped into a single-call plan and delegated to the
 * EXISTING AiNativeToolCallActionHandler, so per-entry validation, confirmation,
 * lookup-miss and result-recording behavior is reused verbatim. Execution is
 * sequential under the hood; the win is one planning round-trip for N lookups.
 *
 * Any entry whose handler returns a non-null ->response (confirmation /
 * needsUserInput / non-action fallback) STOPS the batch and surfaces that
 * response, falling back to the normal single-call confirmation path.
 */
class AiNativeParallelToolCallHandler
{
    public function __construct(
        private readonly AiNativeToolCallActionHandler $toolCallHandler,
        private readonly AiNativeStateStore $stateStore,
        private readonly AiNativeResponseFactory $responses
    ) {}

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function handle(
        string $message,
        UnifiedActionContext $context,
        array &$state,
        array $options,
        array $plan
    ): AiNativeActionOutcome {
        $calls = $this->normalizeCalls($plan);
        if ($calls === []) {
            return AiNativeActionOutcome::continueLoop();
        }

        $max = max(1, (int) config('ai-agent.ai_native.parallel_tools_max', 8));
        $calls = array_slice($calls, 0, $max);

        foreach ($calls as $entry) {
            $singlePlan = [
                'action' => 'tool_call',
                'tool' => $entry['tool'] ?? $entry['tool_name'] ?? '',
                'arguments' => (array) ($entry['arguments'] ?? $entry['params'] ?? $entry['tool_params'] ?? []),
                'message' => $plan['message'] ?? '',
            ];

            $outcome = $this->toolCallHandler->handle($message, $context, $state, $options, $singlePlan);

            // A non-null response means confirmation / needsUserInput / non-action
            // fallback: stop the batch and surface it (the single-call path takes over).
            if ($outcome->response instanceof AgentResponse) {
                return $outcome;
            }

            // continueLoop entries already recorded their result into $state via the
            // handler's recordResult — keep going so the next plan sees them all.
        }

        $this->stateStore->put($context, $state);

        return AiNativeActionOutcome::continueLoop();
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    private function normalizeCalls(array $plan): array
    {
        $raw = $plan['tool_calls'] ?? $plan['calls'] ?? $plan['parallel_tool_calls'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $calls = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $tool = trim((string) ($entry['tool'] ?? $entry['tool_name'] ?? ''));
            if ($tool === '') {
                continue;
            }

            $calls[] = $entry;
        }

        return $calls;
    }
}
