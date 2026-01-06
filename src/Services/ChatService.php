<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\Actions\ActionManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChatService
{
    public function __construct(
        protected ConversationService $conversationService,
        protected AIEngineService $aiEngineService,
        protected MemoryOptimizationService $memoryOptimization,
        protected ?IntelligentRAGService $intelligentRAG = null,
        protected ?RAGCollectionDiscovery $ragDiscovery = null,
        protected ?ActionManager $actionManager = null,
        protected ?PendingActionService $pendingActionService = null
    ) {
        // Lazy load IntelligentRAGService if available
        if ($this->intelligentRAG === null && app()->bound(IntelligentRAGService::class)) {
            $this->intelligentRAG = app(IntelligentRAGService::class);
        }

        // Lazy load RAGCollectionDiscovery if available
        if ($this->ragDiscovery === null && app()->bound(RAGCollectionDiscovery::class)) {
            $this->ragDiscovery = app(RAGCollectionDiscovery::class);
        }

        // Lazy load ActionManager if available
        if ($this->actionManager === null && app()->bound(ActionManager::class)) {
            $this->actionManager = app(ActionManager::class);
        }

        // Lazy load PendingActionService if available
        if ($this->pendingActionService === null && app()->bound(PendingActionService::class)) {
            $this->pendingActionService = app(PendingActionService::class);
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
        if ($useActions && $this->actionManager !== null && config('ai-engine.actions.intent_analysis', true)) {
            // Check for pending action in database
            $pendingAction = $this->pendingActionService?->get($sessionId);
            $cachedActionData = $pendingAction ? [
                'id' => $pendingAction->id,
                'type' => $pendingAction->type->value,
                'label' => $pendingAction->label,
                'description' => $pendingAction->description,
                'data' => $pendingAction->data,
                'missing_fields' => $pendingAction->data['missing_fields'] ?? [],
                'is_incomplete' => !empty($pendingAction->data['missing_fields'] ?? []),
            ] : null;

            // Analyze intent with context (without actions for now - AI engine issue)
            $intentAnalysis = $this->analyzeMessageIntent($processedMessage, $cachedActionData);

            Log::channel('ai-engine')->info('Intent analysis completed', [
                'intent' => $intentAnalysis['intent'],
                'confidence' => $intentAnalysis['confidence'],
                'has_pending_action' => $cachedActionData !== null,
                'context' => $intentAnalysis['context_enhancement'],
                'has_ai_error' => isset($intentAnalysis['ai_error']),
            ]);
            
            // If intent analysis failed due to AI error, show error to user
            if (isset($intentAnalysis['ai_error'])) {
                return new AIResponse(
                    content: "⚠️ " . $intentAnalysis['ai_error'],
                    engine: $engine,
                    model: $model,
                    metadata: [
                        'ai_error' => true,
                        'error_message' => $intentAnalysis['ai_error'],
                        'session_id' => $sessionId,
                    ],
                    success: true, // Still successful from API perspective
                    conversationId: $aiRequest->getConversationId()
                );
            }

            // Handle different intents
            switch ($intentAnalysis['intent']) {
                case 'new_request':
                case 'create':
                    // Clear any existing pending action when user makes a new request
                    if ($cachedActionData) {
                        Log::channel('ai-engine')->info('New request detected, clearing old pending action', [
                            'old_action' => $cachedActionData['label'],
                        ]);
                        $this->pendingActionService?->delete($sessionId);
                    }
                    break;
                    
                case 'use_suggestions':
                    // User wants to use AI-generated suggestions for optional fields
                    if ($cachedActionData) {
                        Log::channel('ai-engine')->info('User wants to use AI suggestions', [
                            'action' => $cachedActionData['label'],
                        ]);
                        
                        // Get existing params
                        $existingParams = $cachedActionData['data']['params'] ?? [];
                        
                        // Generate AI suggestions for optional fields
                        $tempAction = (object)[
                            'data' => $cachedActionData['data'],
                        ];
                        
                        $optionalParams = $this->getOptionalParamsForAction($tempAction, $existingParams);
                        $suggestions = [];
                        
                        if (!empty($optionalParams)) {
                            $suggestions = $this->generateOptionalParamSuggestions(
                                $existingParams,
                                $optionalParams
                            );
                        }
                        
                        if (empty($suggestions)) {
                            Log::channel('ai-engine')->warning('No AI suggestions available to apply');
                            return new AIResponse(
                                content: "I don't have any AI suggestions to apply. The action already has all available information.\n\nReply 'yes' to proceed with the current information.",
                                engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                                model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                                metadata: ['no_suggestions' => true],
                                success: true
                            );
                        }
                        
                        // Merge suggestions with existing params
                        $mergedParams = array_merge($existingParams, $suggestions);
                        
                        $cachedActionData['data']['params'] = $mergedParams;
                        $this->pendingActionService?->updateParams($sessionId, $mergedParams);
                        
                        Log::channel('ai-engine')->info('Applied AI suggestions to action', [
                            'suggestions_count' => count($suggestions),
                            'suggestion_fields' => array_keys($suggestions),
                        ]);
                        
                        // Present updated summary for final confirmation
                        $modelName = class_basename($cachedActionData['data']['model_class'] ?? '');
                        $description = "**✅ Applied AI Suggestions to {$modelName}**\n\n";
                        $description .= "**Complete Information:**\n\n";
                        
                        foreach ($mergedParams as $key => $value) {
                            $formattedKey = ucfirst(str_replace('_', ' ', $key));
                            $description .= "- **{$formattedKey}:** {$value}\n";
                        }
                        
                        $description .= "\n**Would you like to proceed?** Reply 'yes' to confirm.";
                        
                        return new AIResponse(
                            content: $description,
                            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                            model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                            metadata: ['suggestions_applied' => true, 'pending_action' => $cachedActionData],
                            success: true
                        );
                    }
                    break;
                
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
                        $this->pendingActionService?->markExecuted($sessionId);

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
                        $this->pendingActionService?->delete($sessionId);

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

                        // Update pending action in database
                        $this->pendingActionService?->updateParams($sessionId, $intentAnalysis['extracted_data']);
                        
                        Log::channel('ai-engine')->info('Action params updated successfully', [
                            'new_params' => $cachedActionData['data']['params']
                        ]);

                        // Check if action is now complete and should show AI suggestions
                        $isNowComplete = empty($cachedActionData['missing_fields'] ?? []);
                        
                        if ($isNowComplete) {
                            // Get optional parameters for AI suggestions
                            $params = $cachedActionData['data']['params'] ?? [];
                            $modelClass = $cachedActionData['data']['model_class'] ?? '';
                            $modelName = class_basename($modelClass);
                            
                            $tempAction = (object)['data' => $cachedActionData['data']];
                            $optionalParams = $this->getOptionalParamsForAction($tempAction);
                            $missingOptional = array_filter($optionalParams, fn($param) => !isset($params[$param]));
                            
                            Log::channel('ai-engine')->info('Checking for optional params after modify', [
                                'model_class' => $modelClass,
                                'optional_params' => $optionalParams,
                                'missing_optional' => $missingOptional,
                            ]);
                            
                            // Generate AI suggestions for missing optional fields
                            $suggestions = [];
                            if (!empty($missingOptional) && $this->aiEngineService) {
                                $suggestions = $this->generateOptionalParamSuggestions($params, $missingOptional);
                                Log::channel('ai-engine')->info('Generated AI suggestions in modify case', [
                                    'suggestions' => $suggestions,
                                ]);
                            }
                            
                            $description = "**Confirm {$modelName} Creation**\n\n";
                            $description .= "**Summary of Information:**\n\n";
                            
                            foreach ($params as $key => $value) {
                                if (is_array($value)) {
                                    $description .= "- **" . ucfirst(str_replace('_', ' ', $key)) . ":** " . json_encode($value) . "\n";
                                } else {
                                    $description .= "- **" . ucfirst(str_replace('_', ' ', $key)) . ":** {$value}\n";
                                }
                            }
                            
                            // Add AI suggestions if available
                            if (!empty($suggestions)) {
                                $description .= "\n**AI Suggestions for Optional Fields:**\n\n";
                                foreach ($suggestions as $field => $suggestedValue) {
                                    $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                                    $description .= "- **{$fieldLabel}:** {$suggestedValue}\n";
                                }
                                $description .= "\n*You can add these by saying 'use suggestions' or add your own values.*\n";
                            }
                            
                            $description .= "\n**Please review the information above.**\n";
                            $description .= "Reply 'yes' to create, or tell me what you'd like to change.";
                            
                            return new AIResponse(
                                content: $description,
                                engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                                model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                                metadata: [
                                    'pending_action' => $cachedActionData,
                                    'ai_suggestions' => $suggestions,
                                ],
                                success: true
                            );
                        }

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

                        $stillMissing = []; // Initialize for scope
                        $wasIncomplete = $cachedActionData['is_incomplete'] ?? false;
                        
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
                            
                            // Apply model's normalization to merged params
                            $modelClass = $cachedActionData['data']['model_class'] ?? null;
                            if ($modelClass && method_exists($modelClass, 'normalizeAIData')) {
                                try {
                                    $reflection = new \ReflectionMethod($modelClass, 'normalizeAIData');
                                    $reflection->setAccessible(true);
                                    $mergedParams = $reflection->invoke(null, $mergedParams);
                                } catch (\Exception $e) {
                                    // If normalization fails, continue with original params
                                }
                            }
                            
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
                            // For complete actions, merge additional data directly with params
                            $existingParams = $cachedActionData['data']['params'] ?? [];
                            $newData = $intentAnalysis['extracted_data'];
                            $mergedParams = array_merge($existingParams, $newData);
                            
                            $cachedActionData['data']['params'] = $mergedParams;
                            
                            Log::channel('ai-engine')->info('Merged additional data for complete action', [
                                'existing_params' => array_keys($existingParams),
                                'new_data' => array_keys($newData),
                                'merged_params' => array_keys($mergedParams),
                            ]);
                        }

                        // Update pending action in database with merged params
                        if ($this->pendingActionService && isset($cachedActionData)) {
                            $this->pendingActionService->updateParams($sessionId, $cachedActionData['data']['params'] ?? []);
                        }
                        
                        Log::channel('ai-engine')->debug('Checking if action is complete for early return', [
                            'stillMissing' => $stillMissing,
                            'isEmpty' => empty($stillMissing),
                            'wasIncomplete' => $wasIncomplete,
                            'isIncomplete' => $cachedActionData['is_incomplete'] ?? 'not set',
                        ]);
                        
                        // If action was incomplete and is now complete, present it for confirmation with suggestions
                        if ($wasIncomplete && empty($stillMissing)) {
                            Log::channel('ai-engine')->info('Presenting completed action for confirmation', [
                                'action' => $cachedActionData['label'] ?? 'unknown',
                                'params' => array_keys($cachedActionData['data']['params'] ?? []),
                            ]);
                            
                            $params = $cachedActionData['data']['params'] ?? [];
                            $modelClass = $cachedActionData['data']['model_class'] ?? '';
                            $modelName = class_basename($modelClass);
                            
                            // Get optional parameters that weren't provided
                            // Create a temporary action object from cached data for getOptionalParamsForAction
                            $tempAction = (object)['data' => $cachedActionData['data']];
                            $optionalParams = $this->getOptionalParamsForAction($tempAction);
                            $missingOptional = array_filter($optionalParams, fn($param) => !isset($params[$param]));
                            
                            Log::channel('ai-engine')->info('Checking for optional params to suggest', [
                                'model_class' => $modelClass,
                                'optional_params' => $optionalParams,
                                'missing_optional' => $missingOptional,
                                'current_params' => array_keys($params),
                            ]);
                            
                            // Generate AI suggestions for missing optional fields
                            $suggestions = [];
                            if (!empty($missingOptional) && $this->aiEngineService) {
                                $suggestions = $this->generateOptionalParamSuggestions($params, $missingOptional);
                                Log::channel('ai-engine')->info('Generated AI suggestions', [
                                    'suggestions' => $suggestions,
                                ]);
                            }
                            
                            $description = "**Confirm {$modelName} Creation**\n\n";
                            $description .= "**Summary of Information:**\n\n";
                            
                            foreach ($params as $key => $value) {
                                if (is_array($value)) {
                                    $description .= "- **" . ucfirst(str_replace('_', ' ', $key)) . ":** " . json_encode($value) . "\n";
                                } else {
                                    $description .= "- **" . ucfirst(str_replace('_', ' ', $key)) . ":** {$value}\n";
                                }
                            }
                            
                            // Add AI suggestions for optional fields if available
                            if (!empty($suggestions)) {
                                $description .= "\n**AI Suggestions for Optional Fields:**\n\n";
                                foreach ($suggestions as $field => $suggestedValue) {
                                    $fieldLabel = ucfirst(str_replace('_', ' ', $field));
                                    $description .= "- **{$fieldLabel}:** {$suggestedValue}\n";
                                }
                                $description .= "\n*You can add these by saying 'use suggestions' or add your own values.*\n";
                            }
                            
                            $description .= "\n**Please review the information above.**\n";
                            $description .= "Reply 'yes' to create, or tell me what you'd like to change.\n\n";
                            $description .= "**Would you like to proceed with this action?** Reply with 'yes' to confirm.";
                            
                            return new AIResponse(
                                content: $description,
                                engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                                model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                                metadata: [
                                    'action_completed' => true,
                                    'pending_action' => $cachedActionData,
                                    'intent_analysis' => $intentAnalysis,
                                    'ai_suggestions' => $suggestions
                                ],
                                success: true
                            );
                        }
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

        // Check if this is an action-based request (skip RAG for actions)
        // Use intent analysis only - language-agnostic, works with any language
        $isActionIntent = $intentAnalysis && in_array($intentAnalysis['intent'] ?? '', ['new_request', 'create', 'update', 'delete', 'provide_data', 'confirm', 'reject']);
        
        // Also skip RAG if there's a pending action in database (user is in action flow)
        $hasPendingAction = $this->pendingActionService?->has($sessionId) ?? false;
        
        $shouldSkipRAG = $isActionIntent || $hasPendingAction;

        // Use Intelligent RAG if enabled and available (but skip for action intents)
        Log::info('ChatService processMessage', [
            'useIntelligentRAG' => $useIntelligentRAG,
            'intelligentRAG_available' => $this->intelligentRAG !== null,
            'message' => substr($message, 0, 50),
            'ragCollections_passed' => $ragCollections,
            'ragCollections_count' => count($ragCollections),
            'intent_enhanced' => $intentAnalysis !== null,
            'is_action_intent' => $isActionIntent,
            'has_pending_action' => $hasPendingAction,
            'should_skip_rag' => $shouldSkipRAG,
        ]);

        if ($useIntelligentRAG && $this->intelligentRAG !== null && !$shouldSkipRAG) {
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

        // Check for actions if enabled (inline action handling)
        if ($useActions && $this->actionManager !== null) {
            try {
                $sources = $response->getMetadata()['sources'] ?? [];
                $conversationHistory = !empty($messages) ? $messages : [];

                $context = [
                    'conversation_history' => $conversationHistory,
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                ];

                // Skip action generation if user is providing data for existing incomplete action
                $skipActionGeneration = false;
                if (isset($intentAnalysis['intent']) && $intentAnalysis['intent'] === 'provide_data') {
                    $pendingAction = $this->pendingActionService?->get($sessionId);
                    if ($pendingAction) {
                        $skipActionGeneration = true;
                        Log::channel('ai-engine')->info('Skipping action generation - user providing data for pending action', [
                            'pending_action' => $pendingAction->label,
                            'intent' => 'provide_data',
                        ]);
                    }
                }

                if (!$skipActionGeneration) {
                    Log::channel('ai-engine')->info('Generating actions for context', [
                        'message' => $processedMessage,
                        'has_manager' => $this->actionManager !== null,
                        'conversation_history_count' => count($conversationHistory),
                        'intent' => $intentAnalysis['intent'] ?? null,
                    ]);

                    $actions = $this->actionManager->generateActionsForContext(
                        $processedMessage,
                        $context,
                        $intentAnalysis
                    );

                    Log::channel('ai-engine')->info('Actions generated', [
                        'count' => count($actions),
                        'actions' => array_map(fn($a) => $a->label, $actions),
                    ]);

                    // Store actions in smartActions variable for consistency
                    $smartActions = $actions;
                } else {
                    // No new actions, will use pending action
                    $smartActions = [];
                }

                // Add actions to metadata
                $metadata = array_merge($context, ['smart_actions' => $smartActions]);

                // If no actions generated, check if there's a completed pending action from provide_data flow
                if (empty($smartActions)) {
                    $pendingAction = $this->pendingActionService?->get($sessionId);
                    $cachedActionData = $pendingAction ? [
                        'id' => $pendingAction->id,
                        'type' => $pendingAction->type->value,
                        'label' => $pendingAction->label,
                        'description' => $pendingAction->description,
                        'data' => $pendingAction->data,
                        'missing_fields' => $pendingAction->data['missing_fields'] ?? [],
                        'is_incomplete' => !empty($pendingAction->data['missing_fields'] ?? []),
                    ] : null;
                    
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

                    // Try to get pending action from database
                    $pendingAction = $this->pendingActionService?->get($sessionId);
                    $cachedActionData = $pendingAction ? [
                        'id' => $pendingAction->id,
                        'type' => $pendingAction->type->value,
                        'label' => $pendingAction->label,
                        'description' => $pendingAction->description,
                        'data' => $pendingAction->data,
                        'missing_fields' => $pendingAction->data['missing_fields'] ?? [],
                        'is_incomplete' => !empty($pendingAction->data['missing_fields'] ?? []),
                    ] : null;

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
                    // PRIORITY 1: Check if AI intent analysis suggested a specific action
                    $suggestedActionId = $intentAnalysis['suggested_action_id'] ?? null;
                    $aiSuggestedAction = null;
                    
                    if ($suggestedActionId) {
                        // Find the action that matches AI's suggestion
                        foreach ($smartActions as $action) {
                            $actionId = $action->data['action_id'] ?? '';
                            // Match by action_id (with or without unique suffix)
                            if ($actionId === $suggestedActionId || str_starts_with($actionId, $suggestedActionId . '_')) {
                                $aiSuggestedAction = $action;
                                Log::channel('ai-engine')->info('AI suggested action found', [
                                    'suggested_id' => $suggestedActionId,
                                    'matched_action' => $actionId,
                                    'label' => $action->label,
                                ]);
                                break;
                            }
                        }
                    }
                    
                    // PRIORITY 2: If no AI suggestion, sort by confidence and keyword relevance
                    if (!$aiSuggestedAction) {
                        $messageLower = strtolower($processedMessage);
                        
                        usort($smartActions, function($a, $b) use ($messageLower) {
                            $confA = $a->data['confidence'] ?? 0;
                            $confB = $b->data['confidence'] ?? 0;
                            
                            // If confidence is equal, prioritize by keyword match
                            if (abs($confA - $confB) < 0.01) {
                                // Extract model name from action
                                $modelA = class_basename($a->data['model_class'] ?? '');
                                $modelB = class_basename($b->data['model_class'] ?? '');
                                
                                // Check if message contains model keywords
                                $matchA = str_contains($messageLower, strtolower($modelA)) ? 1 : 0;
                                $matchB = str_contains($messageLower, strtolower($modelB)) ? 1 : 0;
                                
                                // Also check for common variations
                                if (!$matchA && str_contains($modelA, 'Product')) {
                                    $matchA = str_contains($messageLower, 'product') ? 1 : 0;
                                }
                                if (!$matchB && str_contains($modelB, 'Product')) {
                                    $matchB = str_contains($messageLower, 'product') ? 1 : 0;
                                }
                                
                                if ($matchA !== $matchB) {
                                    return $matchB <=> $matchA; // Higher match first
                                }
                            }
                            
                            return $confB <=> $confA; // Descending order by confidence
                        });
                    }
                    
                    // Select the action to cache
                    if ($aiSuggestedAction) {
                        // Use AI's suggestion (highest priority)
                        $actionToCache = $aiSuggestedAction;
                    } else {
                        // Fallback: Use first action (already sorted by confidence and keyword match)
                        // The first action is the most relevant based on our sorting logic
                        $actionToCache = $smartActions[0];
                    }
                    
                    $isIncomplete = !($actionToCache->data['ready_to_execute'] ?? true);
                    $missingFields = $actionToCache->data['missing_fields'] ?? [];
                    
                    // If action is incomplete, override response to ask for missing information
                    if ($isIncomplete && !empty($missingFields)) {
                        $modelClass = $actionToCache->data['model_class'] ?? '';
                        $modelName = class_basename($modelClass);
                        
                        // Store the incomplete action in database so we can continue the conversation
                        $this->pendingActionService?->store($sessionId, $actionToCache, $userId);
                        
                        Log::channel('ai-engine')->info('Stored incomplete action for continuation', [
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

                    // Store pending action in database for confirmation
                    $this->pendingActionService?->store($sessionId, $actionToCache, $userId);

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
        $prompt .= "\n\nCRITICAL: When users provide specific names, values, or details in their request, you MUST use EXACTLY what they specified.";
        $prompt .= "\nNEVER substitute, change, or replace user-provided values with different ones from your training data or examples.";

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
                    $prompt .= "\nIMPORTANT: User is providing ADDITIONAL DATA. Acknowledge receipt, show ALL collected information, and ask if they want to proceed.";
                    $prompt .= "\nCRITICAL: Do NOT ask for more optional fields. Only show what has been collected and ask for confirmation.";
                    break;
                case 'question':
                    $prompt .= "\nIMPORTANT: User has a QUESTION. Provide clear explanation and ask if they're ready to proceed after answering.";
                    break;
                case 'new_request':
                    $prompt .= "\nIMPORTANT: This is a NEW REQUEST. Focus on understanding and extracting parameters for the new action.";
                    $prompt .= "\nCRITICAL: Do NOT reuse parameters from previous requests in this conversation. Each new request requires its own fresh parameters.";
                    $prompt .= "\nIf the user hasn't provided REQUIRED fields, ASK for them. Do NOT ask for optional fields like description, quantity, or category.";
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
        $prompt .= "\n- For example, if you listed '1. [Topic A]' and user says '1', provide detailed information about [Topic A]";
        $prompt .= "\n- NEVER say the question is incomplete when user sends a number - they're making a selection!";

        if ($useActions) {
            $prompt .= "\n\nIMPORTANT: You have the ability to CREATE and MANAGE data in this system.";
            $prompt .= "\n- When users ask to create records (any type), you CAN do it!";
            $prompt .= "\n- NEVER say 'I don't have information about creating...' - you have the capability to create records";
            $prompt .= "\n- When a user wants to create something, acknowledge that you can help and present the creation options";
            $prompt .= "\n- Be confident and positive about your ability to create and manage data";

            // Add available actions context
            try {
                $availableActions = $this->actionManager ? $this->actionManager->discoverActions() : [];
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
    protected function getOptionalParamsForAction($action): array
    {
        Log::channel('ai-engine')->debug('getOptionalParamsForAction called', [
            'has_action' => !is_null($action),
            'action_type' => gettype($action),
        ]);
        
        if (!$action) {
            Log::channel('ai-engine')->debug('No action provided, returning empty');
            return [];
        }
        
        $modelClass = $action->data['model_class'] ?? null;
        
        Log::channel('ai-engine')->debug('Extracted model class', [
            'model_class' => $modelClass,
            'class_exists' => $modelClass ? class_exists($modelClass) : false,
        ]);
        
        if (!$modelClass) {
            Log::channel('ai-engine')->debug('No model class provided, returning empty');
            return [];
        }
        
        // For remote models that don't exist in this context, check if AI config is in action data
        if (!class_exists($modelClass)) {
            Log::channel('ai-engine')->debug('Model class not found locally (likely remote model)', [
                'model_class' => $modelClass,
            ]);
            
            // Check if AI config was stored in action data
            $aiConfig = $action->data['ai_config'] ?? null;
            if ($aiConfig && !empty($aiConfig['fields'])) {
                $currentParams = $action->data['params'] ?? [];
                $optionalFields = [];
                
                foreach ($aiConfig['fields'] as $fieldName => $fieldConfig) {
                    $isRequired = $fieldConfig['required'] ?? false;
                    $isAlreadyProvided = isset($currentParams[$fieldName]);
                    $isRelationField = $this->isRelationshipField($fieldName, $fieldConfig);
                    
                    // Skip required fields, already provided fields, and relationship ID fields
                    if (!$isRequired && !$isAlreadyProvided && !$isRelationField) {
                        $optionalFields[] = $fieldName;
                    }
                }
                
                Log::channel('ai-engine')->debug('Got optional params from stored AI config', [
                    'model_class' => $modelClass,
                    'optional_fields' => $optionalFields,
                ]);
                
                return $optionalFields;
            }
            
            // No AI config available for remote model
            Log::channel('ai-engine')->debug('No AI config available for remote model, returning empty');
            return [];
        }
        
        try {
            // Get the action's required and current parameters
            $requiredFields = $action->data['missing_fields'] ?? [];
            $currentParams = $action->data['params'] ?? [];
            
            Log::channel('ai-engine')->debug('Action parameters', [
                'required_fields' => $requiredFields,
                'current_params' => array_keys($currentParams),
            ]);
            
            // PRIORITY 1: Try to get from model's initializeAI() method
            $hasMethod = method_exists($modelClass, 'initializeAI');
            Log::channel('ai-engine')->debug('Checking for initializeAI method', [
                'model_class' => $modelClass,
                'has_method' => $hasMethod,
            ]);
            
            if ($hasMethod) {
                try {
                    $model = new $modelClass();
                    $aiConfig = $model->initializeAI();
                    
                    Log::channel('ai-engine')->debug('initializeAI() called', [
                        'model' => $modelClass,
                        'has_optional' => isset($aiConfig['optional']),
                        'has_fields' => isset($aiConfig['fields']),
                        'config_keys' => array_keys($aiConfig),
                    ]);
                    
                    // Check for 'optional' array (legacy format)
                    if (!empty($aiConfig['optional'])) {
                        $optionalFields = array_filter($aiConfig['optional'], function($field) use ($currentParams) {
                            return !isset($currentParams[$field]);
                        });
                        
                        Log::channel('ai-engine')->debug('Got optional params from initializeAI() (legacy format)', [
                            'model' => $modelClass,
                            'optional_fields' => $optionalFields,
                        ]);
                        
                        return array_values($optionalFields);
                    }
                    
                    // Check for 'fields' array (fluent builder format)
                    if (!empty($aiConfig['fields'])) {
                        $optionalFields = [];
                        foreach ($aiConfig['fields'] as $fieldName => $fieldConfig) {
                            // Field is optional if required is false or not set
                            $isRequired = $fieldConfig['required'] ?? false;
                            $isAlreadyProvided = isset($currentParams[$fieldName]);
                            
                            Log::channel('ai-engine')->debug('Checking field', [
                                'field' => $fieldName,
                                'is_required' => $isRequired,
                                'is_provided' => $isAlreadyProvided,
                                'will_include' => !$isRequired && !$isAlreadyProvided,
                            ]);
                            
                            if (!$isRequired && !$isAlreadyProvided) {
                                $optionalFields[] = $fieldName;
                            }
                        }
                        
                        Log::channel('ai-engine')->debug('Got optional params from initializeAI() (fluent builder)', [
                            'model' => $modelClass,
                            'optional_fields' => $optionalFields,
                            'all_fields' => array_keys($aiConfig['fields']),
                            'current_params' => array_keys($currentParams),
                        ]);
                        
                        return $optionalFields;
                    }
                    
                    Log::channel('ai-engine')->debug('No optional or fields array found in config', [
                        'model' => $modelClass,
                        'config_structure' => json_encode($aiConfig),
                    ]);
                } catch (\Exception $e) {
                    // Continue to fallback
                    Log::channel('ai-engine')->debug('initializeAI() failed, using fallback', [
                        'model' => $modelClass,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
            
            // FALLBACK: Use model's fillable fields
            $model = new $modelClass();
            $fillable = $model->getFillable();
            
            if (empty($fillable)) {
                return [];
            }
            
            // Optional fields = fillable fields that are not required and not already provided
            $optionalFields = array_filter($fillable, function($field) use ($requiredFields, $currentParams) {
                return !in_array($field, $requiredFields) && !isset($currentParams[$field]);
            });
            
            // Exclude common system fields that shouldn't be suggested
            $systemFields = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'workspace_id'];
            $optionalFields = array_diff($optionalFields, $systemFields);
            
            Log::channel('ai-engine')->debug('Got optional params from fillable (fallback)', [
                'model' => $modelClass,
                'optional_fields' => array_values($optionalFields),
            ]);
            
            return array_values($optionalFields);
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Could not get optional params from model', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
            return [];
        }
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
        $prompt .= "I've prepared some suggestions based on your data:\n\n";

        foreach ($missingOptional as $param) {
            $label = ucfirst(str_replace('_', ' ', $param));
            $suggestion = $suggestions[$param] ?? null;
            if ($suggestion !== null) {
                $prompt .= "**{$label}:** {$suggestion}\n";
            }
        }

        $prompt .= "\nYou can:\n";
        $prompt .= "- Say **'yes'** to use these suggestions\n";
        $prompt .= "- Provide different values (e.g., 'SKU: [code], Price: [amount]')\n";
        $prompt .= "- Say **'skip'** to proceed without optional fields";

        return $prompt;
    }

    /**
     * Generate data summary for confirmation using AI-driven formatting
     */
    protected function generateDataSummary(\LaravelAIEngine\DTOs\InteractiveAction $action): string
    {
        $params = $action->data['params'] ?? [];
        $modelClass = $action->data['model_class'] ?? '';
        $modelName = class_basename($modelClass);
        
        // Apply model's normalization before generating summary
        if (method_exists($modelClass, 'normalizeAIData')) {
            try {
                $reflection = new \ReflectionMethod($modelClass, 'normalizeAIData');
                $reflection->setAccessible(true);
                $params = $reflection->invoke(null, $params);
            } catch (\Exception $e) {
                // If normalization fails, continue with original params
            }
        }
        
        // Use AI to generate formatted summary from normalized data
        try {
            $prompt = "Format the following {$modelName} data into a clear, user-friendly confirmation summary.\n\n";
            $prompt .= "Data:\n" . json_encode($params, JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "Requirements:\n";
            $prompt .= "- Start with '**Summary of Information:**'\n";
            $prompt .= "- Intelligently display relevant information based on the data structure:\n";
            $prompt .= "  * If there's entity info (person/organization), show relevant contact details\n";
            $prompt .= "  * If there are collection arrays, show them in a numbered list with details\n";
            $prompt .= "  * For array fields, show nested values INSIDE the array items, not as top-level fields\n";
            $prompt .= "  * If there are dates (created, issued, due, scheduled), show meaningful ones\n";
            $prompt .= "  * If there's a total/amount/price, display it\n";
            $prompt .= "- SKIP internal/technical fields:\n";
            $prompt .= "  * id, user_id, workspace, created_by, account_id, category_id\n";
            $prompt .= "  * Any field ending in _id (except meaningful references)\n";
            $prompt .= "  * Fields like: account_type, module names, display flags\n";
            $prompt .= "  * _resolve_relationships and other internal metadata\n";
            $prompt .= "  * Any field with value 0 or null that's not meaningful\n";
            $prompt .= "- Format currency values with $ symbol\n";
            $prompt .= "- Use bold for section headers\n";
            $prompt .= "- Keep it clean, concise, and user-friendly\n";
            $prompt .= "- Adapt the format to the type of data (works for any model type)\n";
            $prompt .= "- End with: '**Please review the information above.**\\nReply 'yes' to create, or tell me what you'd like to change.'\n\n";
            $prompt .= "Generate the formatted summary now:";
            
            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from(config('ai-engine.actions.intent_model', 'gpt-3.5-turbo')),
                maxTokens: 500,
                temperature: 0
            );
            
            $response = $this->aiEngineService->generate($aiRequest);
            return $response->getContent();
            
        } catch (\Exception $e) {
            // Fallback to simple formatting if AI fails
            return $this->generateSimpleSummary($params, $modelClass);
        }
    }
    
    /**
     * Fallback simple summary generation
     */
    protected function generateSimpleSummary(array $params, string $modelClass): string
    {
        $fieldDefinitions = $this->getModelFieldDefinitions($modelClass);
        $summary = "**Summary of Information:**\n\n";
        
        foreach ($params as $key => $value) {
            if ($key === '_resolve_relationships') {
                continue;
            }
            
            if ($key === 'quantity' && isset($params['items']) && is_array($params['items'])) {
                continue;
            }
            
            $fieldDef = $fieldDefinitions[$key] ?? null;
            
            if (is_array($value)) {
                $summary .= $this->formatArrayField($key, $value, $fieldDef);
            } else {
                $summary .= $this->formatScalarField($key, $value, $fieldDef);
            }
        }
        
        $summary .= "\n**Please review the information above.**\nReply 'yes' to create, or tell me what you'd like to change.";
        
        return $summary;
    }

    /**
     * Get field definitions from model
     */
    protected function getModelFieldDefinitions(string $modelClass): array
    {
        $definitions = [];
        
        try {
            // Try getFunctionSchema first (most detailed)
            if (method_exists($modelClass, 'getFunctionSchema')) {
                $schema = $modelClass::getFunctionSchema();
                $properties = $schema['parameters']['properties'] ?? [];
                
                foreach ($properties as $fieldName => $fieldSchema) {
                    $definitions[$fieldName] = [
                        'type' => $fieldSchema['type'] ?? 'string',
                        'description' => $fieldSchema['description'] ?? null,
                        'format' => $fieldSchema['format'] ?? null,
                        'items' => $fieldSchema['items'] ?? null,
                    ];
                }
            }
            // Fallback to initializeAI
            elseif (method_exists($modelClass, 'initializeAI')) {
                $config = (new $modelClass)->initializeAI();
                $fields = $config['fields'] ?? [];
                
                foreach ($fields as $fieldName => $fieldInfo) {
                    if (is_array($fieldInfo)) {
                        $definitions[$fieldName] = $fieldInfo;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback to empty definitions
        }
        
        return $definitions;
    }

    /**
     * Format array field dynamically based on definition
     */
    protected function formatArrayField(string $key, array $value, ?array $fieldDef): string
    {
        if (empty($value)) {
            return '';
        }
        
        $label = $fieldDef['description'] ?? ucfirst(str_replace('_', ' ', $key));
        $output = "**{$label}:**\n";
        
        // Get item schema if available
        $itemSchema = $fieldDef['items']['properties'] ?? null;
        
        foreach ($value as $index => $item) {
            $itemNum = $index + 1;
            
            if (is_array($item)) {
                // Get primary field (name, title, item, etc.)
                $primaryField = $item['name'] ?? $item['item'] ?? $item['title'] ?? $item['product_name'] ?? 'Item';
                $output .= "{$itemNum}. {$primaryField}\n";
                
                // Display all other fields dynamically from the item
                foreach ($item as $fieldKey => $fieldValue) {
                    // Skip primary fields and description
                    if (in_array($fieldKey, ['name', 'item', 'title', 'product_name', 'description'])) {
                        continue;
                    }
                    
                    // Get field info from schema
                    $fieldType = $itemSchema[$fieldKey]['type'] ?? null;
                    $fieldLabel = $itemSchema[$fieldKey]['description'] ?? ucfirst(str_replace('_', ' ', $fieldKey));
                    
                    // Format based on type
                    if ($fieldType === 'number' || in_array($fieldKey, ['price', 'amount', 'cost', 'total'])) {
                        $output .= "   - {$fieldLabel}: $" . number_format($fieldValue, 2) . "\n";
                    } elseif ($fieldType === 'integer' || in_array($fieldKey, ['quantity', 'qty', 'count'])) {
                        $output .= "   - {$fieldLabel}: {$fieldValue}\n";
                    } else {
                        $output .= "   - {$fieldLabel}: {$fieldValue}\n";
                    }
                }
            } else {
                // Simple value
                $output .= "{$itemNum}. {$item}\n";
            }
        }
        
        return $output;
    }

    /**
     * Format scalar field based on definition
     */
    protected function formatScalarField(string $key, $value, ?array $fieldDef): string
    {
        $fieldType = $fieldDef['type'] ?? 'string';
        $label = $fieldDef['description'] ?? ucfirst(str_replace('_', ' ', $key));
        
        // Format based on type
        if ($fieldType === 'number' || in_array($key, ['price', 'total', 'amount', 'cost'])) {
            return "- **{$label}:** $" . number_format($value, 2) . "\n";
        } elseif ($fieldType === 'boolean') {
            return "- **{$label}:** " . ($value ? 'Yes' : 'No') . "\n";
        } elseif ($fieldType === 'date' || str_ends_with($key, '_date')) {
            return "- **{$label}:** " . date('Y-m-d', strtotime($value)) . "\n";
        } else {
            return "- **{$label}:** {$value}\n";
        }
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

            // Build context from existing params
            $contextInfo = "Product/Service: \"{$entityName}\"\n";
            if (isset($currentParams['type'])) {
                $contextInfo .= "Type: {$currentParams['type']}\n";
            }
            if (isset($currentParams['category'])) {
                $contextInfo .= "Category: {$currentParams['category']}\n";
            }
            if (isset($currentParams['sale_price'])) {
                $contextInfo .= "Price: \${$currentParams['sale_price']}\n";
            }

            // Generic prompt that works for any entity type
            $fieldDescriptions = array_map(function($param) {
                return "- {$param}: Provide a realistic value for this field";
            }, $optionalParams);

            $prompt = "Based on this information:\n{$contextInfo}\n";
            $prompt .= "Generate intelligent, realistic suggestions for these fields:\n";
            $prompt .= implode("\n", $fieldDescriptions) . "\n\n";
            $prompt .= "IMPORTANT: If a category is already provided above, use EXACTLY the same category value.\n";
            $prompt .= "Return ONLY a valid JSON object with these exact keys.";

            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                systemPrompt: 'You are a data assistant. Generate professional, realistic information. Return valid JSON only. Maintain consistency with provided context.',
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

                // Ensure category consistency - if category exists in currentParams, use it
                if (isset($currentParams['category']) && isset($suggestions['category'])) {
                    $suggestions['category'] = $currentParams['category'];
                }

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
                $prompt .= "Format: {\"field_name\": \"value\"}\n";
                $prompt .= "IMPORTANT: Extract ONLY actual values from the user's message, not placeholder examples.\n";
                $prompt .= "CRITICAL: Do NOT use values from conversation history or previous messages. Extract ONLY from this specific message.\n";

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
    protected function analyzeMessageIntent(string $message, ?array $pendingAction = null, array $availableActions = []): array
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
            
            // Note: AI-based action selection disabled due to empty AI responses
            // The enhanced semantic matching in ActionManager will handle action selection

            if ($pendingAction) {
                $prompt .= "Context: There is a pending action waiting for user response.\n";
                $prompt .= "Pending Action: {$pendingAction['label']}\n";
                $prompt .= "Current Parameters: " . json_encode($pendingAction['data']['params'] ?? []) . "\n";
                
                $isComplete = empty($pendingAction['missing_fields'] ?? []);
                $missingFields = $pendingAction['missing_fields'] ?? [];
                
                if (!empty($missingFields)) {
                    $prompt .= "Missing Required Fields: " . implode(', ', $missingFields) . "\n";
                    $prompt .= "CRITICAL INTENT CLASSIFICATION:\n";
                    $prompt .= "- If user provides a simple value (number, text) → ALWAYS classify as 'provide_data' or 'modify'\n";
                    $prompt .= "- DO NOT classify simple values as 'new_request'\n";
                    $prompt .= "- Only classify as 'new_request' if user explicitly says 'create', 'add', 'new', etc.\n\n";
                    $prompt .= "CRITICAL EXTRACTION RULES:\n";
                    $prompt .= "1. If user provides a value, match it to ONE of the missing fields above\n";
                    $prompt .= "2. Use the EXACT field name from the missing fields list - DO NOT invent or change field names\n";
                    $prompt .= "3. Extract as: {\"exact_field_name_from_list\": user_provided_value}\n\n";
                    $prompt .= "Examples (using actual missing fields):\n";
                    foreach ($missingFields as $field) {
                        $prompt .= "- If user says '600' and missing field is '{$field}' → classify as 'provide_data', extract: {\"{$field}\": 600}\n";
                    }
                    $prompt .= "\nData Formatting:\n";
                    $prompt .= "- Extract numeric values as numbers (remove currency symbols)\n";
                    $prompt .= "- Extract email addresses in standard format\n";
                    $prompt .= "- Extract quantities as numbers only\n";
                    $prompt .= "- Extract text values as strings\n\n";
                    $prompt .= "CRITICAL: NEVER invent field names. ONLY use field names from the missing fields list above.\n";
                    $prompt .= "CRITICAL: NEVER classify simple values as 'new_request' when there are missing fields.\n";
                } else {
                    $prompt .= "Status: Action is COMPLETE and awaiting confirmation.\n";
                    $prompt .= "CRITICAL DECISION RULES:\n";
                    $prompt .= "1. If user provides ADDITIONAL/OPTIONAL data for the SAME entity (e.g., 'Category Laptops', 'Color Red'):\n";
                    $prompt .= "   → Classify as 'provide_data' (user is enhancing the existing action)\n";
                    $prompt .= "   → Extract the field and value\n";
                    $prompt .= "2. If user explicitly requests creating a DIFFERENT entity type (e.g., 'Create Invoice', 'Add Customer'):\n";
                    $prompt .= "   → Classify as 'new_request' (user wants to start something new)\n";
                    $prompt .= "3. If user provides a simple field value without 'create' or 'add' keywords:\n";
                    $prompt .= "   → Classify as 'provide_data' (assume it's for the pending action)\n";
                    $prompt .= "Examples:\n";
                    $prompt .= "- 'Category Laptops' → provide_data (adding optional field to existing action)\n";
                    $prompt .= "- 'Description: High performance laptop' → provide_data (adding optional field)\n";
                    $prompt .= "- 'Create Invoice' → new_request (explicitly requesting different entity)\n";
                    $prompt .= "- 'Add Customer John' → new_request (explicitly requesting different entity)\n";
                }
                $prompt .= "\n";
            }

            $prompt .= "Analyze and classify the intent into ONE of these categories:\n";
            $prompt .= "1. 'confirm' - User agrees/confirms to proceed (yes, ok, go ahead, I don't mind, sounds good, etc.)\n";
            $prompt .= "2. 'reject' - User declines/cancels (no, cancel, stop, nevermind, etc.)\n";
            $prompt .= "3. 'use_suggestions' - User wants to use AI-generated suggestions (use suggestions, accept suggestions, apply suggestions, etc.)\n";
            $prompt .= "4. 'modify' - User wants to change/update parameters. Pattern:\n";
            $prompt .= "   - 'change [field] to [value]' → extract as: {\"[field]\": [value]}\n";
            $prompt .= "   - 'make it [value] instead' → extract field from context\n";
            $prompt .= "   - '[field] should be [value]' → extract as: {\"[field]\": [value]}\n";
            $prompt .= "   - 'update [field] to [value]' → extract as: {\"[field]\": [value]}\n";
            $prompt .= "   - Any message providing a field name/value pair to update existing data\n";
            $prompt .= "   IMPORTANT: Extract ONLY the actual values from user's message, never use placeholder examples\n";
            $prompt .= "5. 'provide_data' - User is providing additional data for optional parameters\n";
            $prompt .= "6. 'question' - User is asking a question or needs clarification\n";
            $prompt .= "7. 'new_request' - User is making a completely new request\n\n";
            
            // Add document type analysis for long-form content (from config)
            $docTypeConfig = config('ai-engine.project_context.document_type_detection');
            $isLongContent = strlen($message) > ($docTypeConfig['min_length'] ?? 500);
            
            if ($isLongContent && !$pendingAction && ($docTypeConfig['enabled'] ?? true)) {
                $prompt .= "DOCUMENT TYPE ANALYSIS (for messages >" . ($docTypeConfig['min_length'] ?? 500) . " chars):\n";
                $prompt .= "If the message contains structured document data, analyze the business relationship:\n\n";
                
                $rules = $docTypeConfig['rules'] ?? [];
                if (!empty($rules)) {
                    $prompt .= "**CRITICAL: Determine document type based on these rules:**\n";
                    foreach ($rules as $type => $rule) {
                        $indicators = implode(', ', $rule['indicators'] ?? []);
                        $prompt .= "- {$rule['description']}\n";
                        $prompt .= "  Indicators: {$indicators}\n";
                        $prompt .= "  → Suggest: '{$rule['suggested_collection']}'\n";
                        $prompt .= "  Reasoning: {$rule['reasoning']}\n\n";
                    }
                    
                    $prompt .= "Add to response: \"suggested_collection\": \"" . implode('" or "', array_column($rules, 'suggested_collection')) . "\"\n\n";
                }
            }

            $prompt .= "CRITICAL RULES:\n";
            $prompt .= "- NEVER use example values from these instructions - ONLY extract actual values from the user's message\n";
            $prompt .= "- If user says 'change [item] price to X', extract as: {\"[item]_price\": X}\n";
            $prompt .= "- If user says 'change price to X' without item name, extract as: {\"price\": X}\n";
            $prompt .= "- For item-specific updates, ALWAYS use pattern: {item_name}_{field_name}\n";
            $prompt .= "- Extract numeric values without currency symbols\n";
            $prompt .= "- Classify as 'modify' when user wants to change ANY existing field value\n";
            $prompt .= "- Classify as 'new_request' when user wants to create a DIFFERENT item/entity (e.g., 'Create Product B' after 'Create Product A')\n";
            $prompt .= "- When analyzing user input, ignore all example values in this prompt and focus ONLY on what the user actually said\n\n";

            $prompt .= "Respond with ONLY valid JSON in this exact format (NO trailing commas):\n";
            $prompt .= "{\n";
            $prompt .= '  "intent": "confirm|reject|modify|provide_data|question|new_request",'."\n";
            $prompt .= '  "confidence": 0.95,'."\n";
            $prompt .= '  "extracted_data": {"field_name": "value"},'."\n";
            $prompt .= '  "context_enhancement": "Brief description of what user wants"'."\n";
            $prompt .= "}\n\n";
            $prompt .= "CRITICAL: Do NOT include trailing commas. Do NOT include optional fields (suggested_action_id, suggested_collection) unless explicitly needed.\n";
            $prompt .= "The JSON must be valid and parseable.";

            // Use faster/cheaper model for simple intent analysis
            $intentModel = config('ai-engine.actions.intent_model', 'gpt-3.5-turbo');
            
            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from($intentModel),
                maxTokens: 500, // Increased for action list
                temperature: 0
            );

            Log::channel('ai-engine')->debug('Sending intent analysis request', [
                'prompt_length' => strlen($prompt),
                'model' => $intentModel,
            ]);

            $response = $this->aiEngineService->generate($aiRequest);
            
            // Handle AI engine errors gracefully
            if (!$response->success) {
                $errorMessage = $this->getErrorMessage($response->error);
                
                Log::channel('ai-engine')->warning('Intent analysis failed due to AI error', [
                    'error' => $response->error,
                    'user_message' => $errorMessage,
                ]);
                
                // Return fallback intent with error context
                return [
                    'intent' => 'new_request',
                    'confidence' => 0.5,
                    'extracted_data' => [],
                    'context_enhancement' => 'AI service unavailable, using fallback.',
                    'ai_error' => $errorMessage,
                ];
            }
            
            $content = $response->getContent();
            
            Log::channel('ai-engine')->debug('Received intent analysis response', [
                'content_length' => strlen($content),
                'content_preview' => substr($content, 0, 200),
            ]);
            
            // Try to extract JSON from response (handle markdown code blocks)
            $jsonContent = $content;
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $jsonContent = $matches[1];
            } elseif (preg_match('/(\{.*\})/s', $content, $matches)) {
                $jsonContent = $matches[1];
            }
            
            $result = json_decode($jsonContent, true);

            if (is_array($result) && isset($result['intent'])) {
                // Validate extracted data field names against missing fields (prevent hallucination)
                if (!empty($result['extracted_data']) && $pendingAction && !empty($pendingAction['missing_fields'] ?? [])) {
                    $missingFields = $pendingAction['missing_fields'];
                    $extractedFields = array_keys($result['extracted_data']);
                    $validExtracted = [];
                    
                    foreach ($result['extracted_data'] as $fieldName => $value) {
                        // Check if extracted field name matches any missing field
                        if (in_array($fieldName, $missingFields)) {
                            $validExtracted[$fieldName] = $value;
                        } else {
                            // AI hallucinated a field name - try to map it to a missing field
                            Log::channel('ai-engine')->warning('AI extracted invalid field name', [
                                'extracted_field' => $fieldName,
                                'missing_fields' => $missingFields,
                                'value' => $value,
                            ]);
                            
                            // If there's only one missing field, assume the value is for that field
                            if (count($missingFields) === 1) {
                                $correctField = $missingFields[0];
                                $validExtracted[$correctField] = $value;
                                Log::channel('ai-engine')->info('Corrected hallucinated field name', [
                                    'from' => $fieldName,
                                    'to' => $correctField,
                                    'value' => $value,
                                ]);
                            }
                        }
                    }
                    
                    $result['extracted_data'] = $validExtracted;
                }
                
                Log::channel('ai-engine')->debug('Intent analysis parsed successfully', [
                    'intent' => $result['intent'],
                    'has_suggested_action' => isset($result['suggested_action_id']),
                    'suggested_action_id' => $result['suggested_action_id'] ?? null,
                    'extracted_data' => $result['extracted_data'] ?? [],
                ]);
                return $result;
            }

            // Log parsing failure for debugging
            Log::channel('ai-engine')->warning('Failed to parse intent JSON', [
                'raw_content' => substr($content, 0, 500),
                'json_error' => json_last_error_msg(),
            ]);

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
                    if ($this->actionManager) {
                        try {
                            $context = [
                                'conversation_history' => $conversationHistory,
                                'user_id' => $message['user_id'] ?? null,
                                'session_id' => $message['session_id'] ?? null,
                            ];
                            $actions = $this->actionManager->generateActionsForContext($content, $context, null);

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

            case 'model.remote':
                return $this->executeRemoteModelAction($action, $params, $userId);

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
     * Execute remote model action on a remote node
     */
    protected function executeRemoteModelAction(\LaravelAIEngine\DTOs\InteractiveAction $action, array $params, $userId): array
    {
        try {
            $remoteActionService = app(\LaravelAIEngine\Services\Node\RemoteActionService::class);
            
            $nodeSlug = $action->data['node_slug'] ?? null;
            $modelClass = $action->data['model_class'] ?? null;
            
            if (!$nodeSlug || !$modelClass) {
                return [
                    'success' => false,
                    'error' => 'Missing node or model information'
                ];
            }
            
            Log::channel('ai-engine')->info('Executing remote model action', [
                'node_slug' => $nodeSlug,
                'model_class' => $modelClass,
                'params' => $params,
            ]);
            
            // Call remote node's action execution endpoint
            // ActionExecutionService expects executor in data for routing
            $result = $remoteActionService->executeOn($nodeSlug, 'model.create', [
                'executor' => 'model.create',
                'model_class' => $modelClass,
                'params' => array_merge($params, ['user_id' => $userId]),
            ]);
            
            // Extract the actual result from the response
            // RemoteActionService returns: {node, node_name, status_code, data: {success, action_type, result}}
            $apiResponse = $result['data'] ?? [];
            $actionResult = $apiResponse['result'] ?? [];
            
            if (($actionResult['success'] ?? false) || ($result['status_code'] ?? 0) === 200) {
                $modelName = class_basename($modelClass);
                $productData = $actionResult['data'] ?? null;
                
                Log::channel('ai-engine')->info('Remote action executed successfully', [
                    'model' => $modelName,
                    'node' => $nodeSlug,
                    'result' => $actionResult,
                ]);
                
                // Build detailed success message with product summary
                $message = $actionResult['message'] ?? "✅ {$modelName} created successfully!";
                
                if ($productData && is_array($productData)) {
                    $message .= "\n\n**Created {$modelName} Summary:**\n";
                    
                    // Add product details
                    if (isset($productData['name'])) {
                        $message .= "- **Name:** {$productData['name']}\n";
                    }
                    if (isset($productData['sale_price'])) {
                        $message .= "- **Price:** \${$productData['sale_price']}\n";
                    }
                    if (isset($productData['type'])) {
                        $message .= "- **Type:** " . ucfirst($productData['type']) . "\n";
                    }
                    if (isset($productData['sku']) && !empty($productData['sku'])) {
                        $message .= "- **SKU:** {$productData['sku']}\n";
                    }
                    if (isset($productData['id'])) {
                        $message .= "- **ID:** {$productData['id']}\n";
                    }
                    // Only show category if it exists and is not the same as product name
                    if (isset($productData['category']['name']) && 
                        $productData['category']['name'] !== $productData['name']) {
                        $message .= "- **Category:** {$productData['category']['name']}\n";
                    }
                }
                
                return [
                    'success' => true,
                    'message' => $message,
                    'data' => $productData,
                ];
            }
            
            return [
                'success' => false,
                'error' => $actionResult['error'] ?? $apiResponse['error'] ?? 'Remote action failed',
                'data' => $result
            ];
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Remote action execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'error' => 'Remote execution failed: ' . $e->getMessage()
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
                // Model instance returned - use AI to format success message
                $attributes = method_exists($result, 'toArray') ? $result->toArray() : (array) $result;
                
                // Use AI to generate formatted success message
                try {
                    $prompt = "Format a user-friendly success message for the following {$modelName} that was just created.\n\n";
                    $prompt .= "Data:\n" . json_encode($attributes, JSON_PRETTY_PRINT) . "\n\n";
                    $prompt .= "Requirements:\n";
                    $prompt .= "- Start with '✅ **{$modelName} Created Successfully**'\n";
                    $prompt .= "- Intelligently display relevant information based on the data structure:\n";
                    $prompt .= "  * If there's entity info (person/organization), show relevant contact details\n";
                    $prompt .= "  * If there are collection items, show them in a numbered list with details\n";
                    $prompt .= "  * If there are dates (created, issued, due, scheduled), show meaningful ones\n";
                    $prompt .= "  * If there's a total/amount/price, display it prominently\n";
                    $prompt .= "  * If there's a reference ID (invoice_id, order_id, ticket_id, etc.), show it\n";
                    $prompt .= "- SKIP internal/technical fields:\n";
                    $prompt .= "  * id, user_id, workspace, created_by, account_id, category_id\n";
                    $prompt .= "  * Any field ending in _id (except meaningful IDs like invoice_id, order_id)\n";
                    $prompt .= "  * Fields like: account_type, module names, display flags\n";
                    $prompt .= "  * _resolve_relationships and other internal metadata\n";
                    $prompt .= "  * Any field with value 0 or null that's not meaningful\n";
                    $prompt .= "- Format currency values with $ symbol and 2 decimals\n";
                    $prompt .= "- Use bold for section headers\n";
                    $prompt .= "- Keep it clean, concise, and user-friendly\n";
                    $prompt .= "- Adapt the format to the type of data (don't force invoice-specific structure on other models)\n\n";
                    $prompt .= "Generate the formatted success message now:";
                    
                    $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                        prompt: $prompt,
                        engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                        model: \LaravelAIEngine\Enums\EntityEnum::from(config('ai-engine.actions.intent_model', 'gpt-3.5-turbo')),
                        maxTokens: 500,
                        temperature: 0
                    );
                    
                    $response = $this->aiEngineService->generate($aiRequest);
                    $summary = $response->getContent();
                    
                } catch (\Exception $e) {
                    // Fallback to simple message if AI fails
                    $summary = "✅ **{$modelName} Created Successfully**\n\n";
                    $summary .= "ID: " . ($attributes['id'] ?? 'N/A') . "\n";
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
                        $data = $result['data'] ?? $params;
                        
                        // Use AI to generate formatted success message for remote execution
                        try {
                            $prompt = "Format a user-friendly success message for the following {$modelName} that was just created on a remote node.\n\n";
                            $prompt .= "Data:\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";
                            $prompt .= "Requirements:\n";
                            $prompt .= "- Start with '✅ **{$modelName} Created Successfully**'\n";
                            $prompt .= "- Intelligently display relevant information based on the data structure:\n";
                            $prompt .= "  * If there's customer/client/user info, show it (name, email, phone)\n";
                            $prompt .= "  * If there are items/products/lines, show them in a numbered list with details\n";
                            $prompt .= "  * If there are dates (created, issued, due, scheduled), show meaningful ones\n";
                            $prompt .= "  * If there's a total/amount/price, display it prominently\n";
                            $prompt .= "  * If there's a reference ID (invoice_id, order_id, ticket_id, etc.), show it\n";
                            $prompt .= "- SKIP internal/technical fields:\n";
                            $prompt .= "  * id, user_id, workspace, created_by, account_id, category_id\n";
                            $prompt .= "  * Any field ending in _id (except meaningful IDs like invoice_id, order_id)\n";
                            $prompt .= "  * Fields like: account_type, module names, display flags\n";
                            $prompt .= "  * _resolve_relationships and other internal metadata\n";
                            $prompt .= "  * Any field with value 0 or null that's not meaningful\n";
                            $prompt .= "- Format currency values with $ symbol and 2 decimals\n";
                            $prompt .= "- Use bold for section headers\n";
                            $prompt .= "- Keep it clean, concise, and user-friendly\n";
                            $prompt .= "- Adapt the format to the type of data (don't force specific structure on other models)\n\n";
                            $prompt .= "Generate the formatted success message now:";
                            
                            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                                prompt: $prompt,
                                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                                model: \LaravelAIEngine\Enums\EntityEnum::from(config('ai-engine.actions.intent_model', 'gpt-3.5-turbo')),
                                maxTokens: 500,
                                temperature: 0
                            );
                            
                            $response = $this->aiEngineService->generate($aiRequest);
                            $summary = $response->getContent();
                            
                        } catch (\Exception $e) {
                            // Fallback to simple message if AI fails
                            $summary = "✅ **{$modelName} Created Successfully**\n\n";
                            if (isset($data['id'])) {
                                $summary .= "ID: " . $data['id'] . "\n";
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
     * Get user-friendly error message based on AI error
     */
    protected function getErrorMessage(?string $error): string
    {
        if (!$error) {
            return config('ai-engine.error_handling.fallback_message', 'AI service is temporarily unavailable.');
        }

        $showDetailed = config('ai-engine.error_handling.show_detailed_errors', false);
        $showQuota = config('ai-engine.error_handling.show_quota_errors', true);
        $errorMessages = config('ai-engine.error_handling.error_messages', []);

        // Check for specific error types
        if (str_contains(strtolower($error), 'quota') || str_contains(strtolower($error), 'exceeded')) {
            return $showQuota 
                ? ($errorMessages['quota_exceeded'] ?? 'AI service quota exceeded.')
                : config('ai-engine.error_handling.fallback_message', 'AI service is temporarily unavailable.');
        }

        if (str_contains(strtolower($error), 'rate limit')) {
            return $errorMessages['rate_limit'] ?? 'Too many requests. Please try again later.';
        }

        if (str_contains(strtolower($error), 'api key') || str_contains(strtolower($error), 'authentication')) {
            return $errorMessages['invalid_api_key'] ?? 'AI service configuration error.';
        }

        if (str_contains(strtolower($error), 'timeout')) {
            return $errorMessages['timeout'] ?? 'AI service request timed out.';
        }

        if (str_contains(strtolower($error), 'model') && str_contains(strtolower($error), 'not found')) {
            return $errorMessages['model_not_found'] ?? 'The requested AI model is not available.';
        }

        if (str_contains(strtolower($error), 'network') || str_contains(strtolower($error), 'connection')) {
            return $errorMessages['network_error'] ?? 'Unable to connect to AI service.';
        }

        // Return detailed error if enabled, otherwise fallback
        return $showDetailed 
            ? $error 
            : config('ai-engine.error_handling.fallback_message', 'AI service is temporarily unavailable.');
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

    /**
     * Check if a field is a relationship ID field that should not be suggested
     */
    protected function isRelationshipField(string $fieldName, array $fieldConfig): bool
    {
        // Check if field ends with _id (common pattern for foreign keys)
        if (str_ends_with($fieldName, '_id')) {
            return true;
        }

        // Check if field config explicitly marks it as a relationship
        if (isset($fieldConfig['type']) && $fieldConfig['type'] === 'relationship') {
            return true;
        }

        // Check if field config has auto_relationship flag
        if (isset($fieldConfig['auto_relationship']) && $fieldConfig['auto_relationship'] === true) {
            return true;
        }

        return false;
    }
}
