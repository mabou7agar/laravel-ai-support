<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Services\Agent\AgentResponseConverter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChatService
{
    protected ?AgentResponseConverter $responseConverter = null;

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
     * Lazy load AgentResponseConverter
     */
    protected function getResponseConverter(): AgentResponseConverter
    {
        if ($this->responseConverter === null) {
            try {
                $this->responseConverter = app(AgentResponseConverter::class);
            } catch (\Throwable $e) {
                $this->responseConverter = new AgentResponseConverter();
            }
        }

        return $this->responseConverter;
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
        array $conversationHistory = []
    ): AIResponse {
        Log::channel('ai-engine')->info('ChatService::processMessage called', [
            'message' => substr($message, 0, 100),
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);

        // 1. Load conversation context
        $conversationId = $this->resolveConversation(
            $sessionId, $userId, $engine, $model, $useMemory, $conversationHistory
        );

        // 2. Fire session started event
        $this->fireSessionEvent($sessionId, $userId, $engine, $model, $useMemory, $useActions, $useIntelligentRAG);

        // 3. Delegate to orchestrator
        $options = [
            'engine' => $engine,
            'model' => $model,
            'use_memory' => $useMemory,
            'use_actions' => $useActions,
            'use_intelligent_rag' => $useIntelligentRAG,
            'rag_collections' => $ragCollections,
            'search_instructions' => $searchInstructions,
            'conversation_history' => $conversationHistory,
            'is_forwarded' => $this->isForwardedRequest(),
        ];

        $agentResponse = $this->getOrchestrator()->process($message, $sessionId, $userId, $options);

        // 4. Track workflow session state
        $this->trackWorkflowSession($sessionId, $agentResponse);

        // 5. Convert AgentResponse → AIResponse
        return $this->getResponseConverter()->convert(
            $agentResponse, $engine, $model, $conversationId
        );
    }

    /**
     * Resolve conversation ID and load history if memory is enabled.
     * Populates $conversationHistory by reference when loading from DB.
     */
    protected function resolveConversation(
        string $sessionId,
        $userId,
        string $engine,
        string $model,
        bool $useMemory,
        array &$conversationHistory
    ): ?string {
        if (!$useMemory) {
            return null;
        }

        $conversationId = $this->conversationService->getOrCreateConversation(
            $sessionId, $userId, $engine, $model
        );

        if (empty($conversationHistory)) {
            $conversationHistory = $this->conversationService->getConversationHistory($sessionId, 50, $userId);
        }

        return $conversationId;
    }

    /**
     * Fire the AISessionStarted event (non-critical).
     */
    protected function fireSessionEvent(
        string $sessionId,
        $userId,
        string $engine,
        string $model,
        bool $useMemory,
        bool $useActions,
        bool $useIntelligentRAG
    ): void {
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
    }

    /**
     * Track workflow session state in cache for cross-request continuity.
     */
    protected function trackWorkflowSession(string $sessionId, \LaravelAIEngine\DTOs\AgentResponse $agentResponse): void
    {
        if (!empty($agentResponse->context->currentWorkflow)) {
            Cache::put(
                "session_node:{$sessionId}",
                $agentResponse->context->toArray()['routed_to_node'] ?? null,
                now()->addHours(1)
            );
        } else {
            Cache::forget("session_node:{$sessionId}");
        }
    }

    /**
     * Check if this request was forwarded from another node.
     * Prevents infinite forwarding loops.
     */
    protected function isForwardedRequest(): bool
    {
        $request = request();
        if ($request && $request->hasHeader('X-Forwarded-From-Node')) {
            return true;
        }

        return false;
    }
}
