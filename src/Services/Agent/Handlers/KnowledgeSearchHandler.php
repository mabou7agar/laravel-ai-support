<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\RAG\AutonomousRAGAgent;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

/**
 * Searches knowledge base using RAG
 * Uses AutonomousRAGAgent for intelligent routing with filter support
 */
class KnowledgeSearchHandler implements MessageHandlerInterface
{
    protected ?AutonomousRAGAgent $autonomousAgent = null;

    public function __construct(
        protected AIEngineService $ai,
        protected IntelligentRAGService $rag,
        protected ?\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery $ragDiscovery = null
    ) {
        if ($this->ragDiscovery === null && app()->bound(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class)) {
            $this->ragDiscovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
        }
        
        // Initialize AutonomousRAGAgent for intelligent routing
        if (app()->bound(\LaravelAIEngine\Services\AIEngineManager::class)) {
            $this->autonomousAgent = new AutonomousRAGAgent(
                app(\LaravelAIEngine\Services\AIEngineManager::class),
                $this->rag,
                $this->ragDiscovery
            );
        }
    }

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Searching knowledge base', [
            'query' => $message,
            'session_id' => $context->sessionId,
            'using_autonomous_agent' => $this->autonomousAgent !== null,
        ]);

        $ragCollections = $options['rag_collections'] ?? [];

        // Auto-discover collections if not provided
        if (empty($ragCollections) && $this->ragDiscovery) {
            $ragCollections = $this->ragDiscovery->discover();
            Log::channel('ai-engine')->info('KnowledgeSearchHandler: Auto-discovered collections', [
                'count' => count($ragCollections),
                'collections' => array_map(fn($c) => class_basename($c), $ragCollections),
            ]);
        } else if (empty($ragCollections)) {
            Log::channel('ai-engine')->warning('KnowledgeSearchHandler: No collections available and discovery service not bound', [
                'message' => $message,
                'session_id' => $context->sessionId,
            ]);
        }

        // Use AutonomousRAGAgent for intelligent routing with filter support
        if ($this->autonomousAgent) {
            $conversationHistory = $context->conversationHistory ?? [];
            
            $result = $this->autonomousAgent->process(
                $message,
                $context->sessionId,
                $context->userId,
                $conversationHistory,
                array_merge($options, ['rag_collections' => $ragCollections])
            );
            
            if ($result['success'] ?? false) {
                $responseText = $result['response'] ?? 'No results found.';
                
                Log::channel('ai-engine')->info('KnowledgeSearchHandler: AutonomousRAGAgent response', [
                    'tool' => $result['tool'] ?? 'unknown',
                    'fast_path' => $result['fast_path'] ?? false,
                ]);
                
                $response = AgentResponse::conversational(
                    message: $responseText,
                    context: $context
                );
                
                $context->metadata['tool_used'] = $result['tool'] ?? 'unknown';
                $context->metadata['fast_path'] = $result['fast_path'] ?? false;
                $context->addAssistantMessage($responseText);
                
                return $response;
            }
            
            // Fall through to legacy RAG if autonomous agent fails
            Log::channel('ai-engine')->warning('AutonomousRAGAgent failed, falling back to legacy RAG', [
                'error' => $result['error'] ?? 'unknown',
            ]);
        }

        // LEGACY PATH: Use IntelligentRAGService directly
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
