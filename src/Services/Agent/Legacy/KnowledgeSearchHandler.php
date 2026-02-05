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
        // Provide context about previous list to AI for intelligent selection detection
        // The AI will determine if this is a selection and construct the appropriate query
        if (isset($context->metadata['last_entity_list'])) {
            $options['last_entity_list'] = $context->metadata['last_entity_list'];
            
            Log::channel('ai-engine')->info('Found last_entity_list in context metadata', [
                'entity_type' => $context->metadata['last_entity_list']['entity_type'] ?? 'unknown',
                'node' => $context->metadata['last_entity_list']['node'] ?? null,
                'count' => count($context->metadata['last_entity_list']['entity_ids'] ?? []),
            ]);
        } else {
            Log::channel('ai-engine')->info('No last_entity_list in context metadata', [
                'session_id' => $context->sessionId,
                'metadata_keys' => array_keys($context->metadata ?? []),
            ]);
        }

        // Check if this is a follow-up question with context
        $isFollowUp = $options['is_follow_up'] ?? false;
        $contextEntity = $options['context_entity'] ?? null;
        $conversationContext = $options['conversation_context'] ?? null;
        // If it's a follow-up, enhance the message with context
        $enhancedMessage = $message;
        if ($isFollowUp) {
            // Build enhanced message with context entity and/or conversation context
            if ($contextEntity) {
                $enhancedMessage = "Regarding {$contextEntity}: {$message}";
            }

            // If we have conversation context, append it as additional context for RAG
            if ($conversationContext) {
                $enhancedMessage = "{$conversationContext}\nCurrent question: {$enhancedMessage}";
            }

            Log::channel('ai-engine')->info('Enhanced follow-up query with context', [
                'original' => $message,
                'enhanced' => substr($enhancedMessage, 0, 200) . (strlen($enhancedMessage) > 200 ? '...' : ''),
                'context_entity' => $contextEntity,
                'has_conversation_context' => !empty($conversationContext),
            ]);
        }

        Log::channel('ai-engine')->info('Searching knowledge base', [
            'query' => $enhancedMessage,
            'original_query' => $message,
            'session_id' => $context->sessionId,
            'user_id' => $context->userId,
            'using_autonomous_agent' => $this->autonomousAgent !== null,
            'is_follow_up' => $isFollowUp,
            'context_entity' => $contextEntity,
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

            $agentOptions = array_merge($options, [
                'rag_collections' => $ragCollections,
                'context_entity' => $contextEntity,
                'is_follow_up' => $isFollowUp,
            ]);
            
            Log::channel('ai-engine')->info('Passing options to AutonomousRAGAgent', [
                'has_last_entity_list' => isset($agentOptions['last_entity_list']),
                'last_entity_list_node' => $agentOptions['last_entity_list']['node'] ?? null,
            ]);
            
            $result = $this->autonomousAgent->process(
                $enhancedMessage, // Use enhanced message with context for follow-ups
                $context->sessionId,
                $context->userId,
                $conversationHistory,
                $agentOptions
            );

            if ($result['success'] ?? false) {
                $responseText = $result['response'] ?? 'No results found.';

                Log::channel('ai-engine')->info('KnowledgeSearchHandler: AutonomousRAGAgent response', [
                    'tool' => $result['tool'] ?? 'unknown',
                    'fast_path' => $result['fast_path'] ?? false,
                ]);

                // Store metadata BEFORE creating response to ensure it's saved
                $context->metadata['tool_used'] = $result['tool'] ?? 'unknown';
                $context->metadata['fast_path'] = $result['fast_path'] ?? false;
                
                Log::channel('ai-engine')->info('AutonomousRAGAgent result keys', [
                    'keys' => array_keys($result),
                    'has_entity_ids' => isset($result['entity_ids']),
                    'has_node' => isset($result['node']),
                    'tool' => $result['tool'] ?? 'unknown',
                    'metadata_keys' => isset($result['metadata']) ? array_keys($result['metadata']) : [],
                ]);

                // Store entity IDs and data for follow-up selections
                if (isset($result['entity_ids']) && !empty($result['entity_ids'])) {
                    $context->metadata['last_entity_list'] = [
                        'entity_type' => $result['entity_type'] ?? 'item',
                        'entity_ids' => $result['entity_ids'],
                        'entity_data' => $result['entity_data'] ?? [], // Full entity data for selection
                        'node' => $result['node'] ?? null,
                        'timestamp' => now()->timestamp,
                    ];

                    Log::channel('ai-engine')->info('Stored entity list for follow-up selections', [
                        'entity_type' => $result['entity_type'] ?? 'item',
                        'count' => count($result['entity_ids']),
                        'entity_ids' => $result['entity_ids'],
                        'has_entity_data' => !empty($result['entity_data']),
                    ]);
                }

                $context->addAssistantMessage($responseText);

                $response = AgentResponse::conversational(
                    message: $responseText,
                    context: $context
                );

                // Add suggested actions if provided by the tool
                if (isset($result['suggested_actions']) && is_array($result['suggested_actions'])) {
                    foreach ($result['suggested_actions'] as $action) {
                        $response->addAction([
                            'type' => 'quick_reply',
                            'label' => $action['label'] ?? 'Action',
                            'data' => [
                                'reply' => $action['description'] ?? $action['label'] ?? 'Perform action',
                            ],
                        ]);
                    }
                }

                return $response;
            }

            // Fall through to legacy RAG if autonomous agent fails
            Log::channel('ai-engine')->warning('AutonomousRAGAgent failed, falling back to legacy RAG', [
                'error' => $result['error'] ?? 'unknown',
            ]);
        }

        // LEGACY PATH: Use IntelligentRAGService directly
        // FAST PATH: For aggregate queries, use smart aggregate directly
        if ($this->isAggregateQuery($enhancedMessage) && !empty($ragCollections)) {
            $aggregateData = $this->rag->getSmartAggregateData($ragCollections, $enhancedMessage, $context->userId);

            if (!empty($aggregateData)) {
                $responseText = $this->formatAggregateResponse($aggregateData);

                Log::channel('ai-engine')->info('KnowledgeSearchHandler: Fast aggregate path', [
                    'collections' => array_keys($aggregateData),
                    'is_follow_up' => $isFollowUp,
                ]);

                $response = AgentResponse::conversational(
                    message: $responseText,
                    context: $context
                );
                $context->metadata['fast_path'] = true;
                $context->metadata['aggregate_data'] = $aggregateData;
                $context->metadata['is_follow_up'] = $isFollowUp;
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
            'context_entity' => $contextEntity,
            'is_follow_up' => $isFollowUp,
        ];

        $aiResponse = $this->rag->processMessage(
            $enhancedMessage, // Use enhanced message with context
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
