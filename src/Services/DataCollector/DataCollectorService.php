<?php

namespace LaravelAIEngine\Services\DataCollector;

use LaravelAIEngine\DTOs\DataCollectorConfig;
use LaravelAIEngine\DTOs\DataCollectorState;
use LaravelAIEngine\DTOs\DataCollectorField;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing data collection chat sessions
 * 
 * This service orchestrates the conversation flow for collecting structured data
 * from users through natural language interaction.
 */
class DataCollectorService
{
    protected array $registeredConfigs = [];
    protected string $cachePrefix = 'data_collector_state_';
    protected int $cacheTtl = 3600; // 1 hour

    public function __construct(
        protected AIEngineManager $aiEngine,
        protected ?ConversationService $conversationService = null
    ) {}

    /**
     * Register a data collector configuration
     */
    public function registerConfig(DataCollectorConfig $config): self
    {
        $this->registeredConfigs[$config->name] = $config;
        return $this;
    }

    /**
     * Get a registered configuration (from memory, cache, or session state)
     */
    public function getConfig(string $name): ?DataCollectorConfig
    {
        // First check in-memory registry
        if (isset($this->registeredConfigs[$name])) {
            return $this->registeredConfigs[$name];
        }
        
        // Then check cache (for configs created via API)
        $cached = $this->loadConfig($name);
        if ($cached) {
            // Also register in memory for this request
            $this->registeredConfigs[$name] = $cached;
            return $cached;
        }
        
        return null;
    }
    
    /**
     * Get config for a session - checks registry, cache, then embedded state
     */
    protected function getConfigForSession(string $sessionId, DataCollectorState $state): ?DataCollectorConfig
    {
        // 1. Try registry/cache first
        $config = $this->getConfig($state->configName);
        if ($config) {
            return $config;
        }
        
        // 2. Load from embedded config in state
        if ($state->embeddedConfig) {
            $config = DataCollectorConfig::fromArray($state->embeddedConfig);
            // Register for future use in this request
            $this->registerConfig($config);
            
            Log::channel('ai-engine')->debug('Config loaded from embedded state', [
                'session_id' => $sessionId,
                'config_name' => $config->name,
            ]);
            return $config;
        }
        
        return null;
    }

    /**
     * Start a new data collection session
     */
    public function startSession(
        string $sessionId,
        DataCollectorConfig $config,
        array $initialData = []
    ): DataCollectorState {
        // Merge config's initial data with provided initial data (provided takes precedence)
        $mergedInitialData = array_merge($config->initialData, $initialData);
        
        // Determine the first uncollected field (skip fields with initial data)
        $firstUncollectedField = null;
        foreach ($config->getFields() as $fieldName => $field) {
            if (!isset($mergedInitialData[$fieldName]) || empty($mergedInitialData[$fieldName])) {
                $firstUncollectedField = $fieldName;
                break;
            }
        }
        
        $state = new DataCollectorState(
            sessionId: $sessionId,
            configName: $config->name,
            status: DataCollectorState::STATUS_COLLECTING,
            collectedData: $mergedInitialData,
            currentField: $firstUncollectedField,
            embeddedConfig: $config->toArray(),  // Embed config in state for reliable persistence
        );

        // Register config if not already registered
        if (!isset($this->registeredConfigs[$config->name])) {
            $this->registerConfig($config);
        }
        
        // Save config to cache for persistence across requests
        $this->saveConfig($config);

        $this->saveState($state);

        Log::channel('ai-engine')->info('Data collection session started', [
            'session_id' => $sessionId,
            'config' => $config->name,
            'fields' => $config->getFieldNames(),
        ]);

        return $state;
    }

    /**
     * Process a user message in the data collection flow
     * 
     * @param string $sessionId Session identifier
     * @param string $message User's message
     * @param string $engine AI engine to use
     * @param string $model AI model to use
     */
    public function processMessage(
        string $sessionId,
        string $message,
        string $engine = 'openai',
        string $model = 'gpt-4o'
    ): DataCollectorResponse {
        $state = $this->getState($sessionId);
        
        if (!$state) {
            return new DataCollectorResponse(
                success: false,
                message: 'No active data collection session found.',
                state: null,
            );
        }

        // Get config - tries registry, cache, then embedded state
        $config = $this->getConfigForSession($sessionId, $state);
        
        if (!$config) {
            $locale = $state->detectedLocale ?? $state->embeddedConfig['locale'] ?? 'en';
            return new DataCollectorResponse(
                success: false,
                message: $locale === 'ar' 
                    ? 'لم يتم العثور على التكوين.'
                    : 'Configuration not found.',
                state: $state,
            );
        }

        // Check for cancellation
        if ($this->isCancellationRequest($message)) {
            return $this->handleCancellation($state, $config);
        }

        // Auto-detect locale from user message if not already set or if detectLocale is enabled
        if ($config->detectLocale || !isset($state->detectedLocale)) {
            $detectedLocale = $this->detectLocale($message);
            if ($detectedLocale !== 'en') {
                $state->detectedLocale = $detectedLocale;
                Log::channel('ai-engine')->info('Locale auto-detected', [
                    'session_id' => $state->sessionId,
                    'detected_locale' => $detectedLocale,
                ]);
            }
        }
        
        // Add user message to history
        $state->addMessage('user', $message);

        // Process based on current status
        $response = match ($state->status) {
            DataCollectorState::STATUS_COLLECTING => $this->handleCollecting($state, $config, $message, $engine, $model),
            DataCollectorState::STATUS_CONFIRMING => $this->handleConfirming($state, $config, $message, $engine, $model),
            DataCollectorState::STATUS_ENHANCING => $this->handleEnhancing($state, $config, $message, $engine, $model),
            default => new DataCollectorResponse(
                success: false,
                message: ($state->detectedLocale ?? $config->locale ?? 'en') === 'ar'
                    ? 'الجلسة ليست في حالة نشطة.'
                    : 'Session is not in an active state.',
                state: $state,
            ),
        };

        // Save updated state
        $this->saveState($state);

        return $response;
    }

    /**
     * Handle the collecting status - gathering field values
     */
    protected function handleCollecting(
        DataCollectorState $state,
        DataCollectorConfig $config,
        string $message,
        string $engine,
        string $model
    ): DataCollectorResponse {
        // Build the prompt for the AI
        $systemPrompt = $config->getSystemPrompt();
        
        // Add current state context
        $contextPrompt = $this->buildContextPrompt($state, $config);
        
        // Generate AI response (using context summary instead of full history)
        $aiResponse = $this->generateAIResponse(
            $systemPrompt,
            $contextPrompt . "\n\nUser: " . $message,
            [], // Empty history - context prompt provides all needed state
            $engine,
            $model
        );

        $responseContent = $aiResponse->getContent();
        
        // Check if user is selecting a suggestion by number
        $selectedSuggestion = $this->checkSuggestionSelection($message, $state);
        if ($selectedSuggestion !== null) {
            // User selected a suggestion - treat it as providing a value
            $extractedFields = [$state->currentField => $selectedSuggestion];
            
            Log::channel('ai-engine')->info('User selected suggestion by number', [
                'field' => $state->currentField,
                'selected_value' => substr($selectedSuggestion, 0, 100),
            ]);
            
            // Clear suggestions after selection
            unset($state->lastSuggestions);
            
            // Skip intent analysis and go straight to validation
            goto validateAndSave;
        }
        
        // Use intent analysis to intelligently extract field value
        $intentAnalysis = $this->analyzeFieldIntent(
            $message,
            $state->currentField,
            $config,
            $state->getData()
        );
        
        Log::channel('ai-engine')->info('Field intent analysis completed', [
            'session_id' => $state->sessionId,
            'intent' => $intentAnalysis['intent'],
            'confidence' => $intentAnalysis['confidence'],
            'extracted_value' => $intentAnalysis['extracted_value'] ?? null,
            'current_field' => $state->currentField,
        ]);
        
        // Handle different intents from analysis
        $extractedFields = [];
        
        if ($intentAnalysis['intent'] === 'provide_value' && !empty($intentAnalysis['extracted_value'])) {
            // User provided a value - use it
            $extractedFields[$state->currentField] = $intentAnalysis['extracted_value'];
            
            Log::channel('ai-engine')->info('Using value from intent analysis', [
                'field' => $state->currentField,
                'value' => $intentAnalysis['extracted_value'],
                'confidence' => $intentAnalysis['confidence'],
            ]);
        } elseif ($intentAnalysis['intent'] === 'question') {
            // User is asking a question - don't extract, let AI respond
            Log::channel('ai-engine')->info('User asked a question, no extraction needed');
        } elseif ($intentAnalysis['intent'] === 'suggest') {
            // User wants suggestions - generate AI-powered suggestions
            return $this->handleSuggestionRequest($state, $config, $engine, $model);
        } elseif ($intentAnalysis['intent'] === 'skip') {
            // User wants to skip - handle skip logic
            Log::channel('ai-engine')->info('User wants to skip field', [
                'field' => $state->currentField,
            ]);
        } elseif ($intentAnalysis['intent'] === 'unclear') {
            // Message is unclear - let AI ask for clarification
            Log::channel('ai-engine')->info('User message unclear, AI will ask for clarification');
        } else {
            // Fallback: Parse AI response for field extractions
            Log::channel('ai-engine')->info('Intent analysis returned no value, falling back to marker parsing', [
                'intent' => $intentAnalysis['intent'],
            ]);
            $extractedFields = $this->parseFieldExtractions($responseContent, $config);
        }
        
        validateAndSave:
        
        Log::channel('ai-engine')->info('Field extraction results', [
            'session_id' => $state->sessionId,
            'extracted_count' => count($extractedFields),
            'extracted_fields' => array_keys($extractedFields),
            'current_field' => $state->currentField,
            'used_intent_analysis' => !empty($intentAnalysis['extracted_value'] ?? null),
        ]);
        
        // CRITICAL: Only accept extraction for the current field to prevent hallucination
        if ($state->currentField) {
            $beforeFilter = count($extractedFields);
            $extractedFields = $this->filterToCurrentField($extractedFields, $state->currentField, $state->getData());
            $afterFilter = count($extractedFields);
            
            if ($beforeFilter !== $afterFilter) {
                Log::channel('ai-engine')->warning('Fields filtered out', [
                    'before' => $beforeFilter,
                    'after' => $afterFilter,
                    'session_id' => $state->sessionId,
                ]);
            }
        }
        
        // Track if we used direct extraction
        $usedDirectExtraction = false;
        
        // If no fields extracted and we have a current field, try to extract from user message directly
        if (empty($extractedFields) && $state->currentField) {
            Log::channel('ai-engine')->warning('No fields extracted from AI response, trying direct extraction', [
                'session_id' => $state->sessionId,
                'current_field' => $state->currentField,
                'user_message' => $message,
                'response_preview' => substr($responseContent, 0, 200),
                'has_field_collected_marker' => str_contains($responseContent, 'FIELD_COLLECTED:'),
            ]);
            
            // Direct extraction: assume user's message is the value for current field
            Log::channel('ai-engine')->warning('Using direct extraction fallback', [
                'session_id' => $state->sessionId,
                'current_field' => $state->currentField,
                'user_message' => substr($message, 0, 100),
            ]);
            
            $extractedFields = $this->extractFromUserMessage($message, $state->currentField, $config);
            $usedDirectExtraction = !empty($extractedFields);
            
            if ($usedDirectExtraction) {
                Log::channel('ai-engine')->info('Direct extraction succeeded', [
                    'field' => $state->currentField,
                    'value' => $extractedFields[$state->currentField] ?? null,
                ]);
            } else {
                Log::channel('ai-engine')->error('Direct extraction failed - no value extracted', [
                    'session_id' => $state->sessionId,
                    'current_field' => $state->currentField,
                ]);
            }
        }
        
        $validationFailed = false;
        $validationErrorMessage = '';
        
        foreach ($extractedFields as $fieldName => $value) {
            $field = $config->getField($fieldName);
            if ($field) {
                // Validate the value
                $errors = $field->validate($value);
                if (empty($errors)) {
                    $state->setFieldValue($fieldName, $value);
                    $state->clearValidationErrors();
                    Log::channel('ai-engine')->info('Field value saved successfully', [
                        'field' => $fieldName,
                        'value' => substr($value, 0, 100),
                        'session_id' => $state->sessionId,
                        'all_collected' => array_keys($state->getData()),
                    ]);
                } else {
                    $validationFailed = true;
                    $state->setValidationErrors([$fieldName => $errors]);
                    
                    // Build user-friendly error message
                    $locale = $state->detectedLocale ?? $config->locale ?? 'en';
                    if ($locale === 'ar') {
                        $validationErrorMessage = "عذراً، القيمة المقدمة غير صالحة:\n";
                        $validationErrorMessage .= "\n**الأخطاء:**\n";
                        foreach ($errors as $error) {
                            $validationErrorMessage .= "- {$error}\n";
                        }
                        $validationErrorMessage .= "\nيرجى تقديم {$field->description} صالح";
                        if (!empty($field->examples)) {
                            $validationErrorMessage .= " (مثال: " . implode(', ', $field->examples) . ")";
                        }
                    } else {
                        $validationErrorMessage = "Sorry, the value you provided is not valid:\n";
                        $validationErrorMessage .= "\n**Errors:**\n";
                        foreach ($errors as $error) {
                            $validationErrorMessage .= "- {$error}\n";
                        }
                        $validationErrorMessage .= "\nPlease provide a valid {$field->description}";
                        if (!empty($field->examples)) {
                            $validationErrorMessage .= " (e.g., " . implode(', ', $field->examples) . ")";
                        }
                    }
                    
                    Log::channel('ai-engine')->error('Field validation failed - VALUE REJECTED', [
                        'field' => $fieldName,
                        'value' => substr($value, 0, 100),
                        'errors' => $errors,
                        'validation_rules' => $field->validation,
                        'session_id' => $state->sessionId,
                    ]);
                }
            } else {
                Log::channel('ai-engine')->error('Field not found in config', [
                    'attempted_field' => $fieldName,
                    'available_fields' => $config->getFieldNames(),
                ]);
            }
        }

        // Check for completion signal
        if (str_contains($responseContent, 'DATA_COLLECTION_COMPLETE')) {
            return $this->handleCompletion($state, $config, $engine, $model);
        }

        // Check for cancellation signal
        if (str_contains($responseContent, 'DATA_COLLECTION_CANCELLED')) {
            return $this->handleCancellation($state, $config);
        }

        // CRITICAL: If validation failed, override response with error message
        if ($validationFailed && !empty($validationErrorMessage)) {
            $state->setLastAIResponse($validationErrorMessage);
            $state->addMessage('assistant', $validationErrorMessage);
            
            // Don't count the failed field as collected for progress calculation
            return new DataCollectorResponse(
                success: false,
                message: $validationErrorMessage,
                state: $state,
                aiResponse: $aiResponse,
                currentField: $state->currentField,
                collectedFields: array_keys(array_filter($state->getData(), fn($v) => $v !== null && $v !== '')),
                remainingFields: array_keys($config->getUncollectedFields($state->getData())),
                validationErrors: $state->validationErrors,
            );
        }
        
        // Clean the response first (remove field extraction markers)
        $cleanResponse = $this->cleanAIResponse($responseContent);
        
        // CRITICAL FIX: Always validate and correct the AI's acknowledgment
        // If we extracted a field (either via intent analysis or direct extraction),
        // ensure the response mentions the CORRECT field name
        if (!empty($extractedFields)) {
            $fieldName = array_key_first($extractedFields);
            $fieldValue = $extractedFields[$fieldName];
            $field = $config->getField($fieldName);
            
            // Check if AI mentioned wrong field names in its response (case-insensitive, generic)
            $mentionedWrongField = false;
            $lowerResponse = strtolower($cleanResponse);
            
            foreach ($config->getFields() as $otherFieldName => $otherField) {
                if ($otherFieldName !== $fieldName) {
                    // Check if AI incorrectly mentioned another field (case-insensitive)
                    $descriptionLower = strtolower($otherField->description);
                    $fieldNameLower = strtolower($otherFieldName);
                    
                    // Check for field description or name in response
                    if (str_contains($lowerResponse, $descriptionLower) || 
                        str_contains($lowerResponse, $fieldNameLower)) {
                        $mentionedWrongField = true;
                        Log::channel('ai-engine')->warning('AI mentioned wrong field in response', [
                            'mentioned_field' => $otherFieldName,
                            'mentioned_description' => $otherField->description,
                            'current_field' => $fieldName,
                            'response' => substr($cleanResponse, 0, 200),
                        ]);
                        break;
                    }
                }
            }
            
            // If AI mentioned wrong field OR we used direct extraction, override with correct acknowledgment
            if ($mentionedWrongField || $usedDirectExtraction) {
                $locale = $state->detectedLocale ?? $config->locale ?? 'en';
                if ($locale === 'ar') {
                    $cleanResponse = "رائع! لقد سجلت {$field->description}: " . substr($fieldValue, 0, 100);
                } else {
                    $cleanResponse = "Great! I've recorded {$field->description}: " . substr($fieldValue, 0, 100);
                }
                
                Log::channel('ai-engine')->info('Overrode AI response with correct field acknowledgment', [
                    'field' => $fieldName,
                    'reason' => $mentionedWrongField ? 'wrong_field_mentioned' : 'direct_extraction',
                ]);
            }
        }
        
        // Update state
        $state->setLastAIResponse($cleanResponse);
        $state->addMessage('assistant', $cleanResponse);

        // Check if all required fields are collected
        if ($config->isComplete($state->getData())) {
            if ($config->confirmBeforeComplete) {
                $state->setStatus(DataCollectorState::STATUS_CONFIRMING);
                $state->setCurrentField(null); // Clear current field to prevent showing field options
                
                // Generate data summary - use AI if summaryPrompt is configured
                $summary = $config->summaryPrompt
                    ? $this->generateAISummary($config, $state->getData(), $engine, $model)
                    : $config->generateSummary($state->getData());
                
                // Generate action summary - use AI if actionSummaryPrompt is configured
                $actionSummary = $config->actionSummaryPrompt
                    ? $this->generateAIActionSummary($config, $state->getData(), $engine, $model)
                    : $config->generateActionSummary($state->getData());
                
                // Build full confirmation message (with locale support)
                $locale = $state->detectedLocale ?? $config->locale ?? 'en';
                $fullMessage = $cleanResponse . "\n\n";
                $fullMessage .= $summary;
                $fullMessage .= "\n---\n\n";
                
                if ($locale === 'ar') {
                    $fullMessage .= "## ما سيحدث:\n\n";
                    $fullMessage .= $actionSummary;
                    $fullMessage .= "\n\n---\n\n";
                    $fullMessage .= "**يرجى التأكيد:**\n";
                    $fullMessage .= "- قل **'نعم'** أو **'تأكيد'** للمتابعة\n";
                    $fullMessage .= "- قل **'لا'** أو **'تغيير'** لتعديل أي معلومات\n";
                    $fullMessage .= "- قل **'إلغاء'** لإلغاء العملية\n";
                } else {
                    $fullMessage .= "## What will happen:\n\n";
                    $fullMessage .= $actionSummary;
                    $fullMessage .= "\n\n---\n\n";
                    $fullMessage .= "**Please confirm:**\n";
                    $fullMessage .= "- Say **'yes'** or **'confirm'** to proceed\n";
                    $fullMessage .= "- Say **'no'** or **'change'** to modify any information\n";
                    $fullMessage .= "- Say **'cancel'** to abort the process\n";
                }
                
                return new DataCollectorResponse(
                    success: true,
                    message: $fullMessage,
                    state: $state,
                    aiResponse: $aiResponse,
                    requiresConfirmation: true,
                    summary: $summary,
                    actionSummary: $actionSummary,
                );
            } else {
                return $this->handleCompletion($state, $config, $engine, $model);
            }
        }

        // Determine next field to collect
        $nextField = $this->getNextFieldToCollect($state, $config);
        if ($nextField) {
            $state->setCurrentField($nextField->name);
            
            // Append next field prompt to the acknowledgment message
            $locale = $state->detectedLocale ?? $config->locale ?? 'en';
            $nextFieldPrompt = $nextField->getCollectionPrompt();
            
            if ($locale === 'ar') {
                $cleanResponse .= "\n\n" . $nextFieldPrompt;
            } else {
                $cleanResponse .= "\n\n" . $nextFieldPrompt;
            }
            
            $state->setLastAIResponse($cleanResponse);
        }

        return new DataCollectorResponse(
            success: true,
            message: $cleanResponse,
            state: $state,
            aiResponse: $aiResponse,
            currentField: $state->currentField,
            collectedFields: array_keys(array_filter($state->getData(), fn($v) => $v !== null && $v !== '')),
            remainingFields: array_keys($config->getUncollectedFields($state->getData())),
        );
    }

    /**
     * Handle the confirming status - user confirms or modifies
     */
    protected function handleConfirming(
        DataCollectorState $state,
        DataCollectorConfig $config,
        string $message,
        string $engine,
        string $model
    ): DataCollectorResponse {
        $lowerMessage = strtolower(trim($message));

        // Check for confirmation (English and Arabic)
        $confirmWords = ['yes', 'y', 'confirm', 'correct', 'ok', 'okay', 'looks good', 'perfect', 'submit', 'نعم', 'تأكيد', 'تاكيد', 'صحيح', 'موافق', 'اكيد', 'أكيد'];
        if (in_array($lowerMessage, $confirmWords)) {
            // Store the confirmed action summary before completion
            // This will be used as the source for JSON generation
            if ($config->actionSummaryPrompt) {
                $modificationContext = '';
                if (!empty($state->metadata['output_modifications'])) {
                    $modificationContext = "\n\nUSER REQUESTED MODIFICATIONS:\n";
                    foreach ($state->metadata['output_modifications'] as $mod) {
                        $modificationContext .= "- {$mod}\n";
                    }
                }
                $state->confirmedActionSummary = $this->generateAIActionSummary(
                    $config,
                    $state->getData(),
                    $engine,
                    $model,
                    $modificationContext
                );
            }
            return $this->handleCompletion($state, $config, $engine, $model);
        }

        // Check for rejection/modification request (English and Arabic)
        $rejectWords = ['no', 'n', 'change', 'modify', 'edit', 'wrong', 'incorrect', 'لا', 'تغيير', 'تعديل', 'خطأ', 'غلط'];
        if (in_array($lowerMessage, $rejectWords)) {
            if ($config->allowEnhancement) {
                $state->setStatus(DataCollectorState::STATUS_ENHANCING);
                
                $locale = $state->detectedLocale ?? $config->locale ?? 'en';
                $enhancementMessage = $locale === 'ar'
                    ? "لا مشكلة! ماذا تريد تغييره؟ يمكنك تحديد اسم الحقل والقيمة الجديدة، أو وصف ما تريد تعديله."
                    : "No problem! What would you like to change? You can specify the field name and the new value, or describe what you'd like to modify.";
                
                return new DataCollectorResponse(
                    success: true,
                    message: $enhancementMessage,
                    state: $state,
                    allowsEnhancement: true,
                );
            } else {
                // Restart collection
                $state->setStatus(DataCollectorState::STATUS_COLLECTING);
                $state->collectedData = [];
                $state->setCurrentField($config->getFirstField()?->name);
                
                $locale = $state->detectedLocale ?? $config->locale ?? 'en';
                $firstFieldPrompt = $config->getFirstField()?->getCollectionPrompt();
                $restartMessage = $locale === 'ar'
                    ? "لنبدأ من جديد. " . $firstFieldPrompt
                    : "Let's start over. " . $firstFieldPrompt;
                
                return new DataCollectorResponse(
                    success: true,
                    message: $restartMessage,
                    state: $state,
                );
            }
        }

        // User might be specifying a modification directly
        if ($config->allowEnhancement) {
            $state->setStatus(DataCollectorState::STATUS_ENHANCING);
            return $this->handleEnhancing($state, $config, $message, $engine, $model);
        }

        // Ask for clarification
        $locale = $state->detectedLocale ?? $config->locale ?? 'en';
        $clarificationMessage = $locale === 'ar'
            ? "يرجى التأكيد إذا كانت المعلومات صحيحة بقول 'نعم' أو 'لا'. إذا كنت ترغب في إجراء تغييرات، قل 'لا' أو حدد ما تريد تعديله."
            : "Please confirm if the information is correct by saying 'yes' or 'no'. If you'd like to make changes, say 'no' or specify what you'd like to modify.";
        
        return new DataCollectorResponse(
            success: true,
            message: $clarificationMessage,
            state: $state,
            requiresConfirmation: true,
        );
    }

    /**
     * Handle the enhancing status - user modifies collected data
     */
    protected function handleEnhancing(
        DataCollectorState $state,
        DataCollectorConfig $config,
        string $message,
        string $engine,
        string $model
    ): DataCollectorResponse {
        // Check if user wants to modify the generated output (lessons, etc.)
        $isOutputModification = $this->isOutputModificationRequest($message, $config);
        
        if ($isOutputModification && $config->actionSummaryPrompt) {
            // User wants to modify the generated output (e.g., lessons)
            return $this->handleOutputModification($state, $config, $message, $engine, $model);
        }
        
        // Build enhancement prompt for input fields
        $systemPrompt = "You are helping the user modify their previously collected data.\n\n";
        $systemPrompt .= "Current data:\n" . $config->generateSummary($state->getData()) . "\n\n";
        $systemPrompt .= "Available fields: " . implode(', ', $config->getFieldNames()) . "\n\n";
        $systemPrompt .= "INSTRUCTIONS:\n";
        $systemPrompt .= "1. Ask for ONE field at a time in a conversational manner\n";
        $systemPrompt .= "2. ONLY mention the current field you're asking for - NEVER mention other fields\n";
        $systemPrompt .= "3. When acknowledging user input, ONLY reference the field you just collected\n";
        $systemPrompt .= "4. Validate user input and ask for corrections if needed\n";
        $systemPrompt .= "5. Be helpful and provide examples when the user seems unsure\n";
        $systemPrompt .= "6. After collecting all required fields, provide a summary\n";
        $systemPrompt .= "7. Ask for confirmation before completing\n";
        $systemPrompt .= "5. If user is done with changes, ask them to confirm with 'yes'\n";

        $aiResponse = $this->generateAIResponse(
            $systemPrompt,
            "User wants to modify: " . $message,
            [], // Empty history - context prompt provides all needed state
            $engine,
            $model
        );

        $responseContent = $aiResponse->getContent();
        
        // Parse field extractions (enhancement phase allows any field)
        $extractedFields = $this->parseFieldExtractions($responseContent, $config);
        
        // During enhancement, validate but don't skip already collected fields
        // User is explicitly modifying them
        foreach ($extractedFields as $fieldName => $value) {
            $field = $config->getField($fieldName);
            if ($field) {
                $errors = $field->validate($value);
                if (empty($errors)) {
                    $state->setFieldValue($fieldName, $value);
                    $state->clearValidationErrors();
                    Log::channel('ai-engine')->info('Field updated during enhancement', [
                        'field' => $fieldName,
                        'value' => $value,
                        'session_id' => $state->sessionId,
                    ]);
                } else {
                    $state->setValidationErrors([$fieldName => $errors]);
                }
            }
        }

        // Clean response
        $cleanResponse = $this->cleanAIResponse($responseContent);
        
        // Check if user is done enhancing
        if (str_contains(strtolower($message), 'done') || str_contains(strtolower($message), 'finish')) {
            $state->setStatus(DataCollectorState::STATUS_CONFIRMING);
            
            // Generate data summary - use AI if summaryPrompt is configured
            $summary = $config->summaryPrompt
                ? $this->generateAISummary($config, $state->getData(), $engine, $model)
                : $config->generateSummary($state->getData());
            
            // Generate action summary - use AI if actionSummaryPrompt is configured
            // Include any output modifications that were stored
            $modificationContext = '';
            if (!empty($state->metadata['output_modifications'])) {
                $modificationContext = "\n\nUSER REQUESTED MODIFICATIONS:\n";
                foreach ($state->metadata['output_modifications'] as $mod) {
                    $modificationContext .= "- {$mod}\n";
                }
            }
            
            $actionSummary = $config->actionSummaryPrompt
                ? $this->generateAIActionSummary($config, $state->getData(), $engine, $model, $modificationContext)
                : $config->generateActionSummary($state->getData());
            
            // Build confirmation message (with locale support)
            $locale = $state->detectedLocale ?? $config->locale ?? 'en';
            if ($locale === 'ar') {
                $fullMessage = "إليك معلوماتك المحدثة:\n\n";
                $fullMessage .= $summary;
                $fullMessage .= "\n---\n\n";
                $fullMessage .= "## ما سيحدث:\n\n";
                $fullMessage .= $actionSummary;
                $fullMessage .= "\n\n---\n\n";
                $fullMessage .= "**يرجى التأكيد:**\n";
                $fullMessage .= "- قل **'نعم'** أو **'تأكيد'** للمتابعة\n";
                $fullMessage .= "- قل **'لا'** أو **'تغيير'** لتعديل أي معلومات\n";
            } else {
                $fullMessage = "Here's your updated information:\n\n";
                $fullMessage .= $summary;
                $fullMessage .= "\n---\n\n";
                $fullMessage .= "## What will happen:\n\n";
                $fullMessage .= $actionSummary;
                $fullMessage .= "\n\n---\n\n";
                $fullMessage .= "**Please confirm:**\n";
                $fullMessage .= "- Say **'yes'** or **'confirm'** to proceed\n";
                $fullMessage .= "- Say **'no'** or **'change'** to modify any information\n";
            }
            
            return new DataCollectorResponse(
                success: true,
                message: $fullMessage,
                state: $state,
                requiresConfirmation: true,
                summary: $summary,
                actionSummary: $actionSummary,
            );
        }

        $state->setLastAIResponse($cleanResponse);
        $state->addMessage('assistant', $cleanResponse);

        // Generate updated summary
        $summary = $config->generateSummary($state->getData());
        
        // For enhancement mode, use static summary to avoid repeated AI calls
        $actionSummary = $config->generateActionSummary($state->getData());

        // Build enhancement message (with locale support)
        $locale = $state->detectedLocale ?? $config->locale ?? 'en';
        if ($locale === 'ar') {
            $enhanceMessage = $cleanResponse . "\n\n" . $summary . "\n\n**ما سيحدث:** " . $actionSummary . "\n\nهل تريد إجراء أي تغييرات أخرى؟ قل 'تم' عند الانتهاء، أو 'نعم' للتأكيد.";
        } else {
            $enhanceMessage = $cleanResponse . "\n\n" . $summary . "\n\n**What will happen:** " . $actionSummary . "\n\nWould you like to make any other changes? Say 'done' when you're finished, or 'yes' to confirm.";
        }

        return new DataCollectorResponse(
            success: true,
            message: $enhanceMessage,
            state: $state,
            aiResponse: $aiResponse,
            allowsEnhancement: true,
            summary: $summary,
            actionSummary: $actionSummary,
        );
    }

    /**
     * Check if the user wants to modify generated output (lessons, etc.)
     */
    protected function isOutputModificationRequest(string $message, DataCollectorConfig $config): bool
    {
        $lowerMessage = strtolower($message);
        
        // Keywords that suggest output modification
        $outputKeywords = ['lesson', 'lessons', 'structure', 'curriculum', 'content', 'outline', 'syllabus'];
        
        foreach ($outputKeywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Handle modification of generated output (lessons, etc.)
     */
    protected function handleOutputModification(
        DataCollectorState $state,
        DataCollectorConfig $config,
        string $message,
        string $engine,
        string $model
    ): DataCollectorResponse {
        // Store the modification request
        $modifications = $state->metadata['output_modifications'] ?? [];
        $modifications[] = $message;
        $state->setMetadata(array_merge($state->metadata, ['output_modifications' => $modifications]));
        
        // Build prompt to regenerate action summary with modifications
        $modificationContext = "\n\nUSER REQUESTED MODIFICATIONS:\n";
        foreach ($modifications as $mod) {
            $modificationContext .= "- {$mod}\n";
        }
        
        // Regenerate action summary with modifications
        $actionSummary = $this->generateAIActionSummary(
            $config,
            $state->getData(),
            $engine,
            $model,
            $modificationContext
        );
        
        $locale = $state->detectedLocale ?? $config->locale ?? 'en';
        if ($locale === 'ar') {
            $response = "لقد قمت بتحديث الهيكل بناءً على ملاحظاتك:\n\n";
            $response .= $actionSummary;
            $response .= "\n\nهل تريد أي تغييرات أخرى، أم نتابع بهذا؟";
        } else {
            $response = "I've updated the structure based on your feedback:\n\n";
            $response .= $actionSummary;
            $response .= "\n\nWould you like any other changes, or shall we proceed with this?";
        }
        
        return new DataCollectorResponse(
            success: true,
            message: $response,
            state: $state,
            actionSummary: $actionSummary,
            requiresConfirmation: false,
        );
    }

    /**
     * Handle completion of data collection
     */
    protected function handleCompletion(
        DataCollectorState $state,
        DataCollectorConfig $config,
        string $engine = 'openai',
        string $model = 'gpt-4o'
    ): DataCollectorResponse {
        // Log state data for debugging
        Log::channel('ai-engine')->info('Data collection completion attempt', [
            'session_id' => $state->sessionId,
            'collected_data' => $state->getData(),
            'status' => $state->status,
        ]);
        
        // Final validation
        $errors = $config->validateAll($state->getData());
        
        if (!empty($errors)) {
            Log::channel('ai-engine')->warning('Data collection validation failed', [
                'session_id' => $state->sessionId,
                'errors' => $errors,
                'data' => $state->getData(),
            ]);
            
            $state->setValidationErrors($errors);
            $state->setStatus(DataCollectorState::STATUS_COLLECTING);
            
            $errorMessage = "There are some validation errors:\n";
            foreach ($errors as $field => $fieldErrors) {
                $errorMessage .= "- {$field}: " . implode(', ', $fieldErrors) . "\n";
            }
            
            return new DataCollectorResponse(
                success: false,
                message: $errorMessage . "\nPlease provide the correct values.",
                state: $state,
                validationErrors: $errors,
            );
        }

        // Generate structured output if schema is defined
        $generatedOutput = null;
        if ($config->outputSchema) {
            $generatedOutput = $this->generateStructuredOutput($config, $state, $engine, $model);
        }

        // Execute completion callback
        $result = null;
        $error = null;
        
        try {
            // Pass generated output to the callback if available
            $callbackData = $state->getData();
            if ($generatedOutput) {
                $callbackData['_generated_output'] = $generatedOutput;
            }
            
            $result = $config->executeOnComplete($callbackData);
            $state->setResult($result);
            $state->setStatus(DataCollectorState::STATUS_COMPLETED);
            
            Log::channel('ai-engine')->info('Data collection completed successfully', [
                'session_id' => $state->sessionId,
                'config' => $config->name,
                'data' => $state->getData(),
                'generated_output' => $generatedOutput,
            ]);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            Log::channel('ai-engine')->error('Data collection completion failed', [
                'session_id' => $state->sessionId,
                'config' => $config->name,
                'error' => $error,
            ]);
        }

        $successMessage = $config->successMessage ?? "Thank you! Your information has been successfully collected and processed.";
        
        if ($error) {
            return new DataCollectorResponse(
                success: false,
                message: "There was an error processing your data: {$error}",
                state: $state,
                isComplete: false,
            );
        }

        return new DataCollectorResponse(
            success: true,
            message: $successMessage,
            state: $state,
            isComplete: true,
            result: $result,
            summary: $config->generateSummary($state->getData()),
            generatedOutput: $generatedOutput,
        );
    }

    /**
     * Handle user request for suggestions
     */
    protected function handleSuggestionRequest(
        DataCollectorState $state,
        DataCollectorConfig $config,
        string $engine,
        string $model
    ): DataCollectorResponse {
        $field = $config->getField($state->currentField);
        
        if (!$field) {
            $locale = $state->detectedLocale ?? $config->locale ?? 'en';
            return new DataCollectorResponse(
                success: false,
                message: $locale === 'ar'
                    ? 'غير قادر على إنشاء اقتراحات في الوقت الحالي.'
                    : 'Unable to generate suggestions at this time.',
                state: $state,
            );
        }
        
        Log::channel('ai-engine')->info('Generating suggestions for field', [
            'session_id' => $state->sessionId,
            'field' => $state->currentField,
            'field_description' => $field->description,
        ]);
        
        // Build context for suggestion generation
        $contextData = $state->getData();
        $contextPrompt = "Generate helpful suggestions for the following field:\n\n";
        $contextPrompt .= "**Field**: {$state->currentField}\n";
        $contextPrompt .= "**Description**: {$field->description}\n";
        
        if ($field->type === 'select' && !empty($field->options)) {
            $contextPrompt .= "**Valid Options**: " . implode(', ', $field->options) . "\n";
        }
        
        if (!empty($field->examples)) {
            $contextPrompt .= "**Examples**: " . implode(', ', $field->examples) . "\n";
        }
        
        if ($field->validation) {
            $contextPrompt .= "**Requirements**: {$field->validation}\n";
        }
        
        // Add context from already collected fields
        if (!empty($contextData)) {
            $contextPrompt .= "\n**Context from collected information**:\n";
            foreach ($contextData as $fieldName => $value) {
                if (!empty($value)) {
                    $contextPrompt .= "- {$fieldName}: {$value}\n";
                }
            }
        }
        
        $contextPrompt .= "\n**Task**: Generate 3-5 creative, relevant suggestions for the '{$field->description}' field.";
        $contextPrompt .= "\nConsider the context provided and make suggestions that are specific, helpful, and appropriate.";
        $contextPrompt .= "\nFormat each suggestion on a new line with a number (1., 2., 3., etc.).";
        
        // Use detected or configured locale
        $locale = $state->detectedLocale ?? $config->locale ?? 'en';
        if ($locale === 'ar') {
            $contextPrompt .= "\n\nRespond in Arabic.";
        }
        
        try {
            $aiResponse = $this->aiEngine
                ->engine($engine)
                ->model($model)
                ->withSystemPrompt("You are a helpful assistant providing creative suggestions for data collection.")
                ->withMaxTokens(500)
                ->generate($contextPrompt);
            
            $suggestions = $aiResponse->getContent();
            
            // Store suggestions in state for later selection
            $state->lastSuggestions = [
                'field' => $state->currentField,
                'suggestions' => $suggestions,
            ];
            
            // Build user-friendly response
            if ($locale === 'ar') {
                $responseMessage = "إليك بعض الاقتراحات:\n\n";
                $responseMessage .= $suggestions;
                $responseMessage .= "\n\nيمكنك اختيار أحد هذه الاقتراحات برقمه (مثل: 1) أو تقديم قيمتك الخاصة.";
            } else {
                $responseMessage = "Here are some suggestions:\n\n";
                $responseMessage .= $suggestions;
                $responseMessage .= "\n\nYou can choose one of these suggestions by its number (e.g., 1) or provide your own value.";
            }
            
            $state->setLastAIResponse($responseMessage);
            $state->addMessage('assistant', $responseMessage);
            
            Log::channel('ai-engine')->info('Suggestions generated successfully', [
                'session_id' => $state->sessionId,
                'field' => $state->currentField,
            ]);
            
            return new DataCollectorResponse(
                success: true,
                message: $responseMessage,
                state: $state,
                aiResponse: $aiResponse,
                currentField: $state->currentField,
            );
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to generate suggestions', [
                'session_id' => $state->sessionId,
                'field' => $state->currentField,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to examples if available
            if (!empty($field->examples)) {
                $fallbackMessage = $locale === 'ar' 
                    ? "إليك بعض الأمثلة لـ {$field->description}:\n\n" . implode("\n", array_map(fn($ex, $i) => ($i+1) . ". {$ex}", $field->examples, array_keys($field->examples)))
                    : "Here are some examples for {$field->description}:\n\n" . implode("\n", array_map(fn($ex, $i) => ($i+1) . ". {$ex}", $field->examples, array_keys($field->examples)));
                
                return new DataCollectorResponse(
                    success: true,
                    message: $fallbackMessage,
                    state: $state,
                    currentField: $state->currentField,
                );
            }
            
            $errorMessage = $locale === 'ar'
                ? "عذراً، لم أتمكن من إنشاء اقتراحات. يرجى تقديم {$field->description}."
                : "Sorry, I couldn't generate suggestions. Please provide {$field->description}.";
            
            return new DataCollectorResponse(
                success: false,
                message: $errorMessage,
                state: $state,
                currentField: $state->currentField,
            );
        }
    }

    /**
     * Handle cancellation of data collection
     */
    protected function handleCancellation(
        DataCollectorState $state,
        DataCollectorConfig $config
    ): DataCollectorResponse {
        $state->setStatus(DataCollectorState::STATUS_CANCELLED);
        $this->saveState($state);

        $cancelMessage = $config->cancelMessage ?? "Data collection has been cancelled. Your information has not been saved.";

        Log::channel('ai-engine')->info('Data collection cancelled', [
            'session_id' => $state->sessionId,
            'config' => $config->name,
        ]);

        return new DataCollectorResponse(
            success: true,
            message: $cancelMessage,
            state: $state,
            isCancelled: true,
        );
    }

    /**
     * Generate AI-based action summary using a prompt
     * 
     * This allows dynamic generation of action summaries like lesson plans,
     * product descriptions, etc. based on collected data.
     */
    public function generateAIActionSummary(
        DataCollectorConfig $config,
        array $data,
        string $engine = 'openai',
        string $model = 'gpt-4o',
        string $modificationContext = ''
    ): string {
        if (!$config->actionSummaryPrompt) {
            return $config->generateActionSummary($data);
        }

        try {
            // Get engine/model from config or use defaults
            $promptConfig = $config->actionSummaryPromptConfig ?? [];
            $useEngine = $promptConfig['engine'] ?? $engine;
            $useModel = $promptConfig['model'] ?? $model;
            $maxTokens = $promptConfig['max_tokens'] ?? 2000;

            // Build the prompt with data placeholders replaced
            $prompt = $config->actionSummaryPrompt;
            foreach ($data as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $prompt = str_replace('{' . $key . '}', (string) $value, $prompt);
                }
            }

            // Add collected data context
            $dataContext = "Based on the following collected information:\n\n";
            foreach ($data as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $dataContext .= "- **{$label}**: {$value}\n";
            }
            $dataContext .= "\n---\n\n";

            $fullPrompt = $dataContext . $prompt . $modificationContext;

            $systemPrompt = "You are a helpful assistant generating a preview/summary of what will be created based on user input. "
                . "Format your response in a clear, readable way using markdown. "
                . "Be specific and detailed in your preview.";

            Log::channel('ai-engine')->info('Generating AI action summary', [
                'config' => $config->name,
                'engine' => $useEngine,
                'model' => $useModel,
            ]);

            $response = $this->aiEngine
                ->engine($useEngine)
                ->model($useModel)
                ->withSystemPrompt($systemPrompt)
                ->withMaxTokens($maxTokens)
                ->generate($fullPrompt);

            return $response->getContent();

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to generate AI action summary', [
                'config' => $config->name,
                'error' => $e->getMessage(),
            ]);

            // Fallback to static summary
            return $config->generateActionSummary($data);
        }
    }

    /**
     * Generate AI-powered data summary using the configured summaryPrompt
     */
    public function generateAISummary(
        DataCollectorConfig $config,
        array $data,
        string $engine = 'openai',
        string $model = 'gpt-4o'
    ): string {
        if (!$config->summaryPrompt) {
            return $config->generateSummary($data);
        }

        try {
            // Get engine/model from config or use defaults
            $promptConfig = $config->summaryPromptConfig ?? [];
            $useEngine = $promptConfig['engine'] ?? $engine;
            $useModel = $promptConfig['model'] ?? $model;
            $maxTokens = $promptConfig['max_tokens'] ?? 2000;

            // Build the prompt with data placeholders replaced
            $prompt = $config->summaryPrompt;
            foreach ($data as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $prompt = str_replace('{' . $key . '}', (string) $value, $prompt);
                }
            }

            // Add collected data context
            $dataContext = "Based on the following collected information:\n\n";
            foreach ($data as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $dataContext .= "- **{$label}**: {$value}\n";
            }
            $dataContext .= "\n---\n\n";

            $fullPrompt = $dataContext . $prompt;

            $systemPrompt = "You are a helpful assistant generating a summary of collected data. "
                . "Format your response in a clear, readable way using markdown. "
                . "Be concise but comprehensive.";

            Log::channel('ai-engine')->info('Generating AI data summary', [
                'config' => $config->name,
                'engine' => $useEngine,
                'model' => $useModel,
            ]);

            $response = $this->aiEngine
                ->engine($useEngine)
                ->model($useModel)
                ->withSystemPrompt($systemPrompt)
                ->withMaxTokens($maxTokens)
                ->generate($fullPrompt);

            return $response->getContent();

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to generate AI data summary', [
                'config' => $config->name,
                'error' => $e->getMessage(),
            ]);

            // Fallback to static summary
            return $config->generateSummary($data);
        }
    }

    /**
     * Generate structured output based on schema definition
     * 
     * This generates structured data like courses with lessons, products with variants, etc.
     * based on the collected data and the defined output schema.
     */
    public function generateStructuredOutput(
        DataCollectorConfig $config,
        DataCollectorState $state,
        string $engine = 'openai',
        string $model = 'gpt-4o'
    ): ?array {
        if (!$config->outputSchema) {
            return null;
        }

        try {
            $data = $state->getData();
            
            // Get engine/model from config or use defaults
            $outputConfig = $config->outputConfig ?? [];
            $useEngine = $outputConfig['engine'] ?? $engine;
            $useModel = $outputConfig['model'] ?? $model;
            $maxTokens = $outputConfig['max_tokens'] ?? 4000;

            // Build the schema description for the AI
            $schemaDescription = $this->buildSchemaDescription($config->outputSchema);

            // Build the prompt
            $dataContext = "Based on the following collected information:\n\n";
            foreach ($data as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $dataContext .= "- **{$label}**: {$value}\n";
            }

            // Use custom prompt if provided, otherwise generate one
            $prompt = $config->outputPrompt ?? "Generate the structured output based on the collected data.";
            
            // Replace placeholders in prompt
            foreach ($data as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $prompt = str_replace('{' . $key . '}', (string) $value, $prompt);
                }
            }

            // If we have a confirmed action summary, use it as the source
            // This ensures the JSON matches what the user confirmed
            $actionSummaryContext = '';
            if ($state->confirmedActionSummary) {
                $actionSummaryContext = "\n\nCONFIRMED STRUCTURE (USE THIS AS THE SOURCE):\n";
                $actionSummaryContext .= $state->confirmedActionSummary;
                $actionSummaryContext .= "\n\nIMPORTANT: Convert the above confirmed structure into JSON format. ";
                $actionSummaryContext .= "Do NOT generate new content. Use the exact lessons, descriptions, and details shown above.\n";
            } else {
                // Fallback: Include output modifications if any
                if (!empty($state->metadata['output_modifications'])) {
                    $actionSummaryContext = "\n\nIMPORTANT - USER REQUESTED MODIFICATIONS:\n";
                    foreach ($state->metadata['output_modifications'] as $mod) {
                        $actionSummaryContext .= "- {$mod}\n";
                    }
                    $actionSummaryContext .= "\nMake sure to apply ALL these modifications to the generated output.\n";
                }
            }

            $fullPrompt = $dataContext . "\n---\n\n" . $prompt . $actionSummaryContext;

            $systemPrompt = "You are a data generation assistant. Generate structured JSON output based on user input.\n\n";
            $systemPrompt .= "OUTPUT SCHEMA:\n" . $schemaDescription . "\n\n";
            $systemPrompt .= "IMPORTANT RULES:\n";
            $systemPrompt .= "1. Return ONLY valid JSON, no markdown code blocks, no explanations\n";
            $systemPrompt .= "2. Follow the schema structure exactly\n";
            $systemPrompt .= "3. Generate realistic, relevant content based on the input data\n";
            $systemPrompt .= "4. For arrays, generate the number of items specified or a reasonable default\n";

            Log::channel('ai-engine')->info('Generating structured output', [
                'config' => $config->name,
                'engine' => $useEngine,
                'model' => $useModel,
                'schema' => $config->outputSchema,
            ]);

            $response = $this->aiEngine
                ->engine($useEngine)
                ->model($useModel)
                ->withSystemPrompt($systemPrompt)
                ->withMaxTokens($maxTokens)
                ->generate($fullPrompt);

            $content = $response->getContent();
            
            // Clean up response - remove markdown code blocks if present
            $content = preg_replace('/^```json\s*/i', '', $content);
            $content = preg_replace('/^```\s*/i', '', $content);
            $content = preg_replace('/\s*```$/i', '', $content);
            $content = trim($content);

            // Parse JSON
            $output = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::channel('ai-engine')->warning('Failed to parse structured output JSON', [
                    'config' => $config->name,
                    'error' => json_last_error_msg(),
                    'content' => substr($content, 0, 500),
                ]);
                return null;
            }

            return $output;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to generate structured output', [
                'config' => $config->name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build a human-readable schema description for the AI
     */
    protected function buildSchemaDescription(array $schema, int $indent = 0): string
    {
        $description = "";
        $prefix = str_repeat("  ", $indent);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                if (isset($value['type'])) {
                    // Field definition with type
                    $type = $value['type'];
                    $desc = $value['description'] ?? '';
                    $count = $value['count'] ?? null;
                    
                    if ($type === 'array' && isset($value['items'])) {
                        $countStr = $count ? " (generate {$count} items)" : "";
                        $description .= "{$prefix}{$key}: array of objects{$countStr}\n";
                        if ($desc) {
                            $description .= "{$prefix}  // {$desc}\n";
                        }
                        $description .= "{$prefix}  Each item has:\n";
                        $description .= $this->buildSchemaDescription($value['items'], $indent + 2);
                    } else {
                        $description .= "{$prefix}{$key}: {$type}";
                        if ($desc) {
                            $description .= " // {$desc}";
                        }
                        $description .= "\n";
                    }
                } else {
                    // Nested object
                    $description .= "{$prefix}{$key}: object\n";
                    $description .= $this->buildSchemaDescription($value, $indent + 1);
                }
            } else {
                // Simple field (string description)
                $description .= "{$prefix}{$key}: {$value}\n";
            }
        }

        return $description;
    }

    /**
     * Generate AI response
     */
    protected function generateAIResponse(
        string $systemPrompt,
        string $userPrompt,
        array $conversationHistory,
        string $engine,
        string $model
    ): AIResponse {
        try {
            // Note: We don't use conversation history anymore
            // The context prompt (in userPrompt) contains all necessary state:
            // - Already collected fields
            // - Remaining fields to collect
            // - Current field being asked
            // - Validation errors
            // This reduces token usage significantly while maintaining context

            $response = $this->aiEngine
                ->engine($engine)
                ->model($model)
                ->withSystemPrompt($systemPrompt)
                ->generate($userPrompt);

            return $response;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AI generation failed in data collector', [
                'error' => $e->getMessage(),
            ]);

            // Return a fallback response
            return new AIResponse(
                content: "I apologize, but I encountered an error. Could you please repeat your last message?",
                engine: EngineEnum::from($engine),
                model: EntityEnum::from($model),
                error: $e->getMessage(),
                success: false
            );
        }
    }

    /**
     * Parse field extractions from AI response
     */
    protected function parseFieldExtractions(string $response, DataCollectorConfig $config): array
    {
        $extracted = [];
        
        // Pattern: FIELD_COLLECTED:field_name=value
        preg_match_all('/FIELD_COLLECTED:(\w+)=(.+?)(?=\n|FIELD_COLLECTED:|$)/s', $response, $matches, PREG_SET_ORDER);
        
        Log::channel('ai-engine')->info('Parsing field extractions from AI response', [
            'found_markers' => count($matches),
            'has_marker_text' => str_contains($response, 'FIELD_COLLECTED:'),
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 200),
        ]);
        
        foreach ($matches as $match) {
            $fieldName = trim($match[1]);
            $value = trim($match[2]);
            $extracted[$fieldName] = $value;
            
            Log::channel('ai-engine')->info('Extracted field from marker', [
                'field' => $fieldName,
                'value' => substr($value, 0, 100),
            ]);
        }

        // Only use fallback parser if we found at least one FIELD_COLLECTED marker
        // AND only for valid fields in the config
        if (!empty($extracted)) {
            Log::channel('ai-engine')->info('Running fallback parser (markers found)', [
                'already_extracted' => array_keys($extracted),
            ]);
            
            $fallbackExtracted = $this->parseFieldsFromSummary($response, $config);
            
            if (!empty($fallbackExtracted)) {
                Log::channel('ai-engine')->info('Fallback parser found additional fields', [
                    'fallback_fields' => array_keys($fallbackExtracted),
                ]);
            }
            
            foreach ($fallbackExtracted as $key => $value) {
                // Only accept if field exists in config and not already extracted
                if ($config->getField($key) && (!isset($extracted[$key]) || empty($extracted[$key]))) {
                    $extracted[$key] = $value;
                    Log::channel('ai-engine')->info('Added field from fallback parser', [
                        'field' => $key,
                        'value' => substr($value, 0, 100),
                    ]);
                }
            }
        } else {
            Log::channel('ai-engine')->warning('Fallback parser skipped - no markers found', [
                'response_has_bold_text' => str_contains($response, '**'),
            ]);
        }

        return $extracted;
    }

    /**
     * Fallback parser for extracting fields from AI summary text
     * Handles formats like "**Course Name**: Laravel Basics" or "- **Duration**: 10 hours"
     */
    protected function parseFieldsFromSummary(string $response, DataCollectorConfig $config): array
    {
        $extracted = [];
        
        // Build field mappings dynamically from config to avoid hardcoded assumptions
        $fieldMappings = [];
        foreach ($config->getFields() as $fieldName => $field) {
            $fieldMappings[strtolower($fieldName)] = $fieldName;
            $fieldMappings[strtolower($field->description)] = $fieldName;
            
            // Add common variations only for known fields
            if ($fieldName === 'name') {
                $fieldMappings['course name'] = 'name';
                $fieldMappings['title'] = 'name';
            } elseif ($fieldName === 'level') {
                $fieldMappings['difficulty'] = 'level';
                $fieldMappings['difficulty level'] = 'level';
            } elseif ($fieldName === 'lessons_count') {
                $fieldMappings['lessons'] = 'lessons_count';
                $fieldMappings['number of lessons'] = 'lessons_count';
                $fieldMappings['lesson count'] = 'lessons_count';
            }
        }

        // Pattern: **Label**: Value or - **Label:** Value or **Label:** Value
        // Handles both **Label**: and **Label:**
        preg_match_all('/\*\*([^*:]+):?\*\*:?\s*(.+?)(?=\n|$)/i', $response, $matches, PREG_SET_ORDER);
        
        Log::channel('ai-engine')->debug('Fallback parser scanning response', [
            'bold_patterns_found' => count($matches),
            'available_mappings' => array_keys($fieldMappings),
        ]);
        
        foreach ($matches as $match) {
            $label = strtolower(trim($match[1]));
            $value = trim($match[2]);
            
            // Map display name to field key
            $fieldKey = $fieldMappings[$label] ?? str_replace(' ', '_', $label);
            
            Log::channel('ai-engine')->debug('Fallback parser processing bold text', [
                'label' => $label,
                'mapped_to' => $fieldKey,
                'value_preview' => substr($value, 0, 50),
            ]);
            
            // Clean up value (remove trailing punctuation, extra text)
            $value = preg_replace('/\s*\(.*?\)\s*$/', '', $value); // Remove parenthetical notes
            $value = rtrim($value, '.,;:');
            
            // Extract numeric values for duration/lessons
            if (in_array($fieldKey, ['duration', 'lessons_count'])) {
                if (preg_match('/(\d+)/', $value, $numMatch)) {
                    $value = $numMatch[1];
                }
            }
            
            if (!empty($value)) {
                $extracted[$fieldKey] = $value;
                Log::channel('ai-engine')->info('Fallback parser extracted field', [
                    'field' => $fieldKey,
                    'from_label' => $label,
                    'value' => substr($value, 0, 100),
                ]);
            }
        }

        // Normalize extracted values
        foreach ($extracted as $fieldKey => $value) {
            // Normalize level values
            if ($fieldKey === 'level') {
                $value = strtolower($value);
                // Map common variations
                if (str_contains($value, 'beginner')) {
                    $value = 'beginner';
                } elseif (str_contains($value, 'intermediate')) {
                    $value = 'intermediate';
                } elseif (str_contains($value, 'advanced')) {
                    $value = 'advanced';
                }
                $extracted[$fieldKey] = $value;
            }
            
            // Extract numeric values for duration/lessons
            if (in_array($fieldKey, ['duration', 'lessons_count'])) {
                if (preg_match('/(\d+)/', $value, $numMatch)) {
                    $extracted[$fieldKey] = $numMatch[1];
                }
            }
        }

        return $extracted;
    }

    /**
     * Analyze user message intent for field collection using AI
     * Similar to ChatService intent analysis but focused on single field extraction
     */
    protected function analyzeFieldIntent(
        string $message,
        string $currentField,
        DataCollectorConfig $config,
        array $collectedData
    ): array {
        $field = $config->getField($currentField);
        
        if (!$field) {
            return [
                'intent' => 'unknown',
                'confidence' => 0,
                'extracted_value' => null,
            ];
        }
        
        try {
            $prompt = "Analyze the user's message to extract a value for a specific field.\n\n";
            $prompt .= "User Message: \"{$message}\"\n\n";
            $prompt .= "FIELD TO EXTRACT:\n";
            $prompt .= "- Field Name: {$currentField}\n";
            $prompt .= "- Description: {$field->description}\n";
            $prompt .= "- Type: {$field->type}\n";
            $prompt .= "- Required: " . ($field->required ? 'YES' : 'NO') . "\n";
            
            if (!empty($field->options)) {
                $prompt .= "- Valid Options: " . implode(', ', $field->options) . "\n";
            }
            
            if (!empty($field->examples)) {
                $prompt .= "- Examples: " . implode(', ', $field->examples) . "\n";
            }
            
            if ($field->validation) {
                $prompt .= "- Validation: {$field->validation}\n";
            }
            
            $prompt .= "\nALREADY COLLECTED FIELDS (DO NOT extract these):\n";
            foreach ($collectedData as $fieldName => $value) {
                if (!empty($value)) {
                    $prompt .= "- {$fieldName}: {$value}\n";
                }
            }
            
            $prompt .= "\nYOUR TASK:\n";
            $prompt .= "Analyze the user's message and determine their intent:\n\n";
            $prompt .= "1. 'provide_value' - User is providing a value for the '{$currentField}' field\n";
            $prompt .= "   → Extract the EXACT value from their message\n";
            $prompt .= "   → Clean and format it appropriately for the field type\n";
            $prompt .= "   → For select fields, match to closest valid option\n";
            $prompt .= "   → For numeric fields, extract just the number\n";
            $prompt .= "2. 'question' - User is asking a question or needs clarification\n";
            $prompt .= "   → Do NOT extract any value\n";
            $prompt .= "3. 'suggest' - User wants suggestions or ideas (e.g., 'suggest', 'give me ideas', 'help me')\n";
            $prompt .= "   → Do NOT extract any value\n";
            $prompt .= "4. 'skip' - User wants to skip this field (e.g., 'skip', 'pass', 'next')\n";
            $prompt .= "   → Do NOT extract any value\n";
            $prompt .= "5. 'unclear' - Message is ambiguous or doesn't contain a clear value\n";
            $prompt .= "   → Do NOT extract any value\n\n";
            
            $prompt .= "CRITICAL EXTRACTION RULES:\n";
            $prompt .= "- ONLY extract a value if the user is clearly providing one for '{$currentField}'\n";
            $prompt .= "- NEVER extract values for other fields (they're already collected)\n";
            $prompt .= "- NEVER infer or generate values - only extract what user explicitly said\n";
            $prompt .= "- If user mentions multiple things, extract ONLY the value for '{$currentField}'\n";
            $prompt .= "- For select fields, match user's input to the closest valid option\n";
            $prompt .= "- For numeric fields, extract just the number (remove units, currency, etc.)\n";
            $prompt .= "- If unclear or ambiguous, set intent to 'unclear' and extracted_value to null\n\n";
            
            $prompt .= "EXAMPLES:\n";
            if ($field->type === 'select' && !empty($field->options)) {
                $prompt .= "Field: {$currentField} (select from: " . implode(', ', $field->options) . ")\n";
                $prompt .= "- User: 'beginner' → intent: 'provide_value', value: 'beginner'\n";
                $prompt .= "- User: 'I'm new to this' → intent: 'provide_value', value: 'beginner'\n";
                $prompt .= "- User: 'what are the options?' → intent: 'question', value: null\n";
            } elseif (str_contains($field->validation ?? '', 'numeric')) {
                $prompt .= "Field: {$currentField} (numeric)\n";
                $prompt .= "- User: '10 hours' → intent: 'provide_value', value: '10'\n";
                $prompt .= "- User: 'about ten' → intent: 'provide_value', value: '10'\n";
                $prompt .= "- User: 'not sure yet' → intent: 'unclear', value: null\n";
            } else {
                $prompt .= "Field: {$currentField} (text)\n";
                $prompt .= "- User: 'Learn Laravel basics' → intent: 'provide_value', value: 'Learn Laravel basics'\n";
                $prompt .= "- User: 'what should I write?' → intent: 'question', value: null\n";
            }
            
            $prompt .= "\nRespond with ONLY valid JSON in this exact format:\n";
            $prompt .= "{\n";
            $prompt .= '  "intent": "provide_value|question|suggest|skip|unclear",'."\n";
            $prompt .= '  "confidence": 0.95,'."\n";
            $prompt .= '  "extracted_value": "the actual value from user message or null",'."\n";
            $prompt .= '  "reasoning": "Brief explanation of why you chose this intent"'."\n";
            $prompt .= "}\n";
            
            // Use fast model for intent analysis
            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            );
            
            $response = app(\LaravelAIEngine\Services\AIEngineService::class)->generate($aiRequest);
            
            if (!$response->success) {
                Log::channel('ai-engine')->warning('Field intent analysis failed', [
                    'error' => $response->error,
                ]);
                
                return [
                    'intent' => 'provide_value',
                    'confidence' => 0.5,
                    'extracted_value' => trim($message),
                    'reasoning' => 'AI analysis failed, using raw message',
                ];
            }
            
            $content = $response->getContent();
            
            // Extract JSON from response
            $jsonContent = $content;
            if (preg_match('/(\{.*\})/s', $content, $matches)) {
                $jsonContent = $matches[1];
            }
            
            $result = json_decode($jsonContent, true);
            
            if (is_array($result) && isset($result['intent'])) {
                Log::channel('ai-engine')->info('Field intent analysis successful', [
                    'intent' => $result['intent'],
                    'confidence' => $result['confidence'] ?? 0,
                    'has_value' => !empty($result['extracted_value']),
                    'reasoning' => $result['reasoning'] ?? '',
                ]);
                
                return $result;
            }
            
            Log::channel('ai-engine')->warning('Failed to parse intent analysis JSON', [
                'content' => substr($content, 0, 200),
            ]);
            
            // Fallback
            return [
                'intent' => 'provide_value',
                'confidence' => 0.5,
                'extracted_value' => trim($message),
                'reasoning' => 'JSON parsing failed, using raw message',
            ];
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Field intent analysis exception', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'intent' => 'provide_value',
                'confidence' => 0.5,
                'extracted_value' => trim($message),
                'reasoning' => 'Exception occurred, using raw message',
            ];
        }
    }

    /**
     * Filter extracted fields to only accept the current field being collected
     * This prevents hallucination where AI extracts wrong fields
     */
    protected function filterToCurrentField(array $extractedFields, string $currentField, array $collectedData): array
    {
        $filtered = [];
        
        foreach ($extractedFields as $fieldName => $value) {
            // Skip if field is already collected (prevent re-extraction)
            if (isset($collectedData[$fieldName]) && !empty($collectedData[$fieldName])) {
                Log::channel('ai-engine')->warning('Skipping already collected field', [
                    'field' => $fieldName,
                    'existing_value' => $collectedData[$fieldName],
                    'attempted_value' => $value,
                ]);
                continue;
            }
            
            // During collection phase, only accept the current field
            // This prevents AI from jumping ahead or extracting wrong fields
            if ($fieldName === $currentField) {
                $filtered[$fieldName] = $value;
            } else {
                Log::channel('ai-engine')->warning('Ignoring field extraction - not current field', [
                    'extracted_field' => $fieldName,
                    'current_field' => $currentField,
                    'value' => $value,
                ]);
            }
        }
        
        return $filtered;
    }

    /**
     * Extract field value directly from user message when AI doesn't use markers
     */
    protected function extractFromUserMessage(string $message, string $currentField, DataCollectorConfig $config): array
    {
        $extracted = [];
        $field = $config->getField($currentField);
        
        if (!$field) {
            return $extracted;
        }
        
        // Clean the message
        $value = trim($message);
        
        // For select fields, try to match against options
        if ($field->type === 'select' && !empty($field->options)) {
            $lowerValue = strtolower($value);
            foreach ($field->options as $option) {
                if (str_contains($lowerValue, strtolower($option))) {
                    $extracted[$currentField] = $option;
                    Log::channel('ai-engine')->info('Direct extraction matched select option', [
                        'field' => $currentField,
                        'value' => $option,
                        'user_message' => $message,
                    ]);
                    return $extracted;
                }
            }
        }
        
        // For numeric fields, extract numbers
        if ($field->type === 'number' || str_contains($field->validation ?? '', 'numeric')) {
            if (preg_match('/(\d+(?:\.\d+)?)/', $value, $match)) {
                $extracted[$currentField] = $match[1];
                Log::channel('ai-engine')->info('Direct extraction found number', [
                    'field' => $currentField,
                    'value' => $match[1],
                    'user_message' => $message,
                ]);
                return $extracted;
            }
        }
        
        // For text fields, use the entire message (unless it's too short or looks like a question)
        if (strlen($value) >= 2 && !str_ends_with($value, '?')) {
            $extracted[$currentField] = $value;
            Log::channel('ai-engine')->info('Direct extraction using full message', [
                'field' => $currentField,
                'value' => substr($value, 0, 100),
                'message_length' => strlen($value),
            ]);
        } else {
            Log::channel('ai-engine')->warning('Direct extraction rejected message', [
                'field' => $currentField,
                'reason' => strlen($value) < 2 ? 'too_short' : 'looks_like_question',
                'message' => $value,
            ]);
        }
        
        return $extracted;
    }

    /**
     * Clean AI response by removing field extraction markers
     */
    protected function cleanAIResponse(string $response): string
    {
        // Remove FIELD_COLLECTED markers
        $clean = preg_replace('/FIELD_COLLECTED:\w+=.+?(?=\n|FIELD_COLLECTED:|$)/s', '', $response);
        
        // Remove completion/cancellation markers
        $clean = str_replace(['DATA_COLLECTION_COMPLETE', 'DATA_COLLECTION_CANCELLED'], '', $clean);
        
        // Clean up extra whitespace
        $clean = preg_replace('/\n{3,}/', "\n\n", $clean);
        
        return trim($clean);
    }

    /**
     * Build context prompt with current state
     */
    protected function buildContextPrompt(DataCollectorState $state, DataCollectorConfig $config): string
    {
        $prompt = "CURRENT COLLECTION STATUS:\n";
        
        // Show collected fields
        $collected = array_filter($state->getData(), fn($v) => $v !== null && $v !== '');
        if (!empty($collected)) {
            $prompt .= "✓ Already collected (DO NOT ask for these again):\n";
            foreach ($collected as $field => $value) {
                $prompt .= "  - {$field}: {$value}\n";
            }
        }

        // Current field being collected - EMPHASIZE THIS
        if ($state->currentField) {
            $currentField = $config->getField($state->currentField);
            if ($currentField) {
                $prompt .= "\n" . str_repeat('━', 70) . "\n";
                $prompt .= "🎯 FOCUS: You are ONLY collecting this ONE field right now:\n";
                $prompt .= "   Field name: {$state->currentField}\n";
                $prompt .= "   Description: {$currentField->description}\n";
                $prompt .= "   Required: " . ($currentField->required ? 'YES' : 'NO') . "\n";
                
                if (!empty($currentField->examples)) {
                    $prompt .= "   Examples: " . implode(', ', $currentField->examples) . "\n";
                }
                
                if ($currentField->validation) {
                    $prompt .= "   Validation: {$currentField->validation}\n";
                }
                
                $prompt .= "\n⚠️  CRITICAL RULES:\n";
                $prompt .= "   1. ONLY ask for and acknowledge '{$state->currentField}' ({$currentField->description})\n";
                $prompt .= "   2. NEVER mention other field names or descriptions in your response\n";
                $prompt .= "   3. When acknowledging, say: 'I've recorded {$currentField->description}: [value]'\n";
                $prompt .= "   4. DO NOT say you've recorded ANY other field - only '{$state->currentField}'\n";
                $prompt .= "   5. If user provides info for other fields, ignore it completely for now\n";
                $prompt .= "   6. Ask for ONE field at a time in a conversational manner\n";
                $prompt .= "   7. Be helpful and provide examples when the user seems unsure\n";
                $prompt .= "   8. Validate user input and ask for corrections if needed\n";
                $prompt .= "   9. After collecting all required fields, provide a summary\n";
                $prompt .= "   10. Ask for confirmation before completing\n";
                $prompt .= str_repeat('━', 70) . "\n";
            }
        }

        // Show remaining fields (de-emphasized)
        $missing = $config->getMissingFields($state->getData());
        if (!empty($missing) && count($missing) > 1) {
            $prompt .= "\n○ Still need to collect later (not now):\n";
            foreach ($missing as $name => $field) {
                if ($name !== $state->currentField) {
                    $required = $field->required ? '(required)' : '(optional)';
                    $prompt .= "  - {$name} {$required}\n";
                }
            }
        }

        // Validation errors
        if (!empty($state->validationErrors)) {
            $prompt .= "\n❌ Validation errors to address:\n";
            foreach ($state->validationErrors as $field => $errors) {
                $prompt .= "  - {$field}: " . implode(', ', $errors) . "\n";
            }
        }

        // Strong reminder to use field markers (repeated in every request)
        $prompt .= "\n" . str_repeat('=', 70) . "\n";
        $prompt .= "⚠️  CRITICAL FORMAT REQUIREMENT (MUST FOLLOW):\n";
        $prompt .= "When you extract the '{$state->currentField}' field value, you MUST add:\n";
        $prompt .= "FIELD_COLLECTED:{$state->currentField}=value\n";
        $prompt .= "Place the marker at the END of your response.\n";
        $prompt .= "\n⚠️  FIELD NAME VALIDATION (CRITICAL):\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "CURRENT FIELD: '{$state->currentField}' = {$currentField->description}\n";
        $prompt .= "\nWHAT YOU MUST SAY:\n";
        $prompt .= "✓ 'I've recorded {$currentField->description}: [value]'\n";
        $prompt .= "✓ 'Great! I've noted the {$currentField->description}'\n";
        $prompt .= "✓ 'Perfect! {$currentField->description} recorded'\n";
        $prompt .= "\nWHAT YOU MUST NOT SAY:\n";
        foreach ($config->getFields() as $fname => $fld) {
            if ($fname !== $state->currentField) {
                $prompt .= "✗ DO NOT mention '{$fname}' or '{$fld->description}'\n";
            }
        }
        $prompt .= "\nREMEMBER: You are ONLY collecting '{$state->currentField}' right now!\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= str_repeat('=', 70) . "\n";

        return $prompt;
    }

    /**
     * Get the next field to collect
     */
    protected function getNextFieldToCollect(DataCollectorState $state, DataCollectorConfig $config): ?DataCollectorField
    {
        // First, try to get missing required fields
        $missing = $config->getMissingFields($state->getData());
        
        if (!empty($missing)) {
            return reset($missing);
        }

        // Then, check optional fields if allowed
        if ($config->allowSkipOptional) {
            return null;
        }

        foreach ($config->getOptionalFields() as $name => $field) {
            if (!$state->hasField($name)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Check if user is selecting a suggestion by number
     */
    protected function checkSuggestionSelection(string $message, DataCollectorState $state): ?string
    {
        // Check if we have stored suggestions
        if (empty($state->lastSuggestions) || $state->lastSuggestions['field'] !== $state->currentField) {
            return null;
        }
        
        $trimmedMessage = trim($message);
        
        // Check if message is just a number (1, 2, 3, etc.)
        if (preg_match('/^(\d+)\.?$/', $trimmedMessage, $matches)) {
            $selectedNumber = (int)$matches[1];
            
            // Parse suggestions to extract the text for the selected number
            $suggestions = $state->lastSuggestions['suggestions'];
            $lines = explode("\n", $suggestions);
            
            foreach ($lines as $line) {
                $line = trim($line);
                // Match patterns like "1. suggestion text" or "1- suggestion text" or "١. suggestion text" (Arabic)
                if (preg_match('/^[١٢٣٤٥٦٧٨٩٠]?\.?\s*' . $selectedNumber . '[\.:\-\)]\s*(.+)$/u', $line, $lineMatches)) {
                    return trim($lineMatches[1]);
                }
                if (preg_match('/^' . $selectedNumber . '[\.:\-\)]\s*(.+)$/u', $line, $lineMatches)) {
                    return trim($lineMatches[1]);
                }
            }
            
            Log::channel('ai-engine')->warning('User selected suggestion number but could not extract text', [
                'selected_number' => $selectedNumber,
                'suggestions_preview' => substr($suggestions, 0, 200),
            ]);
        }
        
        return null;
    }
    
    /**
     * Detect locale from user message
     */
    protected function detectLocale(string $message): string
    {
        // Check for Arabic characters
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $message)) {
            return 'ar';
        }
        
        // Default to English
        return 'en';
    }

    /**
     * Check if message is a cancellation request
     */
    protected function isCancellationRequest(string $message): bool
    {
        $cancelPhrases = ['cancel', 'stop', 'quit', 'exit', 'abort', 'nevermind', 'never mind'];
        $lowerMessage = strtolower(trim($message));
        
        foreach ($cancelPhrases as $phrase) {
            if ($lowerMessage === $phrase || str_starts_with($lowerMessage, $phrase . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get session state
     */
    public function getState(string $sessionId): ?DataCollectorState
    {
        $data = Cache::get($this->cachePrefix . $sessionId);
        
        if (!$data) {
            return null;
        }

        return DataCollectorState::fromArray($data);
    }

    /**
     * Save session state
     */
    protected function saveState(DataCollectorState $state): void
    {
        Cache::put(
            $this->cachePrefix . $state->sessionId,
            $state->toArray(),
            $this->cacheTtl
        );
    }

    /**
     * Save config to cache for persistence across requests
     */
    public function saveConfig(DataCollectorConfig $config): void
    {
        $key = $this->cachePrefix . 'config_' . $config->name;
        
        Cache::put(
            $key,
            $config->toArray(),
            $this->cacheTtl
        );
        
        Log::channel('ai-engine')->debug('Config saved to cache', [
            'key' => $key,
            'name' => $config->name,
            'title' => $config->title,
        ]);
    }

    /**
     * Load config from cache
     */
    public function loadConfig(string $name): ?DataCollectorConfig
    {
        $key = $this->cachePrefix . 'config_' . $name;
        $data = Cache::get($key);
        
        if (!$data) {
            Log::channel('ai-engine')->debug('Config not found in cache', [
                'key' => $key,
                'name' => $name,
            ]);
            return null;
        }

        Log::channel('ai-engine')->debug('Config loaded from cache', [
            'key' => $key,
            'name' => $name,
        ]);
        
        return DataCollectorConfig::fromArray($data);
    }

    /**
     * Delete session state
     */
    public function deleteState(string $sessionId): void
    {
        Cache::forget($this->cachePrefix . $sessionId);
    }

    /**
     * Check if a session exists
     */
    public function hasSession(string $sessionId): bool
    {
        return Cache::has($this->cachePrefix . $sessionId);
    }

    /**
     * Get the initial greeting message for a data collection session
     */
    public function getGreeting(DataCollectorConfig $config, ?DataCollectorState $state = null): string
    {
        $locale = $state?->detectedLocale ?? $config->locale ?? 'en';
        
        // Localized greeting phrases
        $greetings = [
            'en' => [
                'hello' => "Hello! I'll help you collect the required information. Let's get started!",
                'provide' => "Please provide the",
                'already_have' => "I already have some information:",
            ],
            'ar' => [
                'hello' => "مرحباً! سأساعدك في جمع المعلومات المطلوبة. لنبدأ!",
                'provide' => "يرجى تقديم",
                'already_have' => "لدي بالفعل بعض المعلومات:",
            ],
        ];
        
        $phrases = $greetings[$locale] ?? $greetings['en'];
        
        $greeting = $phrases['hello'] . "\n\n";

        // If we have initial data, mention it
        if ($state && !empty($state->getData())) {
            $collectedData = array_filter($state->getData(), fn($v) => $v !== null && $v !== '');
            if (!empty($collectedData)) {
                $greeting .= $phrases['already_have'] . "\n";
                foreach ($collectedData as $field => $value) {
                    $fieldObj = $config->getField($field);
                    $fieldLabel = $fieldObj ? $fieldObj->description : $field;
                    $greeting .= "✓ {$fieldLabel}: {$value}\n";
                }
                $greeting .= "\n";
            }
        }

        // Get the next uncollected field
        $nextField = $state ? $this->getNextFieldToCollect($state, $config) : $config->getFirstField();
        
        if ($nextField) {
            $fieldName = $nextField->description ?: $nextField->name;
            $greeting .= $phrases['provide'] . ": " . $fieldName;
            
            // Add validation hints
            if ($nextField->validation) {
                $hints = $this->getValidationHints($nextField->validation, $locale);
                if ($hints) {
                    $greeting .= "\n\n" . $hints;
                }
            }
        }

        return $greeting;
    }
    
    /**
     * Get human-readable validation hints
     */
    protected function getValidationHints(string $validation, string $locale = 'en'): string
    {
        $hints = [];
        $rules = explode('|', $validation);
        
        $labels = [
            'en' => [
                'required' => 'Required',
                'min' => 'minimum %d characters',
                'max' => 'maximum %d characters',
            ],
            'ar' => [
                'required' => 'مطلوب',
                'min' => 'الحد الأدنى %d حرف',
                'max' => 'الحد الأقصى %d حرف',
            ],
        ];
        
        $l = $labels[$locale] ?? $labels['en'];
        
        foreach ($rules as $rule) {
            if ($rule === 'required') {
                // Skip required, it's implied
                continue;
            }
            if (preg_match('/^min:(\d+)$/', $rule, $matches)) {
                $hints[] = sprintf($l['min'], $matches[1]);
            }
            if (preg_match('/^max:(\d+)$/', $rule, $matches)) {
                $hints[] = sprintf($l['max'], $matches[1]);
            }
        }
        
        if (empty($hints)) {
            return '';
        }
        
        $prefix = $locale === 'ar' ? 'المتطلبات: ' : 'Requirements: ';
        return $prefix . implode(', ', $hints);
    }

    /**
     * Extract structured data from file content using AI
     * 
     * @param string $sessionId
     * @param string $content File content
     * @param array $fields Field names to extract
     * @param string $language
     * @return array Extracted data
     */
    public function extractDataFromContent(
        string $sessionId,
        string $content,
        array $fields,
        string $language = 'en',
        array $fieldConfig = []
    ): array {
        // Get config from session if available
        $state = $this->getState($sessionId);
        $config = $state ? $this->getConfig($state->configName) : null;
        
        // Build field descriptions with validation hints for the AI
        $fieldDescriptions = [];
        foreach ($fields as $fieldName) {
            // Try to get field from config, or use passed fieldConfig
            $field = $config?->getField($fieldName);
            $fieldData = $fieldConfig[$fieldName] ?? [];
            
            $description = $field?->description ?? $fieldData['description'] ?? ucfirst(str_replace('_', ' ', $fieldName));
            $validation = $field?->validation ?? $fieldData['validation'] ?? '';
            $type = $field?->type ?? $fieldData['type'] ?? 'text';
            $options = $field?->options ?? $fieldData['options'] ?? [];
            
            // Add validation hints
            $hints = [];
            if ($validation) {
                if (str_contains($validation, 'numeric')) {
                    $hints[] = 'must be a number only (no text)';
                }
                if (str_contains($validation, 'integer')) {
                    $hints[] = 'must be an integer';
                }
                if (preg_match('/min:(\d+)/', $validation, $matches)) {
                    $hints[] = "minimum: {$matches[1]}";
                }
                if (preg_match('/max:(\d+)/', $validation, $matches)) {
                    $hints[] = "maximum: {$matches[1]}";
                }
            }
            
            // Add options for select fields
            if ($type === 'select' && !empty($options)) {
                $hints[] = 'must be one of: ' . implode(', ', $options);
            }
            
            $fieldDescriptions[$fieldName] = $description . ($hints ? ' (' . implode(', ', $hints) . ')' : '');
        }

        // Build extraction prompt
        $prompt = $this->buildExtractionPrompt($content, $fieldDescriptions, $language);

        try {
            // Use AI to extract data
            $systemPrompt = "You are a data extraction assistant. Extract structured data from the provided content and return it as valid JSON only. Do not include any text outside the JSON object.";
            
            $response = $this->aiEngine
                ->engine('openai')
                ->model('gpt-4o')
                ->withSystemPrompt($systemPrompt)
                ->withMaxTokens(2000)
                ->generate($prompt);

            $responseContent = $response->getContent();
            
            // Clean markdown code blocks if present
            $responseContent = trim($responseContent);
            if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $responseContent, $matches)) {
                $responseContent = trim($matches[1]);
            }
            
            $extracted = json_decode($responseContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to parse AI extraction response', ['response' => $responseContent]);
                return [];
            }

            // Filter to only include requested fields
            $result = [];
            foreach ($fields as $field) {
                if (isset($extracted[$field]) && !empty($extracted[$field])) {
                    $result[$field] = $extracted[$field];
                }
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('AI extraction failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Build the extraction prompt for AI
     */
    protected function buildExtractionPrompt(string $content, array $fieldDescriptions, string $language): string
    {
        $fieldsJson = json_encode($fieldDescriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if ($language === 'ar') {
            return <<<PROMPT
قم بتحليل المحتوى التالي واستخراج المعلومات المطلوبة.

المحتوى:
{$content}

الحقول المطلوبة:
{$fieldsJson}

قم بإرجاع البيانات المستخرجة بتنسيق JSON فقط. إذا لم تجد قيمة لحقل معين، اتركه فارغاً.
لا تضف أي نص إضافي، فقط JSON.

مثال على التنسيق المطلوب:
{"field_name": "extracted_value", "another_field": "another_value"}
PROMPT;
        }

        return <<<PROMPT
Analyze the following content and extract the required information.

Content:
{$content}

Required fields:
{$fieldsJson}

Return the extracted data in JSON format only. If you cannot find a value for a field, leave it empty.
Do not add any additional text, only JSON.

Example format:
{"field_name": "extracted_value", "another_field": "another_value"}
PROMPT;
    }

    /**
     * Apply extracted data to session and move to confirmation
     * 
     * @param string $sessionId
     * @param array $extractedData
     * @param string $language
     * @return DataCollectorResponse
     */
    public function applyExtractedData(
        string $sessionId,
        array $extractedData,
        string $language = 'en'
    ): DataCollectorResponse {
        $state = $this->getState($sessionId);
        
        if (!$state) {
            return new DataCollectorResponse(
                success: false,
                message: $language === 'ar' 
                    ? 'لم يتم العثور على جلسة نشطة.' 
                    : 'No active session found.',
                state: null,
            );
        }

        $config = $this->getConfig($state->configName);
        
        if (!$config) {
            return new DataCollectorResponse(
                success: false,
                message: $language === 'ar' 
                    ? 'لم يتم العثور على التكوين.' 
                    : 'Configuration not found.',
                state: $state,
            );
        }

        // Apply extracted data to state
        foreach ($extractedData as $field => $value) {
            if (!empty($value)) {
                $state->collectedData[$field] = $value;
            }
        }

        // Mark all fields as collected
        $state->currentField = null;
        $state->status = DataCollectorState::STATUS_CONFIRMING;

        // Save updated state
        $this->saveState($state);

        // Build confirmation summary
        $summary = $this->buildConfirmationSummary($state, $config, $language);

        $message = $language === 'ar'
            ? "تم استخراج البيانات من الملف وتطبيقها. يرجى مراجعة المعلومات التالية:\n\n{$summary}\n\nهل هذه المعلومات صحيحة؟"
            : "Data extracted from file and applied. Please review the following information:\n\n{$summary}\n\nIs this information correct?";

        return new DataCollectorResponse(
            success: true,
            message: $message,
            state: $state,
            requiresConfirmation: true,
            collectedFields: array_keys($state->collectedData),
        );
    }

    /**
     * Build confirmation summary for extracted data
     */
    protected function buildConfirmationSummary(DataCollectorState $state, DataCollectorConfig $config, string $language): string
    {
        $summary = '';
        
        foreach ($state->collectedData as $fieldName => $value) {
            $field = $config->getField($fieldName);
            $label = $field?->description ?? ucfirst(str_replace('_', ' ', $fieldName));
            $summary .= "• **{$label}**: {$value}\n";
        }

        return $summary ?: ($language === 'ar' ? 'لا توجد بيانات' : 'No data');
    }
}
