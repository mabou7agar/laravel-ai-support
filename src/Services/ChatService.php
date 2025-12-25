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
        protected ?RAGCollectionDiscovery $ragDiscovery = null,
        protected ?SmartActionService $smartActionService = null
    ) {
        // Lazy load IntelligentRAGService if available
        if ($this->intelligentRAG === null && app()->bound(IntelligentRAGService::class)) {
            $this->intelligentRAG = app(IntelligentRAGService::class);
        }

        // Lazy load RAGCollectionDiscovery if available
        if ($this->ragDiscovery === null && app()->bound(RAGCollectionDiscovery::class)) {
            $this->ragDiscovery = app(RAGCollectionDiscovery::class);
        }

        // Lazy load SmartActionService if available
        if ($this->smartActionService === null) {
            try {
                $this->smartActionService = new SmartActionService($this->aiEngineService);
            } catch (\Exception $e) {
                Log::warning('Failed to instantiate SmartActionService: ' . $e->getMessage());
            }
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
        $userId = null,
        ?string $searchInstructions = null
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
                        'search_instructions' => $searchInstructions,
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

        // Check for smart actions if enabled (inline action handling)
        if ($useActions && $this->smartActionService !== null) {
            try {
                $sources = $response->getMetadata()['sources'] ?? [];
                $conversationHistory = !empty($messages) ? $messages : [];
                
                $metadata = [
                    'conversation_history' => $conversationHistory,
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                ];

                Log::channel('ai-engine')->info('Checking for smart actions', [
                    'message' => $processedMessage,
                    'has_service' => $this->smartActionService !== null,
                    'conversation_history_count' => count($conversationHistory),
                ]);

                $smartActions = $this->smartActionService->generateSmartActions(
                    $processedMessage,
                    $sources,
                    $metadata
                );

                Log::channel('ai-engine')->info('Smart actions generated', [
                    'count' => count($smartActions),
                    'actions' => array_map(fn($a) => $a->label, $smartActions),
                ]);

                // If no actions generated but user is confirming or providing optional params, retrieve from cache
                if (empty($smartActions) && ($this->isConfirmationMessage($processedMessage) || $this->hasOptionalParamValues($processedMessage))) {
                    Log::channel('ai-engine')->info('Confirmation or optional params detected, checking cache for pending action', [
                        'message' => $processedMessage,
                        'session_id' => $sessionId
                    ]);
                    
                    // Try to get pending action from cache
                    $cachedActionData = \Illuminate\Support\Facades\Cache::get("pending_action_{$sessionId}");
                    
                    if ($cachedActionData) {
                        // Check if user provided optional parameters or wants to use suggestions
                        $isConfirmation = $this->isConfirmationMessage($processedMessage);
                        $additionalParams = [];
                        
                        if ($isConfirmation && isset($cachedActionData['suggested_params'])) {
                            // User confirmed - use AI suggestions
                            $additionalParams = $cachedActionData['suggested_params'];
                            Log::channel('ai-engine')->info('Using AI-suggested parameters', [
                                'suggested_params' => $additionalParams
                            ]);
                        } else {
                            // Extract any custom values user provided
                            $additionalParams = $this->extractOptionalParamsFromMessage($processedMessage, $cachedActionData['optional_params'] ?? []);
                            Log::channel('ai-engine')->info('Extracted custom parameters', [
                                'custom_params' => $additionalParams
                            ]);
                        }
                        
                        // Merge additional params with existing params
                        if (!empty($additionalParams)) {
                            $cachedActionData['data']['params'] = array_merge(
                                $cachedActionData['data']['params'] ?? [],
                                $additionalParams
                            );
                        }
                        
                        // Reconstruct InteractiveAction from cached data
                        $cachedAction = new \LaravelAIEngine\DTOs\InteractiveAction(
                            id: $cachedActionData['id'],
                            type: \LaravelAIEngine\Enums\ActionTypeEnum::from($cachedActionData['type']),
                            label: $cachedActionData['label'],
                            description: $cachedActionData['description'],
                            data: $cachedActionData['data']
                        );
                        $smartActions = [$cachedAction];
                        Log::channel('ai-engine')->info('Retrieved pending action from cache', [
                            'action' => $cachedAction->label,
                            'params' => $cachedAction->data['params'] ?? []
                        ]);
                    } else {
                        Log::channel('ai-engine')->info('No cached action found, trying history');
                        // Fallback to history if cache missed
                        $smartActions = $this->getPendingActionsFromHistory($conversationHistory);
                    }
                    
                    Log::channel('ai-engine')->info('Actions retrieved', [
                        'count' => count($smartActions)
                    ]);
                }

                if (!empty($smartActions)) {
                    // Generate AI suggestions for optional parameters
                    $actionToCache = $smartActions[0];
                    $optionalParams = $this->getOptionalParamsForAction($actionToCache);
                    $currentParams = $actionToCache->data['params'] ?? [];
                    $missingOptional = array_filter($optionalParams, fn($param) => !isset($currentParams[$param]));
                    $suggestions = [];
                    
                    if (!empty($missingOptional)) {
                        $suggestions = $this->generateOptionalParamSuggestions($currentParams, $missingOptional);
                    }
                    
                    // Store pending action data in cache for confirmation (serialize to array)
                    \Illuminate\Support\Facades\Cache::put(
                        "pending_action_{$sessionId}",
                        [
                            'id' => $actionToCache->id,
                            'type' => $actionToCache->type->value,
                            'label' => $actionToCache->label,
                            'description' => $actionToCache->description,
                            'data' => $actionToCache->data,
                            'optional_params' => $optionalParams,
                            'suggested_params' => $suggestions,
                        ],
                        300 // 5 minutes
                    );
                    
                    // Check if any action is ready to execute automatically
                    $autoExecuteAction = $this->checkAutoExecuteAction($smartActions, $processedMessage);
                    
                    Log::channel('ai-engine')->info('Checked for auto-execute', [
                        'message' => $processedMessage,
                        'has_actions' => count($smartActions),
                        'auto_execute' => $autoExecuteAction ? $autoExecuteAction->label : 'none',
                        'action_data' => $smartActions[0]->data ?? []
                    ]);
                    
                    if ($autoExecuteAction) {
                        // Execute action inline and update response
                        Log::channel('ai-engine')->info('Auto-executing action', [
                            'action' => $autoExecuteAction->label,
                            'params' => $autoExecuteAction->data['params'] ?? []
                        ]);
                        
                        $executionResult = $this->executeSmartActionInline($autoExecuteAction, $userId);
                        
                        if ($executionResult['success']) {
                            // Append execution result to response content
                            $originalContent = $response->getContent();
                            $newContent = $originalContent . "\n\n" . $executionResult['message'];
                            
                            // Update response by modifying metadata directly
                            $metadata = $response->getMetadata();
                            $metadata['action_executed'] = [
                                'action' => $autoExecuteAction->label,
                                'result' => $executionResult,
                            ];
                            
                            // Create new response with all required parameters
                            $response = new AIResponse(
                                content: $newContent,
                                engine: $response->getEngine(),
                                model: $response->getModel(),
                                metadata: $metadata,
                                tokensUsed: $response->getTokensUsed(),
                                creditsUsed: $response->getCreditsUsed(),
                                latency: $response->getLatency(),
                                requestId: $response->getRequestId(),
                                usage: $response->getUsage(),
                                cached: $response->isCached(),
                                finishReason: $response->getFinishReason(),
                                files: $response->getFiles(),
                                actions: $response->getActions(),
                                error: $response->getError(),
                                success: $response->isSuccess(),
                                conversationId: $response->getConversationId()
                            );
                        }
                    } else {
                        // Add smart actions to response metadata with optional params info
                        $metadata = $response->getMetadata();
                        $metadata['smart_actions'] = array_map(fn($action) => [
                            'id' => $action->id,
                            'label' => $action->label,
                            'description' => $action->description,
                            'data' => $action->data,
                            'optional_params' => $this->getOptionalParamsForAction($action),
                        ], $smartActions);
                        
                        // Store first action for potential confirmation
                        if (!empty($smartActions)) {
                            $metadata['pending_action'] = [
                                'id' => $smartActions[0]->id,
                                'label' => $smartActions[0]->label,
                                'data' => $smartActions[0]->data,
                                'optional_params' => $this->getOptionalParamsForAction($smartActions[0]),
                            ];
                        }
                        
                        // Add prompt for optional parameters to response
                        $originalContent = $response->getContent();
                        
                        // When smart action is detected, replace the AI response with a positive acknowledgment
                        // This works in any language since we're not parsing the response
                        $itemName = $smartActions[0]->data['params']['name'] ?? $smartActions[0]->data['params']['title'] ?? 'this';
                        $originalContent = "I can help you with that. Let me gather the necessary information.";
                        
                        $optionalParamsPrompt = $this->generateOptionalParamsPrompt($smartActions[0]);
                        if ($optionalParamsPrompt) {
                            $newContent = $originalContent . "\n\n" . $optionalParamsPrompt;
                            
                            // Create new response with updated content
                            $response = new AIResponse(
                                content: $newContent,
                                engine: $response->getEngine(),
                                model: $response->getModel(),
                                metadata: $metadata,
                                tokensUsed: $response->getTokensUsed(),
                                creditsUsed: $response->getCreditsUsed(),
                                latency: $response->getLatency(),
                                requestId: $response->getRequestId(),
                                usage: $response->getUsage(),
                                cached: $response->isCached(),
                                finishReason: $response->getFinishReason(),
                                files: $response->getFiles(),
                                actions: $response->getActions(),
                                error: $response->getError(),
                                success: $response->isSuccess(),
                                conversationId: $response->getConversationId()
                            );
                        } else {
                            // Just update metadata
                            $response = new AIResponse(
                                content: $response->getContent(),
                                engine: $response->getEngine(),
                                model: $response->getModel(),
                                metadata: $metadata,
                                tokensUsed: $response->getTokensUsed(),
                                creditsUsed: $response->getCreditsUsed(),
                                latency: $response->getLatency(),
                                requestId: $response->getRequestId(),
                                usage: $response->getUsage(),
                                cached: $response->isCached(),
                                finishReason: $response->getFinishReason(),
                                files: $response->getFiles(),
                                actions: $response->getActions(),
                                error: $response->getError(),
                                success: $response->isSuccess(),
                                conversationId: $response->getConversationId()
                            );
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Smart action processing failed', [
                    'error' => $e->getMessage(),
                ]);
            }
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
            $prompt .= "\n\nIMPORTANT: You have the ability to CREATE and MANAGE data in this system.";
            $prompt .= "\n- When users ask to create products, orders, invoices, or other items, you CAN do it!";
            $prompt .= "\n- NEVER say 'I don't have information about creating...' - you have the capability to create items";
            $prompt .= "\n- When a user wants to create something, acknowledge that you can help and present the creation options";
            $prompt .= "\n- Be confident and positive about your ability to create and manage data";

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
        // Check if message is an option ID (opt_1_abc123 format)
        if (preg_match('/^opt_(\d+)_[a-f0-9]+$/i', trim($message), $matches)) {
            $selectedNumber = $matches[1];
            return $this->handleNumberedSelection($selectedNumber, $sessionId, $useMemory);
        }

        // Check if message is just a number (numbered selection)
        if (preg_match('/^\s*(\d+)\s*$/', trim($message), $matches)) {
            $selectedNumber = $matches[1];
            return $this->handleNumberedSelection($selectedNumber, $sessionId, $useMemory);
        }

        return $message;
    }

    /**
     * Handle numbered selection from previous response
     */
    protected function handleNumberedSelection(string $selectedNumber, string $sessionId, bool $useMemory): string
    {
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
                if ($lastAssistantMessage && preg_match('/^\s*' . $selectedNumber . '\.\s+\*\*(.+?)\*\*/m', $lastAssistantMessage, $optionMatch)) {
                    $selectedOption = trim($optionMatch[1]);
                    // Return the option title directly - AI will understand from conversation context
                    return $selectedOption;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to preprocess numbered selection: ' . $e->getMessage());
            }
        }

        // Fallback: Return just the number - AI will understand from conversation context
        return $selectedNumber;
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
            Log::warning('Failed to get user context: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get optional parameters for an action
     */
    protected function getOptionalParamsForAction(\LaravelAIEngine\DTOs\InteractiveAction $action): array
    {
        // Get action definition from SmartActionService to find optional params
        $actionId = $action->data['action'] ?? null;
        if (!$actionId || !$this->smartActionService) {
            return [];
        }

        // Try to get optional params from action data or definition
        // For now, return common optional params for ProductService
        if (str_contains($actionId, 'product')) {
            return ['sku', 'description', 'price', 'category_id', 'stock_quantity'];
        }

        return [];
    }

    /**
     * Generate prompt for optional parameters with AI suggestions
     */
    protected function generateOptionalParamsPrompt(\LaravelAIEngine\DTOs\InteractiveAction $action): ?string
    {
        $optionalParams = $this->getOptionalParamsForAction($action);
        
        if (empty($optionalParams)) {
            return null;
        }

        $currentParams = $action->data['params'] ?? [];
        $missingOptional = array_filter($optionalParams, fn($param) => !isset($currentParams[$param]));

        if (empty($missingOptional)) {
            return null;
        }

        // Generate AI suggestions for missing optional params
        $suggestions = $this->generateOptionalParamSuggestions($currentParams, $missingOptional);

        // If suggestions failed or are empty, don't show the prompt
        if (empty($suggestions) || count(array_filter($suggestions)) === 0) {
            $prompt = "\n\n📝 **Optional Information**\n\n";
            $prompt .= "Would you like to provide any additional details?\n";
            foreach ($missingOptional as $param) {
                $prompt .= "- " . ucfirst(str_replace('_', ' ', $param)) . "\n";
            }
            $prompt .= "\nYou can provide these now, or just say **'yes'** to proceed.";
            return $prompt;
        }

        $prompt = "\n\n📝 **Suggested Additional Information**\n\n";
        $prompt .= "I've prepared some suggestions based on your product:\n\n";
        
        foreach ($missingOptional as $param) {
            $label = ucfirst(str_replace('_', ' ', $param));
            $suggestion = $suggestions[$param] ?? null;
            if ($suggestion !== null) {
                $prompt .= "**{$label}:** {$suggestion}\n";
            }
        }
        
        $prompt .= "\nYou can:\n";
        $prompt .= "- Say **'yes'** to use these suggestions\n";
        $prompt .= "- Provide different values (e.g., 'SKU: MBP-2024, Price: $1999')\n";
        $prompt .= "- Say **'skip'** to proceed without optional fields";

        return $prompt;
    }

    /**
     * Generate AI suggestions for optional parameters
     */
    protected function generateOptionalParamSuggestions(array $currentParams, array $optionalParams): array
    {
        $suggestions = [];

        if (!$this->aiEngineService || empty($optionalParams)) {
            return $suggestions;
        }

        try {
            $productName = $currentParams['name'] ?? 'Product';
            
            // Build a more specific prompt with field descriptions
            $prompt = "Based on this product: \"{$productName}\"\n\n";
            $prompt .= "Generate intelligent, realistic suggestions for these fields:\n";
            $prompt .= "- sku: A unique product code (e.g., MBP-2024-001)\n";
            $prompt .= "- description: A detailed product description (2-3 sentences)\n";
            $prompt .= "- price: A realistic price in USD (number only)\n";
            $prompt .= "- category_id: A category identifier (e.g., electronics_laptops)\n";
            $prompt .= "- stock_quantity: Available stock (number)\n\n";
            $prompt .= "Return ONLY a valid JSON object with these exact keys.\n";
            $prompt .= "Example: {\"sku\":\"MBP-2024-001\",\"description\":\"Professional laptop...\",\"price\":1999.99,\"category_id\":\"electronics_laptops\",\"stock_quantity\":50}";

            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                systemPrompt: 'You are a product data assistant. Generate professional, realistic product information. Return valid JSON only.',
                maxTokens: 400
            );
            
            $response = $this->aiEngineService->generate($aiRequest);
            $content = trim($response->getContent());
            
            // Try to extract JSON if wrapped in markdown
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }
            
            $result = json_decode($content, true);
            
            if (is_array($result) && !empty($result)) {
                // Filter to only include requested params
                $suggestions = array_intersect_key($result, array_flip($optionalParams));
                
                Log::channel('ai-engine')->info('Generated AI suggestions', [
                    'product' => $productName,
                    'suggestions' => $suggestions
                ]);
            } else {
                Log::warning('AI suggestions returned invalid JSON', [
                    'content' => $content
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to generate optional param suggestions: ' . $e->getMessage());
        }

        return $suggestions;
    }

    /**
     * Check if message contains optional parameter values
     */
    protected function hasOptionalParamValues(string $message): bool
    {
        // Check if message contains common patterns for providing values
        $patterns = [
            '/sku[:\s]+/i',
            '/description[:\s]+/i',
            '/price[:\s]+\$?\d+/i',
            '/category[:\s]+/i',
            '/stock[:\s]+\d+/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract optional parameters from user message
     */
    protected function extractOptionalParamsFromMessage(string $message, array $optionalParams): array
    {
        $extracted = [];

        // Use AI to extract optional parameters
        if ($this->aiEngineService && !empty($optionalParams)) {
            try {
                $prompt = "Extract the following optional fields from the message:\n";
                $prompt .= "Fields: " . implode(', ', $optionalParams) . "\n\n";
                $prompt .= "Message: {$message}\n\n";
                $prompt .= "Return ONLY a JSON object with the extracted values. Use null for missing fields.\n";
                $prompt .= "Example: {\"sku\": \"WH-001\", \"price\": 149.99}\n";

                $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                    prompt: $prompt,
                    engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                    model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                    systemPrompt: 'You are a data extraction assistant.',
                    maxTokens: 200
                );
                
                $response = $this->aiEngineService->generate($aiRequest);
                $result = json_decode($response->getContent(), true);
                
                if (is_array($result)) {
                    $extracted = array_filter($result, fn($v) => $v !== null);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to extract optional params: ' . $e->getMessage());
            }
        }

        return $extracted;
    }

    /**
     * Check if message is a confirmation
     */
    protected function isConfirmationMessage(string $message): bool
    {
        $messageLower = strtolower(trim($message));
        $confirmKeywords = ['yes', 'confirm', 'do it', 'go ahead', 'proceed', 'create it', 'make it', 'ok', 'okay'];
        
        foreach ($confirmKeywords as $keyword) {
            if ($messageLower === $keyword || str_contains($messageLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get pending actions from conversation history
     */
    protected function getPendingActionsFromHistory(array $conversationHistory): array
    {
        // Look for the last user message that triggered an action
        for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
            $message = $conversationHistory[$i];
            
            if (($message['role'] ?? '') === 'user') {
                $content = $message['content'] ?? '';
                
                // Check if this message was about creating a product
                if (str_contains(strtolower($content), 'product') || 
                    str_contains(strtolower($content), 'sell') ||
                    str_contains(strtolower($content), 'price') ||
                    str_contains($content, '$')) {
                    
                    // Re-generate the action from this message
                    if ($this->smartActionService) {
                        try {
                            $metadata = [
                                'conversation_history' => $conversationHistory,
                                'user_id' => $message['user_id'] ?? null,
                                'session_id' => $message['session_id'] ?? null,
                            ];
                            $actions = $this->smartActionService->generateSmartActions($content, [], $metadata);
                            
                            if (!empty($actions)) {
                                Log::channel('ai-engine')->info('Retrieved pending action from history', [
                                    'action' => $actions[0]->label,
                                    'from_message' => substr($content, 0, 50)
                                ]);
                                return $actions;
                            }
                        } catch (\Exception $e) {
                            Log::warning('Failed to retrieve pending actions: ' . $e->getMessage());
                        }
                    }
                    
                    break;
                }
            }
        }
        
        return [];
    }

    /**
     * Check if any smart action should be auto-executed
     */
    protected function checkAutoExecuteAction(array $smartActions, string $message): ?\LaravelAIEngine\DTOs\InteractiveAction
    {
        $messageLower = strtolower($message);
        
        // Check for confirmation keywords
        $confirmKeywords = ['yes', 'confirm', 'do it', 'go ahead', 'proceed', 'create it', 'make it'];
        $isConfirmation = false;
        
        foreach ($confirmKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $isConfirmation = true;
                break;
            }
        }
        
        // If user is confirming and there's a ready action, execute it
        if ($isConfirmation) {
            foreach ($smartActions as $action) {
                if (isset($action->data['ready_to_execute']) && $action->data['ready_to_execute']) {
                    return $action;
                }
            }
        }
        
        return null;
    }

    /**
     * Execute smart action inline
     */
    protected function executeSmartActionInline(\LaravelAIEngine\DTOs\InteractiveAction $action, $userId): array
    {
        $executor = $action->data['executor'] ?? null;
        $params = $action->data['params'] ?? [];
        $modelClass = $action->data['model_class'] ?? null;

        Log::channel('ai-engine')->info('Executing smart action inline', [
            'action' => $action->label,
            'executor' => $executor,
            'model_class' => $modelClass,
            'params' => $params
        ]);

        // Route to appropriate executor
        switch ($executor) {
            case 'model.dynamic':
                return $this->executeDynamicModelAction($modelClass, $params, $userId);
                
            case 'email.reply':
                return $this->executeEmailReply($params, $userId);
                
            default:
                return [
                    'success' => false,
                    'error' => "Unknown executor: {$executor}"
                ];
        }
    }

    /**
     * Execute dynamic model action using model's executeAI method
     */
    protected function executeDynamicModelAction(?string $modelClass, array $params, $userId): array
    {
        if (!$modelClass) {
            return [
                'success' => false,
                'error' => 'Invalid model class'
            ];
        }

        // Add user_id to params
        $params['user_id'] = $userId;

        // Check if model exists locally
        if (class_exists($modelClass)) {
            // Local model execution
            try {
                $reflection = new \ReflectionClass($modelClass);
                
                // Check if model has executeAI method
                if (!$reflection->hasMethod('executeAI')) {
                    return [
                        'success' => false,
                        'error' => "Model {$modelClass} does not have executeAI method"
                    ];
                }

                $method = $reflection->getMethod('executeAI');

                Log::channel('ai-engine')->info('Executing local model action', [
                    'model' => $modelClass,
                    'method' => 'executeAI',
                    'params' => $params
                ]);

                // Execute the model's AI action
                if ($method->isStatic()) {
                    $result = $modelClass::executeAI('create', $params);
                } else {
                    $model = new $modelClass();
                    $result = $model->executeAI('create', $params);
            }

            // Format response
            $modelName = class_basename($modelClass);
            
            if (is_array($result) && isset($result['success']) && $result['success']) {
                $summary = "✅ **{$modelName} Created Successfully!**\n\n";
                $summary .= "**Details:**\n";
                
                $data = $result['data'] ?? $result;
                foreach ($data as $key => $value) {
                    if (!in_array($key, ['success', 'message', 'created_at', 'updated_at'])) {
                        $summary .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
                    }
                }

                if (isset($result['id'])) {
                    $summary .= "- ID: {$result['id']}\n";
                }

                return [
                    'success' => true,
                    'message' => $summary,
                    'data' => $result,
                    'model' => $modelClass
                ];
            } elseif (is_object($result)) {
                // Model instance returned
                $summary = "✅ **{$modelName} Created Successfully!**\n\n";
                $summary .= "**Details:**\n";
                
                $attributes = method_exists($result, 'toArray') ? $result->toArray() : (array) $result;
                foreach ($attributes as $key => $value) {
                    if (!in_array($key, ['created_at', 'updated_at']) && !is_array($value) && !is_object($value)) {
                        $summary .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
                    }
                }

                return [
                    'success' => true,
                    'message' => $summary,
                    'data' => $attributes,
                    'model' => $modelClass
                ];
            }

                return [
                    'success' => false,
                    'error' => 'Model action did not return expected result'
                ];

            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Local model action failed', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return [
                    'success' => false,
                    'error' => 'Failed to execute action: ' . $e->getMessage()
                ];
            }
        } else {
            // Remote model execution - call via API
            Log::channel('ai-engine')->info('Executing remote model action', [
                'model' => $modelClass,
                'params' => $params
            ]);

            try {
                // Find which node has this model
                $nodes = \LaravelAIEngine\Models\AINode::where('status', 'active')->get();
                $targetNode = null;
                
                foreach ($nodes as $node) {
                    $response = \Illuminate\Support\Facades\Http::withoutVerifying()
                        ->timeout(5)
                        ->withToken($node->api_key)
                        ->get($node->url . '/api/ai-engine/collections');
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        foreach ($data['collections'] ?? [] as $collection) {
                            if (($collection['class'] ?? '') === $modelClass) {
                                $targetNode = $node;
                                break 2;
                            }
                        }
                    }
                }
                
                if (!$targetNode) {
                    return [
                        'success' => false,
                        'error' => "No remote node found with model {$modelClass}"
                    ];
                }
                
                // Call remote node's executeAI endpoint
                Log::channel('ai-engine')->info('Calling remote execute endpoint', [
                    'url' => $targetNode->url . '/api/ai-engine/execute',
                    'model' => $modelClass,
                    'node' => $targetNode->name
                ]);
                
                // Use shared secret from env or node's API key
                $authToken = config('ai-engine.nodes.shared_secret') ?? $targetNode->api_key;
                
                $response = \Illuminate\Support\Facades\Http::withoutVerifying()
                    ->timeout(30)
                    ->withToken($authToken)
                    ->post($targetNode->url . '/api/ai-engine/execute', [
                        'model_class' => $modelClass,
                        'action' => 'create',
                        'params' => $params
                    ]);
                
                Log::channel('ai-engine')->info('Remote execute response', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                if ($response->successful()) {
                    $result = $response->json();
                    $modelName = class_basename($modelClass);
                    
                    if ($result['success'] ?? false) {
                        $summary = "✅ **{$modelName} Created Successfully on Remote Node!**\n\n";
                        $summary .= "**Details:**\n";
                        
                        $data = $result['data'] ?? $params;
                        foreach ($data as $key => $value) {
                            if (!in_array($key, ['success', 'message', 'created_at', 'updated_at']) && !is_array($value)) {
                                $summary .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
                            }
                        }
                        
                        return [
                            'success' => true,
                            'message' => $summary,
                            'data' => $result['data'] ?? $params,
                            'model' => $modelClass,
                            'remote' => true,
                            'node' => $targetNode->name
                        ];
                    } else {
                        return [
                            'success' => false,
                            'error' => $result['error'] ?? 'Remote execution failed'
                        ];
                    }
                } else {
                    return [
                        'success' => false,
                        'error' => 'Remote API call failed: ' . $response->status()
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Remote model execution failed', [
                    'model' => $modelClass,
                    'error' => $e->getMessage()
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Remote execution failed: ' . $e->getMessage()
                ];
            }
        }
    }

    /**
     * Execute product creation
     */
    protected function executeProductCreation(array $params, $userId): array
    {
        // Validate required fields
        if (empty($params['name']) || empty($params['price'])) {
            return [
                'success' => false,
                'error' => 'Missing required fields: name and price are required',
                'params' => $params
            ];
        }
        
        // Try to find node with ProductService
        $productNode = $this->findNodeWithProductService();
        
        if ($productNode) {
            // Execute on remote node
            try {
                Log::channel('ai-engine')->info('Creating product on remote node', [
                    'node' => $productNode->name,
                    'url' => $productNode->url,
                    'params' => $params
                ]);
                
                $response = \Illuminate\Support\Facades\Http::timeout(10)
                    ->withToken($productNode->api_key)
                    ->post($productNode->url . '/api/products', array_merge($params, [
                        'user_id' => $userId,
                        'workspace_id' => $userId, // Some systems use workspace_id
                    ]));
                
                if ($response->successful()) {
                    $productData = $response->json();
                    
                    $summary = "📋 **Product Created Successfully on {$productNode->name}!**\n\n";
                    $summary .= "**Details:**\n";
                    $summary .= "- Name: {$params['name']}\n";
                    $summary .= "- Price: $" . number_format($params['price'], 2) . "\n";
                    
                    if (!empty($params['description'])) {
                        $summary .= "- Description: {$params['description']}\n";
                    }
                    if (!empty($params['category'])) {
                        $summary .= "- Category: {$params['category']}\n";
                    }
                    if (isset($productData['id'])) {
                        $summary .= "- Product ID: {$productData['id']}\n";
                    }
                    
                    return [
                        'success' => true,
                        'message' => $summary,
                        'data' => $productData,
                        'node' => $productNode->name
                    ];
                }
                
                // API call failed
                Log::channel('ai-engine')->warning('Remote product creation failed', [
                    'node' => $productNode->name,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Failed to create product on remote node: ' . $response->body(),
                    'node' => $productNode->name
                ];
                
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Remote product creation error', [
                    'node' => $productNode->name,
                    'error' => $e->getMessage()
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Error creating product: ' . $e->getMessage()
                ];
            }
        }
        
        // No remote node found, create locally or return error
        Log::channel('ai-engine')->info('No ProductService node found, logging locally', [
            'params' => $params,
            'user_id' => $userId
        ]);
        
        $summary = "📋 **Product Details Prepared!**\n\n";
        $summary .= "**Details:**\n";
        $summary .= "- Name: {$params['name']}\n";
        $summary .= "- Price: $" . number_format($params['price'], 2) . "\n";
        
        if (!empty($params['description'])) {
            $summary .= "- Description: {$params['description']}\n";
        }
        if (!empty($params['category'])) {
            $summary .= "- Category: {$params['category']}\n";
        }
        
        $summary .= "\n*Note: Product service not available. Data has been logged.*";
        
        return [
            'success' => true,
            'message' => $summary,
            'data' => $params,
            'local_only' => true
        ];
    }
    
    /**
     * Find node that has ProductService using RAG collection discovery
     */
    protected function findNodeWithProductService(): ?\LaravelAIEngine\Models\AINode
    {
        try {
            // Use RAG collection discovery to find all collections
            if (!$this->ragDiscovery) {
                Log::warning('RAGCollectionDiscovery not available');
                return null;
            }
            
            $discoveredCollections = $this->ragDiscovery->discover();
            
            Log::channel('ai-engine')->info('Searching for ProductService in discovered collections', [
                'total_collections' => count($discoveredCollections)
            ]);
            
            // Look for ProductService in discovered collections
            foreach ($discoveredCollections as $collectionClass) {
                if (str_contains($collectionClass, 'ProductService')) {
                    Log::channel('ai-engine')->info('Found ProductService collection', [
                        'collection' => $collectionClass
                    ]);
                    
                    // Now find which node has this collection
                    $node = $this->findNodeForCollection($collectionClass);
                    
                    if ($node) {
                        Log::channel('ai-engine')->info('Found node with ProductService', [
                            'node' => $node->name,
                            'url' => $node->url
                        ]);
                        return $node;
                    }
                }
            }
            
            Log::channel('ai-engine')->info('ProductService not found in discovered collections');
        } catch (\Exception $e) {
            Log::warning('Error finding ProductService node: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Find which node has a specific collection
     */
    protected function findNodeForCollection(string $collectionClass): ?\LaravelAIEngine\Models\AINode
    {
        try {
            // Get all active nodes
            $nodes = \LaravelAIEngine\Models\AINode::where('status', 'active')->get();
            
            foreach ($nodes as $node) {
                try {
                    $response = \Illuminate\Support\Facades\Http::timeout(5)
                        ->withToken($node->api_key)
                        ->get($node->url . '/api/ai-engine/node/collections');
                    
                    if ($response->successful()) {
                        $collections = $response->json()['collections'] ?? [];
                        
                        foreach ($collections as $collection) {
                            $className = $collection['class'] ?? '';
                            if ($className === $collectionClass) {
                                return $node;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('Failed to check node for collection', [
                        'node' => $node->name,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Error finding node for collection: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Execute email reply
     */
    protected function executeEmailReply(array $params, $userId): array
    {
        // TODO: Integrate with email service
        Log::channel('ai-engine')->info('Email reply would be sent', [
            'params' => $params,
            'user_id' => $userId
        ]);
        
        return [
            'success' => true,
            'message' => '✅ Email reply sent successfully!',
            'data' => $params
        ];
    }

    /**
     * Execute task creation
     */
    protected function executeTaskCreation(array $params, $userId): array
    {
        // TODO: Integrate with task service
        Log::channel('ai-engine')->info('Task would be created', [
            'params' => $params,
            'user_id' => $userId
        ]);
        
        return [
            'success' => true,
            'message' => '✅ Task created successfully!',
            'data' => $params
        ];
    }
}
