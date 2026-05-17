<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\IntentSignalService;

class ActionIntakeFlowService
{
    public function __construct(
        protected ?IntentSignalService $intentSignals = null,
    ) {
    }

    public function pendingRelation(UnifiedActionContext $context, int|string $ownerKey, string $scope): ?array
    {
        $pending = Cache::get($this->relationKey($context, $ownerKey, $scope));

        return is_array($pending) ? $pending : null;
    }

    public function putPendingRelation(UnifiedActionContext $context, int|string $ownerKey, string $scope, array $pending, mixed $ttl = null): void
    {
        Cache::put($this->relationKey($context, $ownerKey, $scope), $pending, $ttl ?? now()->addHours(2));
    }

    public function forgetPendingRelation(UnifiedActionContext $context, int|string $ownerKey, string $scope): void
    {
        Cache::forget($this->relationKey($context, $ownerKey, $scope));
    }

    /**
     * @param array{
     *     apply_existing?: callable,
     *     mark_create_new?: callable,
     *     continue?: callable,
     *     create_details?: callable
     * } $callbacks
     */
    public function handlePendingRelationDecision(
        string $message,
        UnifiedActionContext $context,
        int|string $ownerKey,
        string $scope,
        array $callbacks = []
    ): ?AgentResponse {
        $pending = $this->pendingRelation($context, $ownerKey, $scope);
        if (!$pending) {
            return null;
        }

        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : [];
        $kind = (string) ($pending['kind'] ?? '');
        $decision = $this->relationDecision($message);

        if ($kind === 'existing_relation') {
            if ($decision['use_existing']) {
                $payload = $this->call($callbacks['apply_existing'] ?? null, [$payload, $pending, $decision]) ?? $payload;
                $this->forgetPendingRelation($context, $ownerKey, $scope);

                return $this->call($callbacks['continue'] ?? null, [$payload, '', $pending, $decision]);
            }

            if ($decision['create_new']) {
                $pending['kind'] = 'create_relation_details';
                $pending['payload'] = $payload;
                $this->putPendingRelation($context, $ownerKey, $scope, $pending);

                return AgentResponse::needsUserInput(
                    message: (string) ($pending['messages']['create_details'] ?? 'Please provide details for the new related record.'),
                    context: $context,
                    data: ['pending_relation' => $pending]
                );
            }

            return AgentResponse::needsUserInput(
                message: (string) ($pending['messages']['invalid_existing'] ?? 'Please choose whether to use the existing record or create a new one.'),
                context: $context,
                data: ['pending_relation' => $pending]
            );
        }

        if ($kind === 'create_relation_details') {
            $this->forgetPendingRelation($context, $ownerKey, $scope);

            return $this->call($callbacks['create_details'] ?? $callbacks['continue'] ?? null, [$payload, $message, $pending, $decision]);
        }

        if ($kind === 'relation_collection_review') {
            if (!$decision['use_existing'] && !$decision['create_new']) {
                return AgentResponse::needsUserInput(
                    message: (string) ($pending['messages']['invalid_collection'] ?? 'Please choose whether to use existing related records, create new records, or both.'),
                    context: $context,
                    data: ['pending_relation' => $pending]
                );
            }

            if ($decision['use_existing']) {
                $payload = $this->call($callbacks['apply_existing'] ?? null, [$payload, $pending, $decision]) ?? $payload;
            }

            if ($decision['create_new']) {
                $payload = $this->call($callbacks['mark_create_new'] ?? null, [$payload, $pending, $decision]) ?? $payload;
            }

            if (($pending['missing'] ?? []) !== [] && !$decision['create_new']) {
                return AgentResponse::needsUserInput(
                    message: (string) ($pending['messages']['missing_without_create'] ?? 'Some related records were not found. Choose create new or provide different values.'),
                    context: $context,
                    data: ['pending_relation' => $pending]
                );
            }

            $this->forgetPendingRelation($context, $ownerKey, $scope);

            return $this->call($callbacks['continue'] ?? null, [$payload, '', $pending, $decision]);
        }

        $this->forgetPendingRelation($context, $ownerKey, $scope);

        return null;
    }

    public function relationDecision(string $message): array
    {
        return [
            'use_existing' => $this->signals()->isRelationUseExisting($message),
            'create_new' => $this->signals()->isRelationCreateNew($message),
        ];
    }

    public function relationKey(UnifiedActionContext $context, int|string $ownerKey, string $scope): string
    {
        return "ai-agent:action-intake:relation:{$ownerKey}:{$context->sessionId}:{$scope}";
    }

    protected function call(?callable $callback, array $arguments): mixed
    {
        return $callback ? $callback(...$arguments) : null;
    }

    protected function signals(): IntentSignalService
    {
        return $this->intentSignals ??= app(IntentSignalService::class);
    }
}
