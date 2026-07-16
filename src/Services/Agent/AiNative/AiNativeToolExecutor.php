<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class AiNativeToolExecutor
{
    public function __construct(
        private readonly AgentTaskStateService $taskState,
        private readonly AiNativeStateStore $stateStore,
        private readonly ?AgentExecutionPolicyService $policy = null
    ) {}

    public function execute(AgentTool $tool, array $params, UnifiedActionContext $context): ActionResult
    {
        $policy = $this->policy();
        if (!$policy->canUseTool($tool->getName(), ['context' => $context])) {
            return ActionResult::failure($policy->blockedMessage('tool', $tool->getName()));
        }

        return $tool->execute($params, $context);
    }

    public function recordResult(array &$state, string $toolName, array $params, ActionResult $result, bool $writeTool = false): void
    {
        $state['tool_results'][] = [
            'tool' => $toolName,
            // Params are capped too: a generate_view call carries the full
            // section HTML (and on regenerate, previous_html) — uncapped they
            // re-ship per step exactly like oversized results did.
            'params' => $this->capForState($this->stateStore->redactedArray($params)),
            'result' => $this->capForState($this->stateStore->redactedArray($result->toArray())),
        ];

        $this->taskState->recordToolResult($state, $toolName, $params, $result, $writeTool);
    }

    /**
     * Cap an oversized tool result BEFORE it enters the planner state. The whole
     * runtime state is re-serialized into EVERY subsequent planner step's prompt,
     * so one large result (a staged preview with its full operations payload, a
     * generated HTML view) re-ships tens of KB on each remaining step of the
     * turn. When the entry exceeds ai_native.state_result_max_bytes, long strings
     * are truncated and long lists elided — scalars (ids, statuses, counts) at
     * any depth survive so follow-up steps can still reference them. Default 0
     * (off) preserves today's behavior byte-for-byte; the ActionResult handed to
     * the host/response layer is never touched.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function capForState(array $result): array
    {
        $max = (int) config('ai-agent.ai_native.state_result_max_bytes', 0);
        if ($max <= 0) {
            return $result;
        }
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) <= $max) {
            return $result;
        }

        $pruned = $this->pruneForState($result);
        $pruned['_state_truncated'] = true;
        $pruned['_original_bytes'] = strlen($encoded);

        return is_array($pruned) ? $pruned : $result;
    }

    private function pruneForState(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > 300 ? mb_substr($value, 0, 300) . '…[truncated]' : $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if ($depth >= 6) {
            return '[pruned: too deep]';
        }
        if (array_is_list($value) && count($value) > 10) {
            $omitted = count($value) - 10;
            $value = array_slice($value, 0, 10);
            $prunedList = array_map(fn ($v) => $this->pruneForState($v, $depth + 1), $value);
            $prunedList[] = sprintf('[pruned: +%d more entries]', $omitted);

            return $prunedList;
        }

        return array_map(fn ($v) => $this->pruneForState($v, $depth + 1), $value);
    }

    public function withConfirmationForValidation(AgentTool $tool, array $params): array
    {
        if (array_key_exists('confirmed', $tool->getParameters())) {
            $params['confirmed'] = true;
        }

        return $params;
    }

    private function policy(): AgentExecutionPolicyService
    {
        return $this->policy ?? app(AgentExecutionPolicyService::class);
    }
}
