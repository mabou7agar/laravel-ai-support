<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentResponseFinalizer
{
    public function __construct(protected ContextManager $contextManager)
    {
    }

    public function finalize(UnifiedActionContext $context, AgentResponse $response): AgentResponse
    {
        $metadata = [];
        if (!empty($response->metadata['entity_ids'])) {
            $metadata['entity_ids'] = $response->metadata['entity_ids'];
            $metadata['entity_type'] = $response->metadata['entity_type'] ?? 'item';
            unset($context->metadata['selected_entity_context']);
        }

        $this->appendAssistantMessageIfNew($context, $response->message, $metadata);
        $this->contextManager->save($context);

        return $response;
    }

    public function persistMessage(UnifiedActionContext $context, string $message, array $metadata = []): void
    {
        $this->appendAssistantMessageIfNew($context, $message, $metadata);
        $this->contextManager->save($context);
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
