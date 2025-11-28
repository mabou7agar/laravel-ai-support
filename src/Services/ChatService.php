<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public function __construct(
        protected ConversationService $conversationService,
        protected AIEngineService $aiEngineService,
        protected MemoryOptimizationService $memoryOptimization,
        protected DynamicActionService $dynamicActionService,
        protected ?IntelligentRAGService $intelligentRAG = null
    ) {
        // Lazy load IntelligentRAGService if available
        if ($this->intelligentRAG === null && app()->bound(IntelligentRAGService::class)) {
            $this->intelligentRAG = app(IntelligentRAGService::class);
        }
    }

    /**
     * Process a chat message and generate AI response
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
        ?string $userId = null
    ): AIResponse {
        // Create AI request
        $aiRequest = Engine::createRequest(
            prompt: $message,
            engine: $engine,
            model: $model,
            maxTokens: 1000,
            temperature: 0.7,
            systemPrompt: $this->getSystemPrompt($useActions)
        );

        // Load conversation history if memory is enabled
        if ($useMemory) {
            $conversationId = $this->conversationService->getOrCreateConversation(
                $sessionId,
                $userId,
                $engine,
                $model
            );
            
            $aiRequest->setConversationId($conversationId);
            
            // Load and attach optimized conversation history (with caching)
            $messages = $this->memoryOptimization->getOptimizedHistory($conversationId, 20);
            
            if (!empty($messages)) {
                $aiRequest = $aiRequest->withMessages($messages);
                
                if (config('ai-engine.debug')) {
                    Log::channel('ai-engine')->debug('Conversation history loaded', [
                        'conversation_id' => $conversationId,
                        'message_count' => count($messages),
                    ]);
                }
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

        // Use Intelligent RAG if enabled and available
        if ($useIntelligentRAG && $this->intelligentRAG !== null && !empty($ragCollections)) {
            try {
                $conversationHistory = !empty($messages) ? $messages : [];
                
                $response = $this->intelligentRAG->processMessage(
                    $message,
                    $sessionId,
                    $ragCollections,
                    $conversationHistory,
                    [
                        'engine' => $engine,
                        'model' => $model,
                        'max_tokens' => 2000,
                    ]
                );
                
                if (config('ai-engine.debug')) {
                    Log::channel('ai-engine')->debug('Intelligent RAG used', [
                        'has_sources' => !empty($response->getMetadata()['sources'] ?? []),
                        'source_count' => count($response->getMetadata()['sources'] ?? []),
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Intelligent RAG failed, falling back to regular response', [
                    'error' => $e->getMessage(),
                ]);
                
                // Fallback to regular response
                $response = $this->aiEngineService->generate($aiRequest);
            }
        } else {
            // Generate regular AI response
            $response = $this->aiEngineService->generate($aiRequest);
        }

        // Save to conversation memory if enabled
        if ($useMemory && isset($conversationId)) {
            try {
                $this->conversationService->saveMessages(
                    $conversationId,
                    $message,
                    $response
                );
                
                // Invalidate cache so next request gets fresh data
                $this->memoryOptimization->invalidateCache($conversationId);
                
                if (config('ai-engine.debug')) {
                    Log::channel('ai-engine')->debug('Conversation saved', [
                        'conversation_id' => $conversationId,
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Failed to save conversation', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                    'trace' => config('app.debug') ? $e->getTraceAsString() : null,
                ]);
            }
        }

        return $response;
    }

    /**
     * Get system prompt based on configuration
     */
    protected function getSystemPrompt(bool $useActions): string
    {
        $prompt = "You are a helpful AI assistant. Provide clear, accurate, and helpful responses to user questions.";

        if ($useActions) {
            $prompt .= " When appropriate, suggest follow-up actions or questions that might be helpful to the user.";
            
            // Add available actions context
            try {
                $availableActions = $this->dynamicActionService->discoverActions();
                if (!empty($availableActions)) {
                    $prompt .= "\n\nYou have access to the following actions in this system:\n";
                    foreach (array_slice($availableActions, 0, 10) as $action) {
                        $prompt .= "- {$action['label']}: {$action['description']}\n";
                        if (isset($action['endpoint'])) {
                            $prompt .= "  API: {$action['method']} {$action['endpoint']}\n";
                        }
                    }
                    $prompt .= "\nWhen users ask to perform these actions, you can recommend them and provide the necessary details.";
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load dynamic actions: ' . $e->getMessage());
            }
        }

        return $prompt;
    }
}
