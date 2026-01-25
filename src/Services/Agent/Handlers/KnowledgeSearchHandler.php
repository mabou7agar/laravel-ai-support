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
        
        // FAST PATH: For aggregate queries, use smart aggregate directly
        if ($this->isAggregateQuery($message) && !empty($ragCollections)) {
            $aggregateData = $this->rag->getSmartAggregateData($ragCollections, $message, $context->userId);
            
            if (!empty($aggregateData)) {
                $responseText = $this->formatAggregateResponse($aggregateData);
                
                Log::channel('ai-engine')->info('KnowledgeSearchHandler: Fast aggregate path', [
                    'collections' => array_keys($aggregateData),
                ]);
                
                $response = AgentResponse::conversational(
                    message: $responseText,
                    context: $context
                );
                $context->metadata['fast_path'] = true;
                $context->metadata['aggregate_data'] = $aggregateData;
                $context->addAssistantMessage($responseText);
                return $response;
            }
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
    
    protected function isAggregateQuery(string $message): bool
    {
        $query = strtolower($message);
        $patterns = ['how many', 'how much', 'count', 'total', 'number of'];
        
        foreach ($patterns as $pattern) {
            if (str_contains($query, $pattern)) {
                return true;
            }
        }
        return false;
    }
    
    protected function formatAggregateResponse(array $aggregateData): string
    {
        $parts = [];
        foreach ($aggregateData as $name => $data) {
            $count = $data['count'] ?? 0;
            $displayName = $data['display_name'] ?? $name;
            $filters = $data['filters_applied'] ?? [];
            
            if ($count > 0) {
                $filterStr = "";
                if (!empty($filters['created_at'])) {
                    $dateFilter = $filters['created_at'];
                    if (is_array($dateFilter)) {
                        $filterStr = " (from {$dateFilter['gte']} to {$dateFilter['lte']})";
                    } else {
                        $filterStr = " (on {$dateFilter})";
                    }
                }
                $parts[] = "**{$count}** {$displayName}(s){$filterStr}";
            }
        }
        
        return !empty($parts) 
            ? "Based on your data:\n- " . implode("\n- ", $parts)
            : "No matching records found.";
    }
}
