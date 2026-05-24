<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\ChatService;
use Throwable;

class RealtimeToolBrokerService
{
    public function __construct(
        protected ToolRegistry $tools
    ) {
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function dispatch(array $event, UnifiedActionContext $context, bool $approved = false): array
    {
        $call = $this->normalizeToolCall($event);
        $toolName = $call['name'];
        $tool = $this->tools->get($toolName);
        $arguments = $call['arguments'];

        if ($tool === null && ($skillId = RealtimeToolName::skillIdFromName($toolName)) !== null) {
            $tool = $this->tools->get('run_skill');
            $arguments = array_replace($arguments, ['skill_id' => $skillId]);
        }

        if ($tool === null && $toolName === 'agent_chat') {
            return $this->dispatchAgentChat($call, $context);
        }

        if ($tool === null) {
            return [
                'success' => false,
                'status' => 'tool_not_found',
                'tool_call_id' => $call['id'],
                'tool_name' => $toolName,
                'error' => "Realtime tool [{$toolName}] is not registered.",
            ];
        }

        if ($tool->requiresConfirmation() && !$approved) {
            return [
                'success' => false,
                'status' => 'approval_required',
                'tool_call_id' => $call['id'],
                'tool_name' => $toolName,
                'message' => $tool->getConfirmationMessage() ?? "Approve realtime tool [{$toolName}] before execution.",
            ];
        }

        try {
            $result = $tool->execute($arguments, $context);
            $output = $result->toArray();
            $needsUserInput = (bool) ($output['metadata']['needs_user_input'] ?? false);

            return [
                'success' => $result->success,
                'status' => $result->success ? 'completed' : ($needsUserInput ? 'needs_user_input' : 'failed'),
                'tool_call_id' => $call['id'],
                'tool_name' => $toolName,
                'message' => $result->message ?? $result->error,
                'output' => $output,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'failed',
                'tool_call_id' => $call['id'],
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array{id:string|null,name:string,arguments:array<string,mixed>}
     */
    protected function normalizeToolCall(array $event): array
    {
        $functionCall = $event['toolCall']['functionCalls'][0] ?? $event['functionCall'] ?? $event;
        $arguments = $functionCall['arguments'] ?? $functionCall['args'] ?? [];

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => isset($functionCall['call_id']) ? (string) $functionCall['call_id'] : (isset($functionCall['id']) ? (string) $functionCall['id'] : null),
            'name' => (string) ($functionCall['name'] ?? $functionCall['function']['name'] ?? ''),
            'arguments' => is_array($arguments) ? $arguments : [],
        ];
    }

    /**
     * @param array{id:string|null,name:string,arguments:array<string,mixed>} $call
     * @return array<string, mixed>
     */
    protected function dispatchAgentChat(array $call, UnifiedActionContext $context): array
    {
        $arguments = $call['arguments'];
        $message = trim((string) ($arguments['message'] ?? $arguments['transcript'] ?? ''));

        if ($message === '') {
            return [
                'success' => false,
                'status' => 'needs_user_input',
                'tool_call_id' => $call['id'],
                'tool_name' => 'agent_chat',
                'message' => 'Please provide a message to send to AgentChat.',
                'output' => [
                    'success' => false,
                    'message' => 'Please provide a message to send to AgentChat.',
                    'metadata' => ['needs_user_input' => true, 'required_inputs' => ['message']],
                ],
            ];
        }

        try {
            $response = app(ChatService::class)->processMessage(
                message: $message,
                sessionId: (string) ($arguments['session_id'] ?? $context->sessionId),
                engine: (string) ($arguments['engine'] ?? $context->metadata['engine'] ?? config('ai-engine.default', 'openai')),
                model: (string) ($arguments['model'] ?? $context->metadata['model'] ?? config('ai-engine.default_model', 'gpt-4o-mini')),
                useMemory: (bool) ($arguments['memory'] ?? $arguments['use_memory'] ?? $context->metadata['memory'] ?? true),
                useActions: (bool) ($arguments['actions'] ?? $arguments['use_actions'] ?? $context->metadata['actions'] ?? true),
                useRag: (bool) ($arguments['use_rag'] ?? $context->metadata['use_rag'] ?? false),
                ragCollections: (array) ($arguments['rag_collections'] ?? $context->metadata['rag_collections'] ?? []),
                userId: $arguments['user_id'] ?? $context->userId,
                searchInstructions: isset($arguments['search_instructions']) ? (string) $arguments['search_instructions'] : ($context->metadata['search_instructions'] ?? null),
                conversationHistory: (array) ($arguments['conversation_history'] ?? []),
                extraOptions: array_filter([
                    'response_points_format' => $arguments['response_points_format'] ?? $context->metadata['response_points_format'] ?? 'text',
                    'response_suggestions' => $arguments['response_suggestions'] ?? $context->metadata['response_suggestions'] ?? true,
                    'response_suggestion_limit' => $arguments['response_suggestion_limit'] ?? $context->metadata['response_suggestion_limit'] ?? null,
                    'realtime' => true,
                ], static fn (mixed $value): bool => $value !== null)
            );

            $metadata = $response->getMetadata();
            $needsUserInput = (bool) ($metadata['needs_user_input'] ?? false);
            $success = $response->isSuccessful();

            return [
                'success' => $success && !$needsUserInput,
                'status' => $success ? ($needsUserInput ? 'needs_user_input' : 'completed') : 'failed',
                'tool_call_id' => $call['id'],
                'tool_name' => 'agent_chat',
                'message' => $response->getContent() ?: $response->getError(),
                'output' => [
                    'success' => $success,
                    'message' => $response->getContent(),
                    'content' => $response->getContent(),
                    'metadata' => $metadata,
                    'response' => $response->toArray(),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'failed',
                'tool_call_id' => $call['id'],
                'tool_name' => 'agent_chat',
                'error' => $e->getMessage(),
            ];
        }
    }
}
