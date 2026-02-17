<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

/**
 * Converts AgentResponse (internal orchestrator format) to AIResponse
 * (public API format returned to controllers/consumers).
 *
 * Extracted from ChatService to keep conversion logic testable
 * and reusable across different entry points (API, CLI, etc.).
 */
class AgentResponseConverter
{
    /**
     * Convert an AgentResponse to an AIResponse.
     *
     * @param AgentResponse $agentResponse  The orchestrator's response
     * @param string        $engine         AI engine identifier
     * @param string        $model          AI model identifier
     * @param string|null   $conversationId Conversation ID for persistence tracking
     * @return AIResponse
     */
    public function convert(
        AgentResponse $agentResponse,
        string $engine = 'openai',
        string $model = 'gpt-4o-mini',
        ?string $conversationId = null
    ): AIResponse {
        $contextMetadata = array_merge(
            $agentResponse->context->toArray(),
            $agentResponse->metadata ?? []
        );

        $entityTracking = $this->extractEntityTracking($agentResponse);

        return new AIResponse(
            content: $agentResponse->message,
            engine: EngineEnum::from($engine),
            model: EntityEnum::from($model),
            metadata: array_merge($contextMetadata, $entityTracking, [
                'workflow_active' => !$agentResponse->isComplete,
                'workflow_class' => $agentResponse->context->currentWorkflow,
                'workflow_data' => $agentResponse->data ?? [],
                'workflow_completed' => $agentResponse->isComplete,
                'agent_strategy' => $agentResponse->strategy,
            ]),
            success: $agentResponse->success,
            conversationId: $conversationId
        );
    }

    /**
     * Extract entity tracking data from the agent response context.
     */
    protected function extractEntityTracking(AgentResponse $agentResponse): array
    {
        if (!isset($agentResponse->context->metadata['last_entity_list'])) {
            return [];
        }

        $lastList = $agentResponse->context->metadata['last_entity_list'];

        return [
            'entity_ids' => $lastList['entity_ids'] ?? null,
            'entity_type' => $lastList['entity_type'] ?? null,
        ];
    }
}
