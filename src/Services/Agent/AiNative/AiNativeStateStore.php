<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;

class AiNativeStateStore
{
    public function __construct(private readonly ?AgentExecutionPolicyService $policy = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function state(UnifiedActionContext $context): array
    {
        $state = $context->metadata['ai_native'] ?? [];
        if (!is_array($state)) {
            $state = [];
        }

        $normalized = [
            'tool_results' => is_array($state['tool_results'] ?? null) ? array_values($state['tool_results']) : [],
            'pending_tool' => is_array($state['pending_tool'] ?? null) ? $state['pending_tool'] : null,
        ];

        foreach (['selected_skill_id', 'runtime_scope', 'fresh_start', 'runtime_feedback', 'confirmed_write_tools', 'suggested_tool_continuation', 'suggested_tool_attempts', 'task_frame', 'recent_outcomes'] as $key) {
            if (array_key_exists($key, $state)) {
                $normalized[$key] = $state[$key];
            }
        }

        return $this->capHistory($normalized);
    }

    /**
     * Bound the PERSISTED history a session drags into every planner step.
     * Tool results and outcome payloads recorded before a size cap existed
     * (or by older releases) live in the session state forever — one long
     * session re-serialized ~440KB of stale preview payloads into EVERY step
     * of every new turn (live repro: a one-line regenerate cost 118k input
     * tokens). At LOAD time:
     *  - each tool_results entry / recent_outcomes entry is pruned to
     *    ai_native.state_result_max_bytes (same knob the executor applies at
     *    record time; 0 = off, today's behavior byte-for-byte);
     *  - tool_results is trimmed to the newest ai_native.state_history_max_results
     *    entries (0 = unlimited).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function capHistory(array $state): array
    {
        $maxBytes = (int) (\function_exists('config') ? config('ai-agent.ai_native.state_result_max_bytes', 0) : 0);
        $maxResults = (int) (\function_exists('config') ? config('ai-agent.ai_native.state_history_max_results', 0) : 0);
        if ($maxBytes <= 0 && $maxResults <= 0) {
            return $state;
        }

        if ($maxResults > 0 && is_array($state['tool_results'] ?? null) && count($state['tool_results']) > $maxResults) {
            $dropped = count($state['tool_results']) - $maxResults;
            $state['tool_results'] = array_slice(array_values($state['tool_results']), -$maxResults);
            $state['compacted_tool_results'] = (int) ($state['compacted_tool_results'] ?? 0) + $dropped;
        }

        if ($maxBytes > 0) {
            foreach (['tool_results', 'recent_outcomes'] as $key) {
                if (! is_array($state[$key] ?? null)) {
                    continue;
                }
                foreach ($state[$key] as $i => $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($encoded === false || strlen($encoded) <= $maxBytes) {
                        continue;
                    }
                    $pruned = $this->pruneOversized($entry);
                    if (is_array($pruned)) {
                        $pruned['_state_truncated'] = true;
                        $pruned['_original_bytes'] = strlen($encoded);
                        $state[$key][$i] = $pruned;
                    }
                }
            }
        }

        return $state;
    }

    private function pruneOversized(mixed $value, int $depth = 0): mixed
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
            $prunedList = array_map(fn ($v) => $this->pruneOversized($v, $depth + 1), $value);
            $prunedList[] = sprintf('[pruned: +%d more entries]', $omitted);

            return $prunedList;
        }

        return array_map(fn ($v) => $this->pruneOversized($v, $depth + 1), $value);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function put(UnifiedActionContext $context, array $state): void
    {
        if (($state['runtime_feedback'] ?? null) === []) {
            unset($state['runtime_feedback']);
        }

        $context->metadata['ai_native'] = $this->redactedState($state);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function redactedState(array $state): array
    {
        return $this->redactedArray($state);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function redactedArray(array $payload): array
    {
        return $this->policy()->redactSensitive($payload);
    }

    private function policy(): AgentExecutionPolicyService
    {
        return $this->policy ?? app(AgentExecutionPolicyService::class);
    }
}
