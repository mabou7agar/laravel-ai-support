<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Events\AISessionStarted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChatService
{
    public function __construct(
        protected ConversationService $conversationService,
        protected ?\LaravelAIEngine\Services\Agent\MinimalAIOrchestrator $orchestrator = null
    ) {}

    /**
     * Lazy load MinimalAIOrchestrator
     */
    protected function getOrchestrator(): \LaravelAIEngine\Services\Agent\MinimalAIOrchestrator
    {
        if ($this->orchestrator === null) {
            $this->orchestrator = app(\LaravelAIEngine\Services\Agent\MinimalAIOrchestrator::class);
        }

        return $this->orchestrator;
    }

    /**
     * Process a chat message and generate AI response
     *
     * @param string $message The user's message
     * @param string $sessionId Session identifier
     * @param string $engine AI engine to use
     * @param string $model AI model to use
     * @param bool $useMemory Enable conversation memory
     * @param bool $useActions Enable interactive actions
     * @param bool $useIntelligentRAG Enable RAG with access control
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
        bool $useIntelligentRAG = true,
        array $ragCollections = [],
        $userId = null,
        ?string $searchInstructions = null,
        array $conversationHistory = [] // Passed from middleware for context-aware responses
    ): AIResponse {
        Log::channel('ai-engine')->info('ChatService::processMessage called', [
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
                $conversationHistory = $this->conversationService->getConversationHistory($sessionId);
            }
        }

        // Fire session started event
        try {
            event(new AISessionStarted(
                sessionId: $sessionId,
                userId: $userId,
                engine: $engine,
                model: $model,
                metadata: ['memory' => $useMemory, 'actions' => $useActions, 'intelligent_rag' => $useIntelligentRAG]
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to fire AISessionStarted event: ' . $e->getMessage());
        }

        // Delegate ALL decisions to MinimalAIOrchestrator (AI-driven)
        $orchestrator = $this->getOrchestrator();

        Log::channel('ai-engine')->info('ChatService delegating to MinimalAIOrchestrator', [
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);

        // Pass all context to orchestrator for AI-driven decisions
        $options = [
            'engine' => $engine,
            'model' => $model,
            'use_memory' => $useMemory,
            'use_actions' => $useActions,
            'use_intelligent_rag' => $useIntelligentRAG,
            'rag_collections' => $ragCollections,
            'search_instructions' => $searchInstructions,
            'conversation_history' => $conversationHistory,
            'is_forwarded' => $this->isForwardedRequest(), // Prevent infinite forwarding loops
        ];

        $agentResponse = $orchestrator->process($message, $sessionId, $userId, $options);

        // Handle workflow session tracking
        if (!empty($agentResponse->context->currentWorkflow)) {
            Cache::put(
                "session_node:{$sessionId}",
                $agentResponse->context->toArray()['routed_to_node'] ?? null,
                now()->addHours(1)
            );
        } else {
            Cache::forget("session_node:{$sessionId}");
        }

        // Convert AgentResponse to AIResponse
        $contextMetadata = array_merge(
            $agentResponse->context->toArray(),
            $agentResponse->metadata ?? []
        );

        // Extract entity_ids from last_entity_list for node routing
        $entityTracking = [];
        if (isset($agentResponse->context->metadata['last_entity_list'])) {
            $lastList = $agentResponse->context->metadata['last_entity_list'];
            $entityTracking = [
                'entity_ids' => $lastList['entity_ids'] ?? null,
                'entity_type' => $lastList['entity_type'] ?? null,
            ];
        }

        return new AIResponse(
            content: $agentResponse->message,
            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
            model: \LaravelAIEngine\Enums\EntityEnum::from($model),
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
