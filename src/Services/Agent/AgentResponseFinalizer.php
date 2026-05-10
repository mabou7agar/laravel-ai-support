<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentResponseFinalizer
{
    public function __construct(
        protected ContextManager $contextManager,
        protected ?ConversationContextCompactor $compactor = null
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
    }

    public function finalize(UnifiedActionContext $context, AgentResponse $response): AgentResponse
    {
        $metadata = [];
        if (!empty($response->metadata['entity_ids'])) {
            $metadata['entity_ids'] = $response->metadata['entity_ids'];
            $metadata['entity_type'] = $response->metadata['entity_type'] ?? 'item';
            unset($context->metadata['selected_entity_context']);
        }

        if (isset($response->metadata['workflow_data']) && is_array($response->metadata['workflow_data'])) {
            $workflow = $this->workflowBrief($response->metadata['workflow_data'], $response->strategy);
            if ($workflow !== []) {
                $context->metadata['last_action_workflow'] = $workflow;
                $metadata['action_workflow'] = $workflow;
            }
        }

        $this->appendAssistantMessageIfNew($context, $response->message, $metadata);
        $this->compactor->compact($context);
        $this->contextManager->save($context);

        return $response;
    }

    public function persistMessage(UnifiedActionContext $context, string $message, array $metadata = []): void
    {
        $this->appendAssistantMessageIfNew($context, $message, $metadata);
        $this->compactor->compact($context);
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

    /**
     * @param array<string, mixed> $workflowData
     * @return array<string, mixed>
     */
    protected function workflowBrief(array $workflowData, ?string $strategy): array
    {
        $action = is_array($workflowData['action'] ?? null) ? $workflowData['action'] : [];
        $draft = is_array($workflowData['draft'] ?? null) ? $workflowData['draft'] : [];
        $actionId = (string) (
            $workflowData['action_id']
            ?? $draft['action_id']
            ?? $action['id']
            ?? ''
        );

        if ($actionId === '') {
            return [];
        }

        $nextOptions = collect($workflowData['next_options'] ?? [])
            ->filter(fn (mixed $option): bool => is_array($option))
            ->map(fn (array $option): array => array_filter([
                'type' => $option['type'] ?? null,
                'instruction' => $option['instruction'] ?? null,
                'approval_key' => $option['approval_key'] ?? null,
                'field' => $option['field'] ?? null,
            ]))
            ->values()
            ->all();

        return array_filter([
            'action_id' => $actionId,
            'strategy' => $strategy,
            'label' => $action['label'] ?? null,
            'success' => $workflowData['success'] ?? null,
            'needs_user_input' => $workflowData['needs_user_input'] ?? null,
            'requires_confirmation' => $workflowData['requires_confirmation'] ?? null,
            'awaits_final_confirmation' => collect($nextOptions)->contains(
                fn (array $option): bool => ($option['type'] ?? null) === 'final_action_confirmation'
            ),
            'next_options' => $nextOptions,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
