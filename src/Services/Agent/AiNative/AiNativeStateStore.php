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

        return $normalized;
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
