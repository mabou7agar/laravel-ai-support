<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class PositionalReferenceCoordinator
{
    public function __construct(
        protected ?IntentClassifierService $intentClassifier = null,
        protected ?FollowUpStateService $followUpStateService = null,
        protected ?AgentPolicyService $policyService = null
    ) {
    }

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?int $preResolvedPosition,
        callable $askAI
    ): AgentResponse {
        $position = $preResolvedPosition ?? $this->getIntentClassifier()->extractPosition($message);
        if ($position === null) {
            return AgentResponse::conversational(
                message: $this->getPolicyService()->positionalUnknownMessage(),
                context: $context
            );
        }

        $entityData = $this->getEntityFromPosition($position, $context);
        if (!$entityData) {
            return AgentResponse::conversational(
                message: $this->getPolicyService()->positionalNotFoundMessage($position),
                context: $context
            );
        }

        Log::channel('ai-engine')->info('Resolved positional reference', [
            'position' => $position,
            'entity_id' => $entityData['id'],
            'entity_type' => $entityData['type'],
        ]);

        $fullEntity = $this->fetchEntityDetails(
            (int) $entityData['id'],
            (string) $entityData['type'],
            $context
        );

        if (!$fullEntity) {
            return AgentResponse::conversational(
                message: $this->getPolicyService()->positionalDetailsUnavailableMessage((string) $entityData['type']),
                context: $context
            );
        }

        $context->metadata['selected_entity_context'] = [
            'entity_id' => $entityData['id'],
            'entity_type' => $entityData['type'],
            'entity_data' => $fullEntity,
            'selected_via' => 'positional_reference',
            'position' => $position,
            'suggested_action_content' => null,
        ];

        Log::channel('ai-engine')->info('Enriched context with entity details', [
            'entity_id' => $entityData['id'],
            'has_data' => !empty($fullEntity),
        ]);

        return $askAI($message, $context, $options);
    }

    protected function getEntityFromPosition(int $position, UnifiedActionContext $context): ?array
    {
        $messages = array_reverse($context->conversationHistory);
        foreach ($messages as $msg) {
            if (($msg['role'] ?? null) !== 'assistant' || empty($msg['metadata']['entity_ids'])) {
                continue;
            }

            $entityIds = $msg['metadata']['entity_ids'];
            $entityType = $msg['metadata']['entity_type'] ?? 'item';
            $index = $position - 1;

            if (isset($entityIds[$index])) {
                return [
                    'id' => $entityIds[$index],
                    'type' => $entityType,
                ];
            }

            return null;
        }

        return null;
    }

    protected function fetchEntityDetails(int $entityId, string $entityType, UnifiedActionContext $context): ?array
    {
        try {
            $modelClass = $this->getFollowUpStateService()->resolveModelClass($entityType, $context);
            if (!$modelClass || !class_exists($modelClass)) {
                Log::channel('ai-engine')->warning('Unknown entity type for positional fetch', [
                    'entity_type' => $entityType,
                ]);
                return null;
            }

            $entity = $modelClass::find($entityId);
            if (!$entity) {
                Log::channel('ai-engine')->warning('Entity not found in database', [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'model_class' => $modelClass,
                ]);
                return null;
            }

            return $entity->toArray();
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Error fetching positional entity details', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function getIntentClassifier(): IntentClassifierService
    {
        if ($this->intentClassifier === null) {
            $this->intentClassifier = app(IntentClassifierService::class);
        }

        return $this->intentClassifier;
    }

    protected function getFollowUpStateService(): FollowUpStateService
    {
        if ($this->followUpStateService === null) {
            $this->followUpStateService = app(FollowUpStateService::class);
        }

        return $this->followUpStateService;
    }

    protected function getPolicyService(): AgentPolicyService
    {
        if ($this->policyService === null) {
            try {
                $this->policyService = app(AgentPolicyService::class);
            } catch (\Throwable $e) {
                $this->policyService = new AgentPolicyService();
            }
        }

        return $this->policyService;
    }
}
