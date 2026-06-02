<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Services\Agent\ChatResponsePresentationService;
use LaravelAIEngine\Services\Agent\StructuredCollectionSessionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChatService
{
    public function __construct(
        protected ConversationTranscriptService $conversationTranscripts,
        protected AgentRuntimeContract $agentRuntime,
        protected ?ChatResponsePresentationService $responsePresentation = null,
        protected ?StructuredCollectionSessionService $collectionSessions = null
    ) {
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

        // Load transcript history when enabled. Durable extracted memories are handled by the agent runtime.
        $conversationId = null;
        if ($useMemory) {
            $conversationId = $this->conversationTranscripts->getOrCreateConversation(
                $sessionId,
                $userId,
                $engine,
                $model
            );

            // Use passed conversation history if available, otherwise load from DB
            if (empty($conversationHistory)) {
                $conversationHistory = $this->conversationTranscripts->getConversationHistory($sessionId, 50, $userId);
            }
        }

        $this->fireSessionStarted($sessionId, $userId, $engine, $model, $useMemory, $useActions, $useRag);

        $options = $this->buildRuntimeOptions(
            $engine,
            $model,
            $useMemory,
            $useActions,
            $useRag,
            $ragCollections,
            $searchInstructions,
            $conversationHistory,
            $extraOptions,
            $conversationId
        );

        $collectionResponse = $this->structuredCollections()->handle($message, $sessionId, $userId, $options);
        if ($collectionResponse instanceof AIResponse) {
            if ($conversationId !== null) {
                $collectionResponse = $collectionResponse->withConversationId($conversationId);
            }

            // Attach a synthetic routing trace so a synchronous response is never missing routing_trace.
            $collectionResponse = $collectionResponse->withMetadata(
                $this->structuredCollectionRoutingTrace()
            );

            $persisted = $this->persistTranscriptTurn($conversationId, $message, $collectionResponse);
            $collectionResponse = $collectionResponse->withMetadata(['transcript_persisted' => $persisted]);

            return $collectionResponse;
        }

        // Delegate decisions to the configured agent runtime.
        Log::channel('ai-engine')->debug('ChatService delegating to agent runtime', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'runtime' => $this->agentRuntime->name(),
        ]);

        $agentResponse = $this->agentRuntime->process($message, $sessionId, $userId, $options);

        $this->updateSessionNode($sessionId, $agentResponse);

        $response = $this->toAIResponse($agentResponse, $engine, $model, $conversationId);
        // Apply presentation before persisting so the stored transcript matches the returned response.
        $response = $this->presentation()->apply($response, $message, $options, $agentResponse->context);
        $persisted = $this->persistTranscriptTurn($conversationId, $message, $response);
        $response = $response->withMetadata(['transcript_persisted' => $persisted]);

        return $response;
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
        array $extraOptions,
        ?string $conversationId = null
    ): array {
        return array_merge([
            'engine' => $engine,
            'model' => $model,
            'use_memory' => $useMemory,
            'use_transcript_history' => $useMemory,
            'use_conversation_memory' => $useMemory,
            'use_actions' => $useActions,
            'use_rag' => $useRag,
            'rag_collections' => $ragCollections,
            'search_instructions' => $searchInstructions,
            'conversation_history' => $conversationHistory,
            'conversation_id' => $conversationId,
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

    protected function persistTranscriptTurn(?string $conversationId, string $message, AIResponse $response): bool
    {
        if ($conversationId === null) {
            return true;
        }

        try {
            $this->conversationTranscripts->saveMessages($conversationId, $message, $response);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to persist conversation transcript turn: ' . $e->getMessage());

            return false;
        }
    }

    protected function presentation(): ChatResponsePresentationService
    {
        return $this->responsePresentation ??= app(ChatResponsePresentationService::class);
    }

    protected function structuredCollections(): StructuredCollectionSessionService
    {
        return $this->collectionSessions ??= app(StructuredCollectionSessionService::class);
    }

    /**
     * Build a synthetic routing trace for the structured-collection short-circuit so the
     * returned response carries routing_decision/routing_trace/route_explanation like every
     * other synchronous response (a missing routing_trace is treated as a regression).
     *
     * @return array<string, mixed>
     */
    protected function structuredCollectionRoutingTrace(): array
    {
        $decision = [
            'action' => 'structured_collection',
            'source' => 'structured_collection',
            'confidence' => 1.0,
            'reason' => 'Handled by structured collection session.',
            'payload' => [],
            'metadata' => [],
        ];

        return [
            'routing_decision' => $decision,
            'routing_trace' => [$decision],
            'route_explanation' => 'Request handled by the structured collection session service.',
        ];
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
            metadata: array_merge($contextData, $contextMetadata, $agentResponse->metadata ?? [], $entityTracking, [
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
