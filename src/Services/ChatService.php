<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        // Note: Will be enhanced with intent analysis after it's performed
        $aiRequest = Engine::createRequest(
            prompt: $processedMessage,
            engine: $engine,
            model: $model,
            maxTokens: 1000,
            temperature: 0.7,
            systemPrompt: $this->getSystemPrompt($useActions, $userId) // Will be updated with intent analysis
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

            // For newer models with native conversation support, use conversationId for long conversations
            // instead of sending full message history (more efficient)
            $fullHistory = $this->memoryOptimization->getOptimizedHistory($conversationId, 100);
            $messageCount = count($fullHistory);
            $conversationThreshold = 21;

            // Check if model supports native conversation continuity from database
            $modelRecord = \LaravelAIEngine\Models\AIModel::findByModelId($model);
            $supportsNativeConversation = false;

            if ($modelRecord) {
                // Check if model has large context window (indicates conversation continuity support)
                $contextWindow = $modelRecord->getContextWindowSize();
                $supportsNativeConversation = $contextWindow && $contextWindow >= 100000;

                // Or check if model has 'conversation' capability
                if (!$supportsNativeConversation && $modelRecord->supports('conversation')) {
                    $supportsNativeConversation = true;
                }
            }

            if ($supportsNativeConversation && $messageCount > $conversationThreshold) {
                // For long conversations with supported models, rely on conversationId
                // Only send recent context (last 5 messages) for immediate context
                $messages = array_slice($fullHistory, -5);

                if (config('ai-engine.debug')) {
                    Log::channel('ai-engine')->debug('Using conversationId for long conversation', [
                        'conversation_id' => $conversationId,
                        'total_messages' => $messageCount,
                        'sent_messages' => count($messages),
                        'model' => $model,
                        'context_window' => $modelRecord?->getContextWindowSize()
                    ]);
                }
            } else {
                // For shorter conversations or older models, send optimized history
                $messages = array_slice($fullHistory, 0, 20);

                if (config('ai-engine.debug')) {
                    Log::channel('ai-engine')->debug('Conversation history loaded', [
                        'conversation_id' => $conversationId,
                        'message_count' => count($messages),
                    ]);
                }
            }

            if (!empty($messages)) {
                $aiRequest = $aiRequest->withMessages($messages);
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

        // INTELLIGENT INTENT ANALYSIS: Analyze user message before AI/RAG call
        // This makes intelligent decisions and enhances AI prompts based on context
        $intentAnalysis = null;
        if ($useActions && $this->smartActionService !== null && config('ai-engine.actions.intent_analysis', true)) {
            // Check for pending action in cache
            $cachedActionData = \Illuminate\Support\Facades\Cache::get("pending_action_{$sessionId}");

            // Analyze intent with context
            $intentAnalysis = $this->analyzeMessageIntent($processedMessage, $cachedActionData);

            Log::channel('ai-engine')->info('Intent analysis completed', [
                'intent' => $intentAnalysis['intent'],
                'confidence' => $intentAnalysis['confidence'],
                'has_pending_action' => $cachedActionData !== null,
                'context' => $intentAnalysis['context_enhancement']
            ]);

            // Handle different intents
            switch ($intentAnalysis['intent']) {
                case 'confirm':
                    if ($cachedActionData) {
                        Log::channel('ai-engine')->info('Confirmation detected with pending action, executing directly');

                        // Merge suggested params
                        $actionData = $cachedActionData['data'];
                        if (isset($cachedActionData['suggested_params'])) {
                            $actionData['params'] = array_merge(
                                $actionData['params'] ?? [],
                                $cachedActionData['suggested_params']
                            );
                        }

                        $action = new \LaravelAIEngine\DTOs\InteractiveAction(
                            id: $cachedActionData['id'],
                            type: \LaravelAIEngine\Enums\ActionTypeEnum::from($cachedActionData['type']),
                            label: $cachedActionData['label'],
                            description: $cachedActionData['description'] ?? '',
                            data: $actionData
                        );

                        $executionResult = $this->executeSmartActionInline($action, $userId);
                        \Illuminate\Support\Facades\Cache::forget("pending_action_{$sessionId}");

                        if ($executionResult['success']) {
                            return new AIResponse(
                                content: $executionResult['message'],
                                engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                                model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                                metadata: ['action_executed' => true, 'intent_analysis' => $intentAnalysis],
                                success: true
                            );
                        } else {
                            return new AIResponse(
                                content: "❌ Failed to execute action: " . ($executionResult['error'] ?? 'Unknown error'),
                                engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                                model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                                error: $executionResult['error'] ?? 'Execution failed',
                                success: false
                            );
                        }
                    }
                    break;

                case 'reject':
                    if ($cachedActionData) {
                        Log::channel('ai-engine')->info('Rejection detected, canceling pending action');
                        \Illuminate\Support\Facades\Cache::forget("pending_action_{$sessionId}");

                        return new AIResponse(
                            content: "Action canceled. How else can I help you?",
                            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                            model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                            metadata: ['action_canceled' => true, 'intent_analysis' => $intentAnalysis],
                            success: true
                        );
                    }
                    break;

                case 'modify':
                    if ($cachedActionData && !empty($intentAnalysis['extracted_data'])) {
                        $oldParams = $cachedActionData['data']['params'] ?? [];
                        
                        Log::channel('ai-engine')->info('Modification detected, updating pending action params', [
                            'old_params' => $oldParams,
                            'modifications' => $intentAnalysis['extracted_data'],
                            'action_label' => $cachedActionData['label'] ?? 'unknown'
                        ]);

                        // Update cached action with modifications
                        $cachedActionData['data']['params'] = array_merge(
                            $oldParams,
                            $intentAnalysis['extracted_data']
                        );

                        \Illuminate\Support\Facades\Cache::put("pending_action_{$sessionId}", $cachedActionData, 300);
                        
                        Log::channel('ai-engine')->info('Action params updated successfully', [
                            'new_params' => $cachedActionData['data']['params']
                        ]);

                        // Generate updated summary and return immediately
                        $actionType = $cachedActionData['type'] ?? 'button';
                        if (is_string($actionType)) {
                            $actionType = \LaravelAIEngine\Enums\ActionTypeEnum::from($actionType);
                        }
                        
                        $updatedSummary = $this->generateDataSummary(new \LaravelAIEngine\DTOs\InteractiveAction(
                            id: $cachedActionData['id'],
                            type: $actionType,
                            label: $cachedActionData['label'],
                            description: $cachedActionData['description'] ?? '',
                            data: $cachedActionData['data']
                        ));
                        
                        $modificationMessage = "Updated! Here's the revised information:\n\n" . $updatedSummary;
                        
                        return new AIResponse(
                            content: $modificationMessage,
                            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                            model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                            metadata: ['modification_applied' => true, 'updated_params' => $cachedActionData['data']['params']],
                            success: true
                        );
                    }
                    break;

                case 'provide_data':
                    if ($cachedActionData && !empty($intentAnalysis['extracted_data'])) {
                        Log::channel('ai-engine')->info('Additional data provided, updating pending action', [
                            'data' => $intentAnalysis['extracted_data'],
                            'is_incomplete' => $cachedActionData['is_incomplete'] ?? false,
                        ]);

                        // If this is an incomplete action, merge data with existing params
                        if ($cachedActionData['is_incomplete'] ?? false) {
                            $existingParams = $cachedActionData['data']['params'] ?? [];
                            $newData = $intentAnalysis['extracted_data'];
                            
                            // Generic smart merge: detect field prefix from missing_fields pattern
                            // Example: missing_fields = ['customer_name', 'customer_email'] → prefix = 'customer'
                            $missingFields = $cachedActionData['missing_fields'] ?? [];
                            $detectedPrefix = null;
                            $commonFields = ['name', 'email', 'phone', 'address', 'city', 'country', 'zip'];
                            
                            // Detect prefix from missing fields pattern
                            foreach ($missingFields as $field) {
                                foreach ($commonFields as $commonField) {
                                    if (str_ends_with($field, '_' . $commonField)) {
                                        $detectedPrefix = str_replace('_' . $commonField, '', $field);
                                        break 2;
                                    }
                                }
                            }
                            
                            // If we detected a prefix, apply it to all common fields in new data
                            // to avoid conflicts with existing data
                            if ($detectedPrefix) {
                                $prefixedData = [];
                                foreach ($newData as $key => $value) {
                                    // Check if this is a common field that should be prefixed
                                    // Apply prefix if:
                                    // 1. It's a common field (name, email, phone, etc.)
                                    // 2. It doesn't already have a prefix (no underscore)
                                    // 3. Either the field exists in params OR it's in the missing fields list
                                    $shouldPrefix = in_array($key, $commonFields) && 
                                                   !str_contains($key, '_') &&
                                                   (isset($existingParams[$key]) || 
                                                    in_array($detectedPrefix . '_' . $key, $missingFields));
                                    
                                    if ($shouldPrefix) {
                                        $prefixedData[$detectedPrefix . '_' . $key] = $value;
                                    } else {
                                        $prefixedData[$key] = $value;
                                    }
                                }
                                $newData = $prefixedData;
                                
                                Log::channel('ai-engine')->info('Applied generic field prefixing', [
                                    'detected_prefix' => $detectedPrefix,
                                    'missing_fields' => $missingFields,
                                    'original_keys' => array_keys($intentAnalysis['extracted_data']),
                                    'prefixed_keys' => array_keys($prefixedData),
                                ]);
                            }
                            
                            $mergedParams = array_merge($existingParams, $newData);
                            $cachedActionData['data']['params'] = $mergedParams;
                            
                            // Re-check if action is now complete by checking missing fields
                            $missingFields = $cachedActionData['missing_fields'] ?? [];
                            $stillMissing = [];
                            
                            foreach ($missingFields as $field) {
                                // Check if the field is now satisfied
                                $fieldParts = explode('_', $field);
                                $satisfied = false;
                                
                                // Check direct field
                                if (isset($mergedParams[$field]) && !empty($mergedParams[$field])) {
                                    $satisfied = true;
                                }
                                // Check if it's a sub-field (e.g., customer_name)
                                elseif (count($fieldParts) > 1) {
                                    $mainField = $fieldParts[0];
                                    $subField = implode('_', array_slice($fieldParts, 1));
                                    if (isset($mergedParams[$subField]) && !empty($mergedParams[$subField])) {
                                        $satisfied = true;
                                    }
                                }
                                
                                if (!$satisfied) {
                                    $stillMissing[] = $field;
                                }
                            }
                            
                            // Update action status
                            if (empty($stillMissing)) {
                                $cachedActionData['is_incomplete'] = false;
                                $cachedActionData['data']['ready_to_execute'] = true;
                                unset($cachedActionData['missing_fields']);
                                
                                Log::channel('ai-engine')->info('Action is now complete after merging data', [
                                    'merged_params' => array_keys($mergedParams),
                                    'action' => $cachedActionData['label'],
                                ]);
                            } else {
                                $cachedActionData['missing_fields'] = $stillMissing;
                                
                                Log::channel('ai-engine')->info('Action still incomplete after merging', [
                                    'still_missing' => $stillMissing,
                                ]);
                            }
                            
                            Log::channel('ai-engine')->info('Merged data for incomplete action', [
                                'existing_params' => array_keys($existingParams),
                                'new_data' => array_keys($intentAnalysis['extracted_data']),
                                'merged_params' => array_keys($mergedParams),
                                'is_now_complete' => empty($stillMissing),
                            ]);
                        } else {
                            // For complete actions, merge with suggested params
                            if (!isset($cachedActionData['suggested_params'])) {
                                $cachedActionData['suggested_params'] = [];
                            }
                            $cachedActionData['suggested_params'] = array_merge(
                                $cachedActionData['suggested_params'],
                                $intentAnalysis['extracted_data']
                            );
                        }

                        \Illuminate\Support\Facades\Cache::put("pending_action_{$sessionId}", $cachedActionData, 300);
                    }
                    break;
            }
        }

        // Enhance AI request with intent analysis if available
        if ($intentAnalysis && $intentAnalysis['intent'] !== 'confirm' && $intentAnalysis['intent'] !== 'reject') {
            // Update system prompt with intent context for smarter AI responses
            $enhancedSystemPrompt = $this->getSystemPrompt($useActions, $userId, $intentAnalysis);
            $aiRequest = $aiRequest->withSystemPrompt($enhancedSystemPrompt);

            Log::channel('ai-engine')->info('Enhanced AI prompt with intent analysis', [
                'intent' => $intentAnalysis['intent'],
                'confidence' => $intentAnalysis['confidence']
            ]);
        }

        // Use Intelligent RAG if enabled and available
        Log::info('ChatService processMessage', [
            'useIntelligentRAG' => $useIntelligentRAG,
            'intelligentRAG_available' => $this->intelligentRAG !== null,
            'message' => substr($message, 0, 50),
            'ragCollections_passed' => $ragCollections,
            'ragCollections_count' => count($ragCollections),
            'intent_enhanced' => $intentAnalysis !== null,
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
                    'intent_analysis' => $intentAnalysis, // Pass intent analysis to action generation
                ];

                Log::channel('ai-engine')->info('Checking for smart actions', [
                    'message' => $processedMessage,
                    'has_service' => $this->smartActionService !== null,
                    'conversation_history_count' => count($conversationHistory),
                    'intent' => $intentAnalysis['intent'] ?? null,
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

                // Add smart actions to metadata for ActionService to use
                $metadata['smart_actions'] = $smartActions;

                // If no actions generated, check if there's a completed cached action from provide_data flow
                if (empty($smartActions)) {
                    $cachedActionData = \Illuminate\Support\Facades\Cache::get("pending_action_{$sessionId}");
                    
                    // Check if cached action was just completed (provide_data intent)
                    if ($cachedActionData && 
                        isset($intentAnalysis['intent']) && 
                        $intentAnalysis['intent'] === 'provide_data' &&
                        !($cachedActionData['is_incomplete'] ?? true)) {
                        
                        Log::channel('ai-engine')->info('Presenting completed action after data provided', [
                            'action' => $cachedActionData['label'],
                            'params' => array_keys($cachedActionData['data']['params'] ?? []),
                        ]);
                        
                        // Generate updated description with current data
                        $params = $cachedActionData['data']['params'] ?? [];
                        $modelClass = $cachedActionData['data']['model_class'] ?? '';
                        $modelName = class_basename($modelClass);
                        
                        $description = "**Confirm {$modelName} Creation**\n\n";
                        $description .= "**Summary of Information:**\n\n";
                        
                        // Show all collected data
                        foreach ($params as $key => $value) {
                            if (is_array($value)) {
                                $description .= "- **" . ucfirst(str_replace('_', ' ', $key)) . ":** " . json_encode($value) . "\n";
                            } else {
                                $description .= "- **" . ucfirst(str_replace('_', ' ', $key)) . ":** {$value}\n";
                            }
                        }
                        
                        $description .= "\n**Please review the information above.**\n";
                        $description .= "Reply 'yes' to create, or tell me what you'd like to change.";
                        
                        // Convert cached action back to InteractiveAction with updated description
                        $smartActions = [
                            new \LaravelAIEngine\DTOs\InteractiveAction(
                                id: $cachedActionData['id'],
                                type: \LaravelAIEngine\Enums\ActionTypeEnum::from($cachedActionData['type']),
                                label: str_replace(' (Incomplete)', '', $cachedActionData['label']),
                                description: $description,
                                data: $cachedActionData['data']
                            )
                        ];
                        
                        $metadata['smart_actions'] = $smartActions;
                    }
                }
                
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
                    // Check if action is incomplete (has missing required fields)
                    $actionToCache = $smartActions[0];
                    $isIncomplete = !($actionToCache->data['ready_to_execute'] ?? true);
                    $missingFields = $actionToCache->data['missing_fields'] ?? [];
                    
                    // If action is incomplete, override response to ask for missing information
                    if ($isIncomplete && !empty($missingFields)) {
                        $modelClass = $actionToCache->data['model_class'] ?? '';
                        $modelName = class_basename($modelClass);
                        
                        // Cache the incomplete action so we can continue the conversation
                        \Illuminate\Support\Facades\Cache::put(
                            "pending_action_{$sessionId}",
                            [
                                'id' => $actionToCache->id,
                                'type' => $actionToCache->type->value,
                                'label' => $actionToCache->label,
                                'description' => $actionToCache->description,
                                'data' => $actionToCache->data,
                                'missing_fields' => $missingFields,
                                'is_incomplete' => true,
                            ],
                            300 // 5 minutes
                        );
                        
                        Log::channel('ai-engine')->info('Cached incomplete action for continuation', [
                            'session_id' => $sessionId,
                            'action' => $actionToCache->label,
                            'missing_fields' => $missingFields,
                        ]);
                        
                        // Build a helpful message asking for missing information
                        $askForInfo = "I'll help you create a {$modelName}. To proceed, I need the following information:\n\n";
                        foreach ($missingFields as $field) {
                            $fieldLabel = str_replace('_', ' ', $field);
                            $askForInfo .= "- **" . ucfirst($fieldLabel) . "**\n";
                        }
                        $askForInfo .= "\nPlease provide these details.";
                        
                        // Create a simple response asking for missing info
                        return new AIResponse(
                            content: $askForInfo,
                            engine: $response->getEngine(),
                            model: $response->getModel(),
                            actions: $smartActions,
                            metadata: $metadata,
                            tokensUsed: $response->getTokensUsed(),
                            creditsUsed: $response->getCreditsUsed(),
                            latency: $response->getLatency(),
                            requestId: $response->getRequestId(),
                            finishReason: 'incomplete_action',
                            success: true,
                            conversationId: $response->getConversationId()
                        );
                    }
                    
                    // Generate AI suggestions for optional parameters
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
                    $autoExecuteAction = $this->checkAutoExecuteAction($smartActions, $processedMessage, $sessionId);

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

                        // Remove executed action from pending list
                        $this->removeExecutedAction($sessionId, $autoExecuteAction->id);

                        if ($executionResult['success']) {
                            // Append execution result to response content
                            $originalContent = $response->getContent();
                            $newContent = $originalContent . "\n\n" . $executionResult['message'];

                            // Update response by modifying metadata directly
                            $metadata = $response->getMetadata();

                            // Get remaining pending actions count
                            $remainingActions = $this->getPendingActionsCount($sessionId);

                            $metadata['action_executed'] = [
                                'action' => $autoExecuteAction->label,
                                'result' => $executionResult,
                                'remaining_pending_actions' => $remainingActions,
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
                            'type' => $action->type->value ?? 'button',
                            'label' => $action->label,
                            'description' => $action->description,
                            'data' => $action->data,
                            'optional_params' => $this->getOptionalParamsForAction($action),
                        ], $smartActions);

                        // Store all actions for potential confirmation (support multiple actions per session)
                        if (!empty($smartActions)) {
                            // Get existing pending actions from cache
                            $cacheKey = "pending_actions_{$sessionId}";
                            $existingActions = Cache::get($cacheKey, []);

                            // Add new action to the list
                            $newAction = [
                                'id' => $smartActions[0]->id,
                                'label' => $smartActions[0]->label,
                                'data' => $smartActions[0]->data,
                                'optional_params' => $this->getOptionalParamsForAction($smartActions[0]),
                                'created_at' => now()->toIso8601String(),
                            ];

                            $existingActions[] = $newAction;

                            // Store updated list in cache (24 hour TTL)
                            Cache::put($cacheKey, $existingActions, 86400);

                            // Keep backward compatibility - store most recent action
                            $metadata['pending_action'] = $newAction;
                            $metadata['pending_actions_count'] = count($existingActions);
                        }

                        // Add prompt for optional parameters to response
                        $originalContent = $response->getContent();

                        // When smart action is detected, replace the AI response with a positive acknowledgment
                        // This works in any language since we're not parsing the response
                        $itemName = $smartActions[0]->data['params']['name'] ?? $smartActions[0]->data['params']['title'] ?? 'this';
                        $originalContent = "I can help you with that. Let me gather the necessary information.";

                        // Generate summary of collected data
                        $dataSummary = $this->generateDataSummary($smartActions[0]);
                        
                        $optionalParamsPrompt = $this->generateOptionalParamsPrompt($smartActions[0]);

                        // Add confirmation prompt at the end
                        $confirmationPrompt = "\n\n**Would you like to proceed with this action?** Reply with 'yes' to confirm.";

                        if ($optionalParamsPrompt) {
                            $newContent = $originalContent . "\n\n" . $dataSummary . "\n\n" . $optionalParamsPrompt . $confirmationPrompt;

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
                            // No optional params, just add data summary and confirmation prompt
                            $newContent = $originalContent . "\n\n" . $dataSummary . $confirmationPrompt;

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
     * Get system prompt based on configuration and intent analysis
     */
    protected function getSystemPrompt(bool $useActions, $userId = null, ?array $intentAnalysis = null): string
    {
        $prompt = "You are a helpful AI assistant. Provide clear, accurate, and helpful responses to user questions.";

        // Enhance prompt with intent analysis context
        if ($intentAnalysis) {
            $prompt .= "\n\n## CONTEXT FROM INTENT ANALYSIS:\n";
            $prompt .= "User Intent: {$intentAnalysis['intent']}\n";
            $prompt .= "Confidence: " . ($intentAnalysis['confidence'] * 100) . "%\n";
            $prompt .= "Context: {$intentAnalysis['context_enhancement']}\n";

            if (!empty($intentAnalysis['extracted_data'])) {
                $prompt .= "Extracted Data: " . json_encode($intentAnalysis['extracted_data']) . "\n";
            }
            
            // Check if there's a pending action with missing fields
            if (isset($intentAnalysis['pending_action'])) {
                $pendingAction = $intentAnalysis['pending_action'];
                $missingFields = $pendingAction['missing_fields'] ?? [];
                $modelClass = $pendingAction['model_class'] ?? null;
                
                if (!empty($missingFields) && $modelClass) {
                    // Get conversational guidance from model
                    $guidance = $this->getModelConversationalGuidance($modelClass);
                    
                    if ($guidance) {
                        $prompt .= "\n\n## CONVERSATIONAL GUIDANCE:\n";
                        $prompt .= $guidance . "\n";
                    }
                    
                    $prompt .= "\n\nIMPORTANT: User wants to create a " . class_basename($modelClass) . " but is missing required information.\n";
                    $prompt .= "Missing fields: " . implode(', ', $missingFields) . "\n";
                    $prompt .= "Ask for the missing information in a friendly, conversational way. Don't create the action yet.";
                }
            }

            // Add intent-specific instructions
            switch ($intentAnalysis['intent']) {
                case 'modify':
                    $prompt .= "\nIMPORTANT: User wants to MODIFY existing parameters. Acknowledge the changes and show updated information.";
                    break;
                case 'provide_data':
                    $prompt .= "\nIMPORTANT: User is providing ADDITIONAL DATA for optional fields. Acknowledge receipt and ask if they want to proceed.";
                    break;
                case 'question':
                    $prompt .= "\nIMPORTANT: User has a QUESTION. Provide clear explanation and ask if they're ready to proceed after answering.";
                    break;
                case 'new_request':
                    $prompt .= "\nIMPORTANT: This is a NEW REQUEST. Focus on understanding and extracting parameters for the new action.";
                    break;
            }
        }

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

        // Optional params should be defined in model's AI config, not hardcoded here
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
     * Generate data summary for confirmation
     */
    protected function generateDataSummary(\LaravelAIEngine\DTOs\InteractiveAction $action): string
    {
        $params = $action->data['params'] ?? [];
        $modelClass = $action->data['model_class'] ?? '';
        $modelName = class_basename($modelClass);
        
        $summary = "**Summary of Information:**\n\n";
        
        // Format each parameter
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                // Handle array fields (like items)
                if ($key === 'items' && !empty($value)) {
                    $summary .= "**Items:**\n";
                    foreach ($value as $index => $item) {
                        $itemNum = $index + 1;
                        $itemName = $item['item'] ?? $item['name'] ?? $item['product_name'] ?? 'Item';
                        $itemPrice = $item['price'] ?? 0;
                        $itemQty = $item['quantity'] ?? 1;
                        
                        $summary .= "{$itemNum}. {$itemName}\n";
                        $summary .= "   - Price: $" . number_format($itemPrice, 2) . "\n";
                        $summary .= "   - Quantity: {$itemQty}\n";
                    }
                }
                continue;
            }
            
            // Format scalar values
            $label = ucfirst(str_replace('_', ' ', $key));
            
            // Format based on field type
            if (in_array($key, ['price', 'total', 'amount', 'cost'])) {
                $summary .= "- **{$label}:** $" . number_format($value, 2) . "\n";
            } else {
                $summary .= "- **{$label}:** {$value}\n";
            }
        }
        
        $summary .= "\n**Please review the information above.**\nReply 'yes' to create, or tell me what you'd like to change.";
        
        return $summary;
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

        // AI suggestion generation should be model-driven, not hardcoded
        // Models can implement their own suggestion logic if needed
        try {
            $entityName = $currentParams['name'] ?? 'Record';

            // Generic prompt that works for any entity type
            $fieldDescriptions = array_map(function($param) {
                return "- {$param}: Provide a realistic value for this field";
            }, $optionalParams);

            $prompt = "Based on this record: \"{$entityName}\"\n\n";
            $prompt .= "Generate intelligent, realistic suggestions for these fields:\n";
            $prompt .= implode("\n", $fieldDescriptions) . "\n\n";
            $prompt .= "Return ONLY a valid JSON object with these exact keys.";

            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                systemPrompt: 'You are a data assistant. Generate professional, realistic information. Return valid JSON only.',
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
                    'entity' => $entityName,
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
     * Analyze user message intent using AI (language-agnostic)
     *
     * @return array{intent: string, confidence: float, extracted_data: array, context_enhancement: string}
     */
    protected function analyzeMessageIntent(string $message, ?array $pendingAction = null): array
    {
        // Quick check for single-word confirmations (optimization)
        $messageLower = strtolower(trim($message));
        $quickConfirms = ['yes', 'ok', 'okay', 'confirm', 'sure', 'yep', 'yeah', 'yup'];

        if (in_array($messageLower, $quickConfirms)) {
            return [
                'intent' => 'confirm',
                'confidence' => 1.0,
                'extracted_data' => [],
                'context_enhancement' => 'User confirmed with simple affirmative response.'
            ];
        }

        // Use AI to analyze intent comprehensively
        try {
            $prompt = "Analyze the user's message intent and provide structured output.\n\n";
            $prompt .= "User Message: \"{$message}\"\n\n";

            if ($pendingAction) {
                $prompt .= "Context: There is a pending action waiting for user response.\n";
                $prompt .= "Pending Action: {$pendingAction['label']}\n";
                $prompt .= "Required Parameters: " . json_encode($pendingAction['data']['params'] ?? []) . "\n\n";
            }

            $prompt .= "Analyze and classify the intent into ONE of these categories:\n";
            $prompt .= "1. 'confirm' - User agrees/confirms to proceed (yes, ok, go ahead, I don't mind, sounds good, etc.)\n";
            $prompt .= "2. 'reject' - User declines/cancels (no, cancel, stop, nevermind, etc.)\n";
            $prompt .= "3. 'modify' - User wants to change/update parameters. Examples:\n";
            $prompt .= "   - 'change price to X'\n";
            $prompt .= "   - 'make it Y instead'\n";
            $prompt .= "   - 'Customer name Mohamed2' (updating customer_name field)\n";
            $prompt .= "   - 'price should be 500' (updating price field)\n";
            $prompt .= "   - Any message that provides a field name/value pair to update existing data\n";
            $prompt .= "4. 'provide_data' - User is providing additional data for optional parameters\n";
            $prompt .= "5. 'question' - User is asking a question or needs clarification\n";
            $prompt .= "6. 'new_request' - User is making a completely new request\n\n";

            $prompt .= "IMPORTANT: If user mentions a field name from the pending action followed by a value, classify as 'modify'.\n";
            $prompt .= "Extract any data mentioned (prices, names, values, etc.) with proper field names.\n\n";

            $prompt .= "Respond with ONLY valid JSON in this exact format:\n";
            $prompt .= "{\n";
            $prompt .= '  "intent": "confirm|reject|modify|provide_data|question|new_request",'."\n";
            $prompt .= '  "confidence": 0.95,'."\n";
            $prompt .= '  "extracted_data": {"field_name": "value"},'."\n";
            $prompt .= '  "context_enhancement": "Brief description of what user wants"'."\n";
            $prompt .= "}";

            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            );

            $response = $this->aiEngineService->generate($aiRequest);
            $result = json_decode($response->getContent(), true);

            if (is_array($result) && isset($result['intent'])) {
                return $result;
            }

            // Fallback if JSON parsing fails
            return [
                'intent' => 'new_request',
                'confidence' => 0.5,
                'extracted_data' => [],
                'context_enhancement' => 'Unable to parse intent, treating as new request.'
            ];

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI intent analysis failed, using fallback', [
                'error' => $e->getMessage()
            ]);

            // Fallback to basic keyword detection
            if (str_contains($messageLower, 'yes') || str_contains($messageLower, 'ok') || str_contains($messageLower, 'confirm')) {
                return [
                    'intent' => 'confirm',
                    'confidence' => 0.7,
                    'extracted_data' => [],
                    'context_enhancement' => 'Detected confirmation keyword.'
                ];
            }

            if (str_contains($messageLower, 'no') || str_contains($messageLower, 'cancel') || str_contains($messageLower, 'stop')) {
                return [
                    'intent' => 'reject',
                    'confidence' => 0.7,
                    'extracted_data' => [],
                    'context_enhancement' => 'Detected rejection keyword.'
                ];
            }

            return [
                'intent' => 'new_request',
                'confidence' => 0.5,
                'extracted_data' => [],
                'context_enhancement' => 'Fallback: treating as new request.'
            ];
        }
    }

    
    /**
     * Check if message is a confirmation (backward compatibility)
     */
    protected function isConfirmationMessage(string $message): bool
    {
        $analysis = $this->analyzeMessageIntent($message);
        return $analysis['intent'] === 'confirm';
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
    protected function checkAutoExecuteAction(array $smartActions, string $message, string $sessionId = null): ?\LaravelAIEngine\DTOs\InteractiveAction
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

        // If user is confirming, check for pending actions in cache
        if ($isConfirmation && $sessionId) {
            // Check singular cache key first (current storage format)
            $cacheKey = "pending_action_{$sessionId}";
            $cachedActionData = Cache::get($cacheKey);

            if ($cachedActionData) {
                Log::channel('ai-engine')->info('Auto-executing pending action from cache', [
                    'action' => $cachedActionData['label'],
                    'cache_key' => $cacheKey,
                ]);

                // Convert array back to InteractiveAction
                return new \LaravelAIEngine\DTOs\InteractiveAction(
                    id: $cachedActionData['id'],
                    type: \LaravelAIEngine\Enums\ActionTypeEnum::from($cachedActionData['type']),
                    label: $cachedActionData['label'],
                    description: $cachedActionData['description'] ?? '',
                    data: $cachedActionData['data']
                );
            }

            // Fallback: check plural cache key for backward compatibility
            $cacheKey = "pending_actions_{$sessionId}";
            $pendingActions = Cache::get($cacheKey, []);

            // If there are multiple pending actions, execute the most recent one
            if (!empty($pendingActions)) {
                $mostRecentAction = end($pendingActions);

                Log::channel('ai-engine')->info('Auto-executing most recent pending action from legacy cache', [
                    'action' => $mostRecentAction['label'],
                    'total_pending' => count($pendingActions),
                ]);

                // Convert array back to InteractiveAction
                return new \LaravelAIEngine\DTOs\InteractiveAction(
                    id: $mostRecentAction['id'],
                    type: \LaravelAIEngine\Enums\ActionTypeEnum::from(\LaravelAIEngine\Enums\ActionTypeEnum::BUTTON),
                    label: $mostRecentAction['label'],
                    description: '',
                    data: $mostRecentAction['data']
                );
            }
        }

        // Fallback to checking current smart actions
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

        // Apply AI mapper if model has mapAIData method
        $params = $this->applyAIMapper($modelClass, $params);

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
            // Note: Remote model's executeAI method handles its own data transformation
            
            Log::channel('ai-engine')->info('Executing remote model action', [
                'model' => $modelClass,
                'params' => $params
            ]);

            try {
                // Find which node has this model
                // In debug mode, get all nodes regardless of status
                $nodes = config('app.debug')
                    ? \LaravelAIEngine\Models\AINode::all()
                    : \LaravelAIEngine\Models\AINode::where('status', 'active')->get();
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
                $authToken = config('ai-engine.nodes.shared_secret') ?? config('ai-engine.nodes.jwt_secret') ?? $targetNode->api_key;

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
                        $summary = "✅ **{$modelName} Created Successfully**\n\n";
                        
                        $data = $result['data'] ?? $params;
                        
                        // Format customer information if available
                        if (isset($data['customer']) && is_array($data['customer'])) {
                            $customer = $data['customer'];
                            $summary .= "**Customer:**\n";
                            $summary .= "- Name: " . ($customer['name'] ?? 'N/A') . "\n";
                            $summary .= "- Email: " . ($customer['email'] ?? 'N/A') . "\n";
                            $summary .= "\n";
                        }
                        
                        // Format items if available
                        if (isset($data['items']) && is_array($data['items']) && !empty($data['items'])) {
                            $summary .= "**Items:**\n";
                            foreach ($data['items'] as $index => $item) {
                                $itemNum = $index + 1;
                                $itemName = $item['product_name'] ?? $item['name'] ?? $item['item'] ?? 'Item';
                                $itemPrice = $item['price'] ?? 0;
                                $itemQty = $item['quantity'] ?? 1;
                                $itemTotal = $item['total'] ?? ($itemPrice * $itemQty);
                                
                                $summary .= "{$itemNum}. {$itemName}\n";
                                $summary .= "   - Price: $" . number_format($itemPrice, 2) . "\n";
                                $summary .= "   - Quantity: {$itemQty}\n";
                                
                                if (isset($item['discount']) && $item['discount'] > 0) {
                                    $summary .= "   - Discount: $" . number_format($item['discount'], 2) . "\n";
                                }
                                
                                $summary .= "   - Subtotal: $" . number_format($itemTotal, 2) . "\n";
                            }
                            $summary .= "\n";
                        }
                        
                        // Format total and other key fields
                        $summary .= "**Summary:**\n";
                        
                        if (isset($data['total'])) {
                            $summary .= "- **Total: $" . number_format($data['total'], 2) . "**\n";
                        }
                        
                        // Add invoice/order ID if available
                        if (isset($data['invoice']['invoice_id'])) {
                            $summary .= "- Invoice ID: #" . $data['invoice']['invoice_id'] . "\n";
                        } elseif (isset($data['invoice']['id'])) {
                            $summary .= "- Invoice ID: #" . $data['invoice']['id'] . "\n";
                        } elseif (isset($data['id'])) {
                            $summary .= "- ID: #" . $data['id'] . "\n";
                        }
                        
                        // Add other scalar fields (excluding already shown ones)
                        $excludeKeys = ['success', 'message', 'created_at', 'updated_at', 'customer', 'items', 'total', 'invoice', 'id'];
                        foreach ($data as $key => $value) {
                            if (!in_array($key, $excludeKeys) && !is_array($value) && !is_null($value)) {
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

    // Legacy methods removed - all model execution now handled by generic executeRemoteModelAction()

    /**
     * Remove executed action from pending list
     */
    protected function removeExecutedAction(string $sessionId, string $actionId): void
    {
        $cacheKey = "pending_actions_{$sessionId}";
        $pendingActions = Cache::get($cacheKey, []);

        // Remove the executed action
        $pendingActions = array_filter($pendingActions, function($action) use ($actionId) {
            return $action['id'] !== $actionId;
        });

        // Re-index array
        $pendingActions = array_values($pendingActions);

        // Update cache
        if (empty($pendingActions)) {
            Cache::forget($cacheKey);
        } else {
            Cache::put($cacheKey, $pendingActions, 86400);
        }

        Log::channel('ai-engine')->info('Removed executed action from pending list', [
            'session_id' => $sessionId,
            'action_id' => $actionId,
            'remaining' => count($pendingActions),
        ]);
    }

    /**
     * Get count of pending actions for a session
     */
    protected function getPendingActionsCount(string $sessionId): int
    {
        $cacheKey = "pending_actions_{$sessionId}";
        $pendingActions = Cache::get($cacheKey, []);
        return count($pendingActions);
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

    /**
     * Get conversational guidance from model's AI config
     */
    protected function getModelConversationalGuidance(string $modelClass): ?string
    {
        try {
            if (!class_exists($modelClass)) {
                return null;
            }
            
            $reflection = new \ReflectionClass($modelClass);
            
            if (!$reflection->hasMethod('initializeAI')) {
                return null;
            }
            
            $method = $reflection->getMethod('initializeAI');
            if (!$method->isStatic()) {
                return null;
            }
            
            $config = $modelClass::initializeAI();
            $description = $config['description'] ?? '';
            
            // Extract conversational guidance section
            if (preg_match('/CONVERSATIONAL GUIDANCE:(.*?)(?=\n\n[A-Z]|$)/s', $description, $matches)) {
                return trim($matches[1]);
            }
            
            return null;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Failed to get conversational guidance', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Apply AI mapper to transform data before execution
     * Checks if model has mapAIData method and calls it
     */
    protected function applyAIMapper(string $modelClass, array $params): array
    {
        try {
            // Check if model exists and has mapAIData method
            if (class_exists($modelClass)) {
                $reflection = new \ReflectionClass($modelClass);

                if ($reflection->hasMethod('mapAIData')) {
                    $method = $reflection->getMethod('mapAIData');

                    Log::channel('ai-engine')->info('Applying AI data mapper', [
                        'model' => $modelClass,
                        'original_params' => $params
                    ]);

                    // Call the mapper method
                    if ($method->isStatic()) {
                        $mappedParams = $modelClass::mapAIData($params);
                    } else {
                        $model = new $modelClass();
                        $mappedParams = $model->mapAIData($params);
                    }

                    Log::channel('ai-engine')->info('AI data mapper applied', [
                        'model' => $modelClass,
                        'mapped_params' => $mappedParams
                    ]);

                    return $mappedParams;
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI mapper failed, using original params', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
        }

        // Return original params if no mapper or mapper failed
        return $params;
    }
}
