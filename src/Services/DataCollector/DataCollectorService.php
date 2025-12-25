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
        $state = new DataCollectorState(
            sessionId: $sessionId,
            configName: $config->name,
            status: DataCollectorState::STATUS_COLLECTING,
            collectedData: $initialData,
            currentField: $config->getFirstField()?->name,
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
            return new DataCollectorResponse(
                success: false,
                message: 'Configuration not found.',
                state: $state,
            );
        }

        // Check for cancellation
        if ($this->isCancellationRequest($message)) {
            return $this->handleCancellation($state, $config);
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
                message: 'Session is not in an active state.',
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
        
        // Parse AI response for field extractions
        $extractedFields = $this->parseFieldExtractions($responseContent);
        
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
            $extractedFields = $this->extractFromUserMessage($message, $state->currentField, $config);
        }
        
        foreach ($extractedFields as $fieldName => $value) {
            $field = $config->getField($fieldName);
            if ($field) {
                // Validate the value
                $errors = $field->validate($value);
                if (empty($errors)) {
                    $state->setFieldValue($fieldName, $value);
                    $state->clearValidationErrors();
                } else {
                    $state->setValidationErrors([$fieldName => $errors]);
                }
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

        // Clean the response (remove field extraction markers)
        $cleanResponse = $this->cleanAIResponse($responseContent);
        
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
                $locale = $config->locale ?? 'en';
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
            return $this->handleCompletion($state, $config, $engine, $model);
        }

        // Check for rejection/modification request (English and Arabic)
        $rejectWords = ['no', 'n', 'change', 'modify', 'edit', 'wrong', 'incorrect', 'لا', 'تغيير', 'تعديل', 'خطأ', 'غلط'];
        if (in_array($lowerMessage, $rejectWords)) {
            if ($config->allowEnhancement) {
                $state->setStatus(DataCollectorState::STATUS_ENHANCING);
                
                return new DataCollectorResponse(
                    success: true,
                    message: "No problem! What would you like to change? You can specify the field name and the new value, or describe what you'd like to modify.",
                    state: $state,
                    allowsEnhancement: true,
                );
            } else {
                // Restart collection
                $state->setStatus(DataCollectorState::STATUS_COLLECTING);
                $state->collectedData = [];
                $state->setCurrentField($config->getFirstField()?->name);
                
                return new DataCollectorResponse(
                    success: true,
                    message: "Let's start over. " . $config->getFirstField()?->getCollectionPrompt(),
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
        return new DataCollectorResponse(
            success: true,
            message: "Please confirm if the information is correct by saying 'yes' or 'no'. If you'd like to make changes, say 'no' or specify what you'd like to modify.",
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
        $systemPrompt .= "Instructions:\n";
        $systemPrompt .= "1. Understand what the user wants to change\n";
        $systemPrompt .= "2. Extract the new value for the field\n";
        $systemPrompt .= "3. Respond with FIELD_COLLECTED:field_name=new_value\n";
        $systemPrompt .= "4. After the change, show the updated summary and ask for confirmation\n";
        $systemPrompt .= "5. If user is done with changes, ask them to confirm with 'yes'\n";

        $aiResponse = $this->generateAIResponse(
            $systemPrompt,
            "User wants to modify: " . $message,
            [], // Empty history - context prompt provides all needed state
            $engine,
            $model
        );

        $responseContent = $aiResponse->getContent();
        
        // Parse field extractions
        $extractedFields = $this->parseFieldExtractions($responseContent);
        
        foreach ($extractedFields as $fieldName => $value) {
            $field = $config->getField($fieldName);
            if ($field) {
                $errors = $field->validate($value);
                if (empty($errors)) {
                    $state->setFieldValue($fieldName, $value);
                    $state->clearValidationErrors();
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
            $actionSummary = $config->actionSummaryPrompt
                ? $this->generateAIActionSummary($config, $state->getData(), $engine, $model)
                : $config->generateActionSummary($state->getData());
            
            // Build confirmation message (with locale support)
            $locale = $config->locale ?? 'en';
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
        $locale = $config->locale ?? 'en';
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
        
        $locale = $config->locale ?? 'en';
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
            $generatedOutput = $this->generateStructuredOutput($config, $state->getData(), $engine, $model);
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
        array $data,
        string $engine = 'openai',
        string $model = 'gpt-4o'
    ): ?array {
        if (!$config->outputSchema) {
            return null;
        }

        try {
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

            $fullPrompt = $dataContext . "\n---\n\n" . $prompt;

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
    protected function parseFieldExtractions(string $response): array
    {
        $extracted = [];
        
        // Pattern: FIELD_COLLECTED:field_name=value
        preg_match_all('/FIELD_COLLECTED:(\w+)=(.+?)(?=\n|FIELD_COLLECTED:|$)/s', $response, $matches, PREG_SET_ORDER);
        
        Log::channel('ai-engine')->debug('Parsing field extractions', [
            'found_markers' => count($matches),
            'has_marker_text' => str_contains($response, 'FIELD_COLLECTED:'),
        ]);
        
        foreach ($matches as $match) {
            $fieldName = trim($match[1]);
            $value = trim($match[2]);
            $extracted[$fieldName] = $value;
        }

        // Only use fallback parser if we found at least one FIELD_COLLECTED marker
        // This prevents extracting from conversational text
        if (!empty($extracted)) {
            $fallbackExtracted = $this->parseFieldsFromSummary($response);
            foreach ($fallbackExtracted as $key => $value) {
                if (!isset($extracted[$key]) || empty($extracted[$key])) {
                    $extracted[$key] = $value;
                }
            }
        }

        return $extracted;
    }

    /**
     * Fallback parser for extracting fields from AI summary text
     * Handles formats like "**Course Name**: Laravel Basics" or "- **Duration**: 10 hours"
     */
    protected function parseFieldsFromSummary(string $response): array
    {
        $extracted = [];
        
        // Common field name mappings (display name => field key)
        $fieldMappings = [
            'course name' => 'name',
            'name' => 'name',
            'title' => 'name',
            'description' => 'description',
            'duration' => 'duration',
            'level' => 'level',
            'difficulty' => 'level',
            'difficulty level' => 'level',
            'lessons' => 'lessons_count',
            'number of lessons' => 'lessons_count',
            'lessons count' => 'lessons_count',
            'lesson count' => 'lessons_count',
            'total lessons' => 'lessons_count',
        ];

        // Pattern: **Label**: Value or - **Label:** Value or **Label:** Value
        // Handles both **Label**: and **Label:**
        preg_match_all('/\*\*([^*:]+):?\*\*:?\s*(.+?)(?=\n|$)/i', $response, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $label = strtolower(trim($match[1]));
            $value = trim($match[2]);
            
            // Map display name to field key
            $fieldKey = $fieldMappings[$label] ?? str_replace(' ', '_', $label);
            
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
                'value' => $value,
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
            $prompt .= "Already collected:\n";
            foreach ($collected as $field => $value) {
                $prompt .= "- {$field}: {$value}\n";
            }
        }

        // Show remaining fields
        $missing = $config->getMissingFields($state->getData());
        if (!empty($missing)) {
            $prompt .= "\nStill need to collect:\n";
            foreach ($missing as $name => $field) {
                $required = $field->required ? '(required)' : '(optional)';
                $prompt .= "- {$name} {$required}: {$field->description}\n";
            }
        }

        // Current field being collected
        if ($state->currentField) {
            $currentField = $config->getField($state->currentField);
            if ($currentField) {
                $prompt .= "\nCurrently asking for: {$state->currentField}\n";
                $prompt .= "Field details: {$currentField->description}\n";
                if (!empty($currentField->examples)) {
                    $prompt .= "Examples: " . implode(', ', $currentField->examples) . "\n";
                }
            }
        }

        // Validation errors
        if (!empty($state->validationErrors)) {
            $prompt .= "\nValidation errors to address:\n";
            foreach ($state->validationErrors as $field => $errors) {
                $prompt .= "- {$field}: " . implode(', ', $errors) . "\n";
            }
        }

        // Strong reminder to use field markers (repeated in every request)
        $prompt .= "\n" . str_repeat('=', 70) . "\n";
        $prompt .= "⚠️  CRITICAL FORMAT REQUIREMENT (MUST FOLLOW):\n";
        $prompt .= "When you extract ANY field value, you MUST add this marker:\n";
        $prompt .= "FIELD_COLLECTED:field_name=value\n";
        $prompt .= "Place ALL markers at the END of your response.\n";
        $prompt .= "Missing markers = DATA LOSS = FAILURE\n";
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
    public function getGreeting(DataCollectorConfig $config): string
    {
        $locale = $config->locale ?? 'en';
        
        // Localized greeting phrases
        $greetings = [
            'en' => [
                'hello' => "Hello! I'll help you collect the required information. Let's get started!",
                'provide' => "Please provide the",
            ],
            'ar' => [
                'hello' => "مرحباً! سأساعدك في جمع المعلومات المطلوبة. لنبدأ!",
                'provide' => "يرجى تقديم",
            ],
        ];
        
        $phrases = $greetings[$locale] ?? $greetings['en'];
        
        $greeting = $phrases['hello'] . "\n\n";

        $firstField = $config->getFirstField();
        if ($firstField) {
            $fieldName = $firstField->description ?: $firstField->name;
            $greeting .= $phrases['provide'] . ": " . $fieldName;
            
            // Add validation hints
            if ($firstField->validation) {
                $hints = $this->getValidationHints($firstField->validation, $locale);
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
