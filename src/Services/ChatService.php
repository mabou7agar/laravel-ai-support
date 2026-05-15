<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChatService
{
    public function __construct(
        protected ConversationService $conversationService,
        protected AgentRuntimeContract $agentRuntime
    ) {}

    /**
     * Process a chat message and generate AI response
     *
     * @param string $message The user's message
     * @param string $sessionId Session identifier
     * @param string $engine AI engine to use
     * @param string $model AI model to use
     * @param bool $useMemory Enable conversation memory
     * @param bool $useActions Enable interactive actions
     * @param bool $useRag Enable RAG with access control
     * @param array $ragCollections RAG collections to search
     * @param string|int|null $userId User ID (fetched internally for access control)
     * @return AIResponse
     */
    public function processMessage(
        string $message,
        string $sessionId,
        string $engine = 'openai',
        string $model = 'gpt-4o-mini',
        bool $useMemory = true,
        bool $useActions = true,
        bool $useRag = true,
        array $ragCollections = [],
        $userId = null,
        ?string $searchInstructions = null,
        array $conversationHistory = [], // Passed from middleware for context-aware responses
        array $extraOptions = []
    ): AIResponse {
        Log::channel('ai-engine')->debug('ChatService::processMessage called', [
            'message' => substr($message, 0, 100),
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);

        // Load conversation history if memory is enabled
        $conversationId = null;
        if ($useMemory) {
            $conversationId = $this->conversationService->getOrCreateConversation(
                $sessionId,
                $userId,
                $engine,
                $model
            );

            // Use passed conversation history if available, otherwise load from DB
            if (empty($conversationHistory)) {
                $conversationHistory = $this->conversationService->getConversationHistory($sessionId, 50, $userId);
            }
        }

        $this->fireSessionStarted($sessionId, $userId, $engine, $model, $useMemory, $useActions, $useRag);

        // Delegate decisions to the configured agent runtime.
        Log::channel('ai-engine')->debug('ChatService delegating to agent runtime', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'runtime' => $this->agentRuntime->name(),
        ]);

        $options = $this->buildRuntimeOptions(
            $engine,
            $model,
            $useMemory,
            $useActions,
            $useRag,
            $ragCollections,
            $searchInstructions,
            $conversationHistory,
            $extraOptions
        );
        $agentResponse = $this->agentRuntime->process($message, $sessionId, $userId, $options);

        $this->updateSessionNode($sessionId, $agentResponse);

        return $this->toAIResponse($agentResponse, $engine, $model, $conversationId);
    }

    protected function buildRuntimeOptions(
        string $engine,
        string $model,
        bool $useMemory,
        bool $useActions,
        bool $useRag,
        array $ragCollections,
        ?string $searchInstructions,
        array $conversationHistory,
        array $extraOptions
    ): array {
        return array_merge([
            'engine' => $engine,
            'model' => $model,
            'use_memory' => $useMemory,
            'use_actions' => $useActions,
            'use_rag' => $useRag,
            'rag_collections' => $ragCollections,
            'search_instructions' => $searchInstructions,
            'conversation_history' => $conversationHistory,
            'is_forwarded' => $this->isForwardedRequest(),
        ], $extraOptions);
    }

    protected function fireSessionStarted(
        string $sessionId,
        mixed $userId,
        string $engine,
        string $model,
        bool $useMemory,
        bool $useActions,
        bool $useRag
    ): void {
        try {
            event(new AISessionStarted(
                sessionId: $sessionId,
                userId: $userId,
                engine: $engine,
                model: $model,
                metadata: ['memory' => $useMemory, 'actions' => $useActions, 'rag' => $useRag]
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to fire AISessionStarted event: ' . $e->getMessage());
        }
    }

    protected function updateSessionNode(string $sessionId, AgentResponse $agentResponse): void
    {
        $routedNode = $agentResponse->context?->toArray()['routed_to_node'] ?? null;

        if (!empty($routedNode)) {
            Cache::put(
                "session_node:{$sessionId}",
                $routedNode,
                now()->addHours(1)
            );

            return;
        }

        Cache::forget("session_node:{$sessionId}");
    }

    protected function toAIResponse(
        AgentResponse $agentResponse,
        string $engine,
        string $model,
        ?string $conversationId
    ): AIResponse {
        $context = $agentResponse->context;
        $contextData = $context?->toArray() ?? [];
        $contextMetadata = $context?->metadata ?? [];

        $entityTracking = [];
        if (isset($contextMetadata['last_entity_list']) && is_array($contextMetadata['last_entity_list'])) {
            $lastList = $contextMetadata['last_entity_list'];
            $entityTracking = [
                'entity_ids' => $lastList['entity_ids'] ?? null,
                'entity_type' => $lastList['entity_type'] ?? null,
            ];
        }

        return new AIResponse(
            content: $agentResponse->message,
            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
            model: \LaravelAIEngine\Enums\EntityEnum::from($model),
            metadata: array_merge($contextData, $agentResponse->metadata ?? [], $entityTracking, [
                'runtime_active' => !$agentResponse->isComplete,
                'runtime_data' => $agentResponse->data ?? [],
                'runtime_completed' => $agentResponse->isComplete,
                'agent_strategy' => $agentResponse->strategy,
                'needs_user_input' => $agentResponse->needsUserInput,
                'is_complete' => $agentResponse->isComplete,
                'next_step' => $agentResponse->nextStep,
                'required_inputs' => $agentResponse->requiredInputs,
            ]),
            success: $agentResponse->success,
            conversationId: $conversationId,
            actions: $agentResponse->actions ?? []
        );
    }

    /**
     * Check if this request was forwarded from another node
     * Prevents infinite forwarding loops
     */
    protected function isForwardedRequest(): bool
    {
        // Check for forwarded header or context flag
        $request = request();
        if ($request && $request->hasHeader('X-Forwarded-From-Node')) {
            return true;
        }

        return false;
    }
}
