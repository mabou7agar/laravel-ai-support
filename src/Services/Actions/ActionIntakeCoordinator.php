<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class ActionIntakeCoordinator
{
    public function __construct(protected ActionPayloadExtractor $payloadExtractor)
    {
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, callable> $callbacks
     * @param array<string, mixed> $options
     */
    public function prepare(
        string $message,
        UnifiedActionContext $context,
        int|string $ownerKey,
        string $scope,
        array $action,
        array $callbacks,
        array $options = []
    ): AgentResponse {
        $cachedPayload = $this->cachedPayload($context, $ownerKey, $scope);

        $incomingPayload = $this->extractPayload($action, $message, $cachedPayload, $context, $callbacks, $options);
        $payload = $this->call($callbacks['merge_payload'] ?? null, [$cachedPayload, $incomingPayload])
            ?? array_replace_recursive($cachedPayload, $incomingPayload);

        $relationResponse = $this->call($callbacks['review_relations'] ?? null, [$payload, $context, $ownerKey, $scope]);
        if ($relationResponse instanceof AgentResponse) {
            return $relationResponse;
        }

        $result = $this->call($callbacks['prepare'] ?? null, [$payload, $context, $action]);
        if (!is_array($result)) {
            $result = ['success' => false, 'error' => 'Action prepare callback did not return a result.'];
        }

        if ($result['success'] ?? false) {
            $this->putDraftPayload($context, $ownerKey, $scope, $result['draft']['payload'] ?? $payload);
            $this->forgetIntakePayload($context, $ownerKey, $scope);
        } else {
            $this->putIntakePayload($context, $ownerKey, $scope, $payload);
        }

        $message = (string) ($this->call($callbacks['format_prepare_reply'] ?? null, [$result]) ?? ($result['message'] ?? $result['error'] ?? 'More information is required.'));

        return new AgentResponse(
            success: true,
            message: $message,
            strategy: ($result['success'] ?? false)
                ? (string) ($options['prepared_strategy'] ?? 'action_prepare')
                : (string) ($options['needs_input_strategy'] ?? 'action_needs_input'),
            metadata: [
                'agent_strategy' => ($result['success'] ?? false)
                    ? (string) ($options['prepared_strategy'] ?? 'action_prepare')
                    : (string) ($options['needs_input_strategy'] ?? 'action_needs_input'),
                'decision_source' => 'action_intake_coordinator',
                'workflow_data' => $result,
            ],
            isComplete: true
        );
    }

    /**
     * @param array<string, callable> $callbacks
     * @param array<string, mixed> $options
     */
    public function executePending(
        UnifiedActionContext $context,
        int|string $ownerKey,
        string $scope,
        array $callbacks,
        array $options = []
    ): ?AgentResponse {
        $payload = $this->draftPayload($context, $ownerKey, $scope);
        if (!is_array($payload)) {
            return null;
        }

        $result = $this->call($callbacks['execute'] ?? null, [$payload, $context]);
        if (!is_array($result)) {
            $result = ['success' => false, 'error' => 'Action execute callback did not return a result.'];
        }

        if ($result['success'] ?? false) {
            $this->forgetDraftPayload($context, $ownerKey, $scope);
            $this->forgetIntakePayload($context, $ownerKey, $scope);
        }

        return new AgentResponse(
            success: true,
            message: (string) ($result['message'] ?? $result['error'] ?? 'Action failed.'),
            strategy: ($result['success'] ?? false)
                ? (string) ($options['executed_strategy'] ?? 'action_execute')
                : (string) ($options['failed_strategy'] ?? 'action_failed'),
            metadata: [
                'agent_strategy' => ($result['success'] ?? false)
                    ? (string) ($options['executed_strategy'] ?? 'action_execute')
                    : (string) ($options['failed_strategy'] ?? 'action_failed'),
                'decision_source' => 'action_intake_coordinator',
                'workflow_data' => $result,
            ],
            isComplete: true
        );
    }

    public function cachedPayload(UnifiedActionContext $context, int|string $ownerKey, string $scope): array
    {
        $payload = $this->intakePayload($context, $ownerKey, $scope);
        if (is_array($payload)) {
            return $payload;
        }

        $payload = $this->draftPayload($context, $ownerKey, $scope);

        return is_array($payload) ? $payload : [];
    }

    public function draftPayload(UnifiedActionContext $context, int|string $ownerKey, string $scope): ?array
    {
        $payload = Cache::get($this->draftKey($context, $ownerKey, $scope));

        return is_array($payload) ? $payload : null;
    }

    public function intakePayload(UnifiedActionContext $context, int|string $ownerKey, string $scope): ?array
    {
        $payload = Cache::get($this->intakeKey($context, $ownerKey, $scope));

        return is_array($payload) ? $payload : null;
    }

    public function putDraftPayload(UnifiedActionContext $context, int|string $ownerKey, string $scope, array $payload, mixed $ttl = null): void
    {
        Cache::put($this->draftKey($context, $ownerKey, $scope), $payload, $ttl ?? now()->addHours(2));
    }

    public function putIntakePayload(UnifiedActionContext $context, int|string $ownerKey, string $scope, array $payload, mixed $ttl = null): void
    {
        Cache::put($this->intakeKey($context, $ownerKey, $scope), $payload, $ttl ?? now()->addHours(2));
    }

    public function forgetDraftPayload(UnifiedActionContext $context, int|string $ownerKey, string $scope): void
    {
        Cache::forget($this->draftKey($context, $ownerKey, $scope));
    }

    public function forgetIntakePayload(UnifiedActionContext $context, int|string $ownerKey, string $scope): void
    {
        Cache::forget($this->intakeKey($context, $ownerKey, $scope));
    }

    public function draftKey(UnifiedActionContext $context, int|string $ownerKey, string $scope): string
    {
        return "ai-agent:action-intake:draft:{$ownerKey}:{$context->sessionId}:{$scope}";
    }

    public function intakeKey(UnifiedActionContext $context, int|string $ownerKey, string $scope): string
    {
        return "ai-agent:action-intake:payload:{$ownerKey}:{$context->sessionId}:{$scope}";
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $cachedPayload
     * @param array<string, callable> $callbacks
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function extractPayload(
        array $action,
        string $message,
        array $cachedPayload,
        UnifiedActionContext $context,
        array $callbacks,
        array $options
    ): array {
        $recentHistory = is_callable($callbacks['recent_history'] ?? null)
            ? (array) $callbacks['recent_history']($context, $options)
            : [];

        $payload = $this->payloadExtractor->extract(
            action: $action,
            message: $message,
            currentPayload: $cachedPayload,
            recentHistory: $recentHistory,
            options: is_array($options['extraction'] ?? null) ? $options['extraction'] : []
        );

        if (is_array($payload)) {
            return (array) ($this->call($callbacks['normalize_payload'] ?? null, [$payload, $cachedPayload, $context]) ?? $payload);
        }

        return (array) ($this->call($callbacks['fallback_payload'] ?? null, [$message, $cachedPayload, $context]) ?? []);
    }

    protected function call(?callable $callback, array $arguments): mixed
    {
        return $callback ? $callback(...$arguments) : null;
    }
}
