<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

/**
 * Searches knowledge base using RAG
 * No ChatService dependency - uses RAG and AI directly
 */
class KnowledgeSearchHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AIEngineService $ai,
        protected IntelligentRAGService $rag,
        protected ?\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery $ragDiscovery = null
    ) {
        if ($this->ragDiscovery === null && app()->bound(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class)) {
            $this->ragDiscovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
        }
    }

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Searching knowledge base with RAG', [
            'query' => $message,
            'session_id' => $context->sessionId,
        ]);
        
        // Use IntelligentRAGService to process message with RAG
        $ragCollections = $options['rag_collections'] ?? [];
        
        // Auto-discover collections if not provided
        if (empty($ragCollections) && $this->ragDiscovery) {
            $ragCollections = $this->ragDiscovery->discover();
            Log::channel('ai-engine')->info('KnowledgeSearchHandler: Auto-discovered collections', [
                'count' => count($ragCollections),
            ]);
        }
        
        $conversationHistory = $context->conversationHistory ?? [];
        
        $ragOptions = [
            'engine' => $options['engine'] ?? 'openai',
            'model' => $options['model'] ?? 'gpt-4o-mini',
            'max_tokens' => 1000,
            'search_instructions' => $options['search_instructions'] ?? null,
        ];
        
        $aiResponse = $this->rag->processMessage(
            $message,
            $context->sessionId,
            $ragCollections,
            $conversationHistory,
            $ragOptions,
            $context->userId
        );
        
        // Convert AIResponse to AgentResponse
        $response = AgentResponse::conversational(
            message: $aiResponse->content,
            context: $context
        );
        
        // Add RAG metadata to context
        $context->metadata = array_merge($context->metadata ?? [], $aiResponse->getMetadata(), [
            'used_rag' => true,
            'rag_sources' => $aiResponse->getMetadata()['sources'] ?? [],
        ]);
        
        $context->addAssistantMessage($aiResponse->content);
        
        return $response;
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'search_knowledge';
    }
}
