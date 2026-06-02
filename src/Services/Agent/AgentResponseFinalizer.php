<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentResponseFinalizer
{
    public function __construct(
        protected ContextManager $contextManager,
        protected ?ConversationContextCompactor $compactor = null,
        protected ?AgentTraceMetadataService $traceMetadata = null
    )
    {
        if ($this->compactor === null) {
            try {
                $this->compactor = app()->bound(ConversationContextCompactor::class)
                    ? app(ConversationContextCompactor::class)
                    : new ConversationContextCompactor();
            } catch (\Throwable) {
                $this->compactor = new ConversationContextCompactor();
            }
        }

        if ($this->traceMetadata === null) {
            try {
                $this->traceMetadata = app()->bound(AgentTraceMetadataService::class)
                    ? app(AgentTraceMetadataService::class)
                    : new AgentTraceMetadataService();
            } catch (\Throwable) {
                $this->traceMetadata = new AgentTraceMetadataService();
            }
        }
    }

    /**
     * @param array<string, mixed> $options Current-request scope (tenant_id/workspace_id) threaded
     *                                       through to compaction so memories are written under the
     *                                       current scope rather than stale cached context metadata.
     */
    public function finalize(UnifiedActionContext $context, AgentResponse $response, array $options = []): AgentResponse
    {
        $response = $this->traceMetadata->enrichResponse(
            $response,
            $this->traceMetadata->responseMetadataFromContext($context->metadata ?? [])
        );

        $metadata = $this->traceMetadata->responseMetadataFromContext($response->metadata ?? []);
        if (!empty($response->metadata['entity_ids'])) {
            $metadata['entity_ids'] = $response->metadata['entity_ids'];
            $metadata['entity_type'] = $response->metadata['entity_type'] ?? 'item';
            // A new selection replaces any prior transient selection context;
            // when there is no new selection it is preserved so follow-up turns
            // can keep referring to the same entity.
            unset($context->metadata['selected_entity_context']);
        }

        $this->appendAssistantMessageIfNew($context, $response->message, $metadata);
        $this->compactor->compact($context, $options);
        $this->contextManager->save($context, $options);

        return $response;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options Current-request scope threaded through to compaction.
     */
    public function persistMessage(UnifiedActionContext $context, string $message, array $metadata = [], array $options = []): void
    {
        $this->appendAssistantMessageIfNew($context, $message, $metadata);
        $this->compactor->compact($context, $options);
        $this->contextManager->save($context, $options);
    }

    public function appendAssistantMessageIfNew(UnifiedActionContext $context, string $message, array $metadata = []): void
    {
        $history = $context->conversationHistory ?? [];
        $lastMessage = !empty($history) ? end($history) : null;

        if (
            is_array($lastMessage) &&
            ($lastMessage['role'] ?? null) === 'assistant' &&
            ($lastMessage['content'] ?? null) === $message
        ) {
            return;
        }

        $context->addAssistantMessage($message, $metadata);
    }

}
