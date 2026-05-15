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

    public function finalize(UnifiedActionContext $context, AgentResponse $response): AgentResponse
    {
        $response = $this->traceMetadata->enrichResponse(
            $response,
            $this->traceMetadata->responseMetadataFromContext($context->metadata ?? [])
        );

        $metadata = $this->traceMetadata->responseMetadataFromContext($response->metadata ?? []);
        if (!empty($response->metadata['entity_ids'])) {
            $metadata['entity_ids'] = $response->metadata['entity_ids'];
            $metadata['entity_type'] = $response->metadata['entity_type'] ?? 'item';
            unset($context->metadata['selected_entity_context']);
        }

        if (isset($response->metadata['flow_data']) && is_array($response->metadata['flow_data'])) {
            $skillFlow = $this->skillFlowBrief($response->metadata['flow_data'], $response->strategy);
            if ($skillFlow !== []) {
                if (($skillFlow['status'] ?? null) === 'completed') {
                    unset($context->metadata['last_skill_flow']);
                } else {
                    $context->metadata['last_skill_flow'] = $skillFlow;
                }

                $metadata['skill_flow'] = $skillFlow;
            }

            $flow = $this->actionFlowBrief($response->metadata['flow_data'], $response->strategy);
            if ($flow !== []) {
                $context->metadata['last_action_flow'] = $flow;
                $metadata['action_flow'] = $flow;
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
     * @param array<string, mixed> $flowData
     * @return array<string, mixed>
     */
    protected function actionFlowBrief(array $flowData, ?string $strategy): array
    {
        if (!isset($flowData['action'], $flowData['draft']) && is_array($flowData['data'] ?? null)) {
            $flowData = array_merge($flowData, $flowData['data']);
        }

        $action = is_array($flowData['action'] ?? null) ? $flowData['action'] : [];
        $draft = is_array($flowData['draft'] ?? null) ? $flowData['draft'] : [];
        $actionId = (string) (
            $flowData['action_id']
            ?? $draft['action_id']
            ?? $action['id']
            ?? ''
        );

        if ($actionId === '') {
            return [];
        }

        $nextOptions = collect($flowData['next_options'] ?? [])
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
            'success' => $flowData['success'] ?? null,
            'needs_user_input' => $flowData['needs_user_input'] ?? null,
            'requires_confirmation' => $flowData['requires_confirmation'] ?? null,
            'awaits_final_confirmation' => collect($nextOptions)->contains(
                fn (array $option): bool => ($option['type'] ?? null) === 'final_action_confirmation'
            ),
            'next_options' => $nextOptions,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param array<string, mixed> $flowData
     * @return array<string, mixed>
     */
    protected function skillFlowBrief(array $flowData, ?string $strategy): array
    {
        if (!isset($flowData['skill_id']) && is_array($flowData['data'] ?? null)) {
            $flowData = array_merge($flowData, $flowData['data']);
        }

        $skillId = (string) ($flowData['skill_id'] ?? '');
        if ($skillId === '') {
            return [];
        }

        return array_filter([
            'skill_id' => $skillId,
            'skill_name' => $flowData['skill_name'] ?? null,
            'strategy' => $strategy,
            'status' => $flowData['status'] ?? 'collecting',
            'pending_tool' => $flowData['pending_tool'] ?? null,
            'payload' => $flowData['payload'] ?? null,
            'target_json' => $flowData['target_json'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
