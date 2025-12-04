<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public function __construct(
        protected ConversationService $conversationService,
        protected AIEngineService $aiEngineService,
        protected MemoryOptimizationService $memoryOptimization,
        protected DynamicActionService $dynamicActionService,
        protected ?IntelligentRAGService $intelligentRAG = null,
        protected ?RAGCollectionDiscovery $ragDiscovery = null
    ) {
        // Lazy load IntelligentRAGService if available
        if ($this->intelligentRAG === null && app()->bound(IntelligentRAGService::class)) {
            $this->intelligentRAG = app(IntelligentRAGService::class);
        }
        
        // Lazy load RAGCollectionDiscovery if available
        if ($this->ragDiscovery === null && app()->bound(RAGCollectionDiscovery::class)) {
            $this->ragDiscovery = app(RAGCollectionDiscovery::class);
        }
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
        $userId = null
    ): AIResponse {
        // Preprocess message to detect numbered selections
        $processedMessage = $this->preprocessMessage($message, $sessionId, $useMemory);
        
        // Create AI request with user context
        $aiRequest = Engine::createRequest(
            prompt: $processedMessage,
            engine: $engine,
            model: $model,
            maxTokens: 1000,
            temperature: 0.7,
            systemPrompt: $this->getSystemPrompt($useActions, $userId)
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
        Log::info('ChatService processMessage', [
            'useIntelligentRAG' => $useIntelligentRAG,
            'intelligentRAG_available' => $this->intelligentRAG !== null,
            'message' => substr($message, 0, 50),
            'ragCollections_passed' => $ragCollections,
            'ragCollections_count' => count($ragCollections),
        ]);
        
        if ($useIntelligentRAG && $this->intelligentRAG !== null) {
            try {
                // Auto-discover collections ONLY if not provided
                // If user passes specific collections, respect them strictly
                if (empty($ragCollections) && $this->ragDiscovery !== null) {
                    $ragCollections = $this->ragDiscovery->discover();
                    
                    Log::channel('ai-engine')->info('Auto-discovered RAG collections (none passed)', [
                        'collections' => $ragCollections,
                        'count' => count($ragCollections),
                    ]);
                } else {
                    Log::channel('ai-engine')->info('Using user-passed RAG collections', [
                        'collections' => $ragCollections,
                        'count' => count($ragCollections),
                    ]);
                }
                
                $conversationHistory = !empty($messages) ? $messages : [];
                
                // SECURITY: Pass userId for multi-tenant access control
                $response = $this->intelligentRAG->processMessage(
                    $message,
                    $sessionId,
                    $ragCollections,
                    $conversationHistory,
                    [
                        'engine' => $engine,
                        'model' => $model,
                        'max_tokens' => 2000,
                    ],
                    $userId // CRITICAL: User ID for access control (fetched internally)
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
    protected function getSystemPrompt(bool $useActions, $userId = null): string
    {
        $prompt = "You are a helpful AI assistant. Provide clear, accurate, and helpful responses to user questions.";
        
        // Add user context if authenticated
        if ($userId && config('ai-engine.inject_user_context', true)) {
            $userContext = $this->getUserContext($userId);
            if ($userContext) {
                $prompt .= "\n\n" . $userContext;
            }
        }
        
        // Add numbered selection handling
        $prompt .= "\n\nIMPORTANT: When you provide numbered lists or options:";
        $prompt .= "\n- If the user responds with JUST a number (like '1', '2', etc.), they are selecting that option from your previous response";
        $prompt .= "\n- Look at your previous message and expand on the selected option";
        $prompt .= "\n- For example, if you listed '1. Introduction to Laravel' and user says '1', provide detailed information about introducing Laravel";
        $prompt .= "\n- NEVER say the question is incomplete when user sends a number - they're making a selection!";

        if ($useActions) {
            $prompt .= "\n\nWhen appropriate, suggest follow-up actions or questions that might be helpful to the user.";
            
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
    
    /**
     * Preprocess message to detect numbered selections
     */
    protected function preprocessMessage(string $message, string $sessionId, bool $useMemory): string
    {
        // Check if message is just a number (numbered selection)
        if (preg_match('/^\s*(\d+)\s*$/', trim($message), $matches)) {
            $selectedNumber = $matches[1];
            
            // Try to get the last assistant message to find context
            if ($useMemory) {
                try {
                    $conversationId = $this->conversationService->getOrCreateConversation(
                        $sessionId,
                        null,
                        'openai',
                        'gpt-4o-mini'
                    );
                    
                    $messages = $this->memoryOptimization->getOptimizedHistory($conversationId, 5);
                    
                    // Find the last assistant message
                    $lastAssistantMessage = null;
                    for ($i = count($messages) - 1; $i >= 0; $i--) {
                        if (($messages[$i]['role'] ?? '') === 'assistant') {
                            $lastAssistantMessage = $messages[$i]['content'] ?? '';
                            break;
                        }
                    }
                    
                    // If we found a message with numbered list, extract the selected option
                    if ($lastAssistantMessage && preg_match('/^\s*' . $selectedNumber . '\.\s+(.+?)(?:\n|$)/m', $lastAssistantMessage, $optionMatch)) {
                        $selectedOption = trim($optionMatch[1]);
                        
                        // Enrich the message with context
                        return "I'm selecting option {$selectedNumber}: \"{$selectedOption}\". Please provide more details about this.";
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to preprocess numbered selection: ' . $e->getMessage());
                }
            }
            
            // Fallback: Just add context that it's a selection
            return "I'm selecting option {$selectedNumber} from your previous response. Please provide more details about that option.";
        }
        
        return $message;
    }

    /**
     * Get user context for AI system prompt
     * 
     * @param string|int $userId
     * @return string|null
     */
    protected function getUserContext($userId): ?string
    {
        try {
            // Get user model class from config
            $userModel = config('auth.providers.users.model', 'App\\Models\\User');
            
            if (!class_exists($userModel)) {
                return null;
            }
            
            // Fetch user with caching (5 minutes)
            $user = \Illuminate\Support\Facades\Cache::remember(
                "ai_user_context_{$userId}",
                300,
                fn() => $userModel::find($userId)
            );
            
            if (!$user) {
                return null;
            }
            
            // Build user context
            $context = "USER CONTEXT:\n";
            
            // User ID (always include for data searching)
            $context .= "- User ID: {$user->id}\n";
            
            // Name
            if (isset($user->name)) {
                $context .= "- User's name: {$user->name}\n";
            }
            
            // Email (always include for data searching)
            if (isset($user->email)) {
                $context .= "- Email: {$user->email}\n";
            }
            
            // Phone number
            if (isset($user->phone)) {
                $context .= "- Phone: {$user->phone}\n";
            } elseif (isset($user->phone_number)) {
                $context .= "- Phone: {$user->phone_number}\n";
            } elseif (isset($user->mobile)) {
                $context .= "- Phone: {$user->mobile}\n";
            }
            
            // Additional useful fields
            if (isset($user->username)) {
                $context .= "- Username: {$user->username}\n";
            }
            
            if (isset($user->first_name) && isset($user->last_name)) {
                $context .= "- Full Name: {$user->first_name} {$user->last_name}\n";
            }
            
            if (isset($user->title) || isset($user->job_title)) {
                $title = $user->title ?? $user->job_title;
                $context .= "- Job Title: {$title}\n";
            }
            
            if (isset($user->department)) {
                $context .= "- Department: {$user->department}\n";
            }
            
            if (isset($user->location) || isset($user->city)) {
                $location = $user->location ?? $user->city;
                $context .= "- Location: {$location}\n";
            }
            
            if (isset($user->timezone)) {
                $context .= "- Timezone: {$user->timezone}\n";
            }
            
            if (isset($user->language) || isset($user->locale)) {
                $language = $user->language ?? $user->locale;
                $context .= "- Language: {$language}\n";
            }
            
            // Role/Admin status
            if (isset($user->is_admin) && $user->is_admin) {
                $context .= "- Role: Administrator (has full system access)\n";
            } elseif (method_exists($user, 'getRoleNames')) {
                // Spatie Laravel Permission
                $roles = $user->getRoleNames();
                if ($roles->isNotEmpty()) {
                    $context .= "- Role: " . $roles->join(', ') . "\n";
                }
            } elseif (method_exists($user, 'roles')) {
                // Generic roles relationship
                $roles = $user->roles()->pluck('name');
                if ($roles->isNotEmpty()) {
                    $context .= "- Role: " . $roles->join(', ') . "\n";
                }
            }
            
            // Tenant/Organization
            if (isset($user->tenant_id)) {
                $context .= "- Organization ID: {$user->tenant_id}\n";
            } elseif (isset($user->organization_id)) {
                $context .= "- Organization ID: {$user->organization_id}\n";
            } elseif (isset($user->company_id)) {
                $context .= "- Company ID: {$user->company_id}\n";
            }
            
            // Custom user context (if method exists)
            if (method_exists($user, 'getAIContext')) {
                $customContext = $user->getAIContext();
                if ($customContext) {
                    $context .= $customContext . "\n";
                }
            }
            
            $context .= "\nIMPORTANT INSTRUCTIONS:\n";
            $context .= "- Always address the user by their name when appropriate\n";
            $context .= "- When searching for user's data, use their User ID ({$user->id}) or Email ({$user->email})\n";
            $context .= "- Personalize responses based on their role and context\n";
            $context .= "- When user asks 'my emails', 'my documents', etc., search for data belonging to User ID: {$user->id}";
            
            return $context;
            
        } catch (\Exception $e) {
            Log::warning('Failed to get user context for AI', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
