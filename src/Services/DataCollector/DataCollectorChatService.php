<?php

namespace LaravelAIEngine\Services\DataCollector;

use LaravelAIEngine\DTOs\DataCollectorConfig;
use LaravelAIEngine\DTOs\DataCollectorState;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\ChatService;
use Illuminate\Support\Facades\Log;

/**
 * Chat service wrapper for data collection
 * 
 * Integrates DataCollectorService with the existing ChatService
 * to provide a seamless chat experience for data collection.
 */
class DataCollectorChatService
{
    public function __construct(
        protected DataCollectorService $dataCollector,
        protected ?ChatService $chatService = null
    ) {}

    /**
     * Start a new data collection chat session
     * 
     * @param string $sessionId Unique session identifier
     * @param DataCollectorConfig $config Configuration for data collection
     * @param array $initialData Pre-filled data (optional)
     * @return AIResponse
     */
    public function startCollection(
        string $sessionId,
        DataCollectorConfig $config,
        array $initialData = []
    ): AIResponse {
        // Start the data collection session
        $state = $this->dataCollector->startSession($sessionId, $config, $initialData);
        
        // Get the greeting message
        $greeting = $this->dataCollector->getGreeting($config);
        
        // Build actions for the response
        $actions = $this->buildActions($state, $config);

        // Build comprehensive metadata including config details
        $metadata = [
            'data_collector' => true,
            'config_name' => $config->name,
            'status' => $state->status,
            'fields' => $config->getFieldNames(),
            'current_field' => $state->currentField,
            'collected_fields' => [],
            'remaining_fields' => $config->getFieldNames(),
            'progress' => 0,
            'data' => [],
            'config' => [
                'title' => $config->title,
                'description' => $config->description,
                'confirm_before_complete' => $config->confirmBeforeComplete,
                'allow_enhancement' => $config->allowEnhancement,
                'allow_skip_optional' => $config->allowSkipOptional,
                'success_message' => $config->successMessage,
                'cancel_message' => $config->cancelMessage,
                'action_summary' => $config->actionSummary,
                'action_summary_prompt' => $config->actionSummaryPrompt,
                'output_schema' => $config->outputSchema,
                'locale' => $config->locale,
                'detect_locale' => $config->detectLocale,
            ],
            // Include field definitions for frontend
            'field_definitions' => array_map(fn($f) => $f->toArray(), $config->getFields()),
        ];

        return $this->buildResponse(
            content: $greeting,
            state: $state,
            actions: $actions,
            metadata: $metadata
        );
    }

    /**
     * Process a message in an active data collection session
     * 
     * @param string $sessionId Session identifier
     * @param string $message User's message
     * @param string $engine AI engine to use
     * @param string $model AI model to use
     * @return AIResponse
     */
    public function processMessage(
        string $sessionId,
        string $message,
        string $engine = 'openai',
        string $model = 'gpt-4o'
    ): AIResponse {
        // Check if there's an active session
        if (!$this->dataCollector->hasSession($sessionId)) {
            return $this->buildErrorResponse(
                "No active data collection session found. Please start a new session.",
                $engine,
                $model
            );
        }

        // Process the message
        $response = $this->dataCollector->processMessage(
            $sessionId,
            $message,
            $engine,
            $model
        );

        // Get config for building actions
        $config = $this->dataCollector->getConfig($response->state?->configName ?? '');
        
        // Build actions based on response state
        $actions = $this->buildActions($response->state, $config);

        // Build metadata
        $metadata = [
            'data_collector' => true,
            'config_name' => $config?->name,
            'status' => $response->state?->status,
            'is_complete' => $response->isComplete,
            'is_cancelled' => $response->isCancelled,
            'requires_confirmation' => $response->requiresConfirmation,
            'allows_enhancement' => $response->allowsEnhancement,
            'current_field' => $response->currentField,
            'collected_fields' => $response->collectedFields,
            'remaining_fields' => $response->remainingFields,
            'fields' => $config?->getFieldNames() ?? [],
            'progress' => $response->getProgress(),
            'validation_errors' => $response->validationErrors,
            'data' => $response->getData(),
        ];

        if ($response->summary) {
            $metadata['summary'] = $response->summary;
        }

        if ($response->actionSummary) {
            $metadata['action_summary'] = $response->actionSummary;
        }

        if ($response->result !== null) {
            $metadata['result'] = $response->result;
        }

        if ($response->generatedOutput !== null) {
            $metadata['generated_output'] = $response->generatedOutput;
        }

        // Include config metadata if available
        if ($config) {
            $metadata['config'] = [
                'title' => $config->title,
                'description' => $config->description,
                'confirm_before_complete' => $config->confirmBeforeComplete,
                'allow_enhancement' => $config->allowEnhancement,
                'allow_skip_optional' => $config->allowSkipOptional,
                'success_message' => $config->successMessage,
                'cancel_message' => $config->cancelMessage,
                'action_summary' => $config->actionSummary,
                'locale' => $config->locale,
            ];
        }

        // If complete or cancelled, clean up session after a delay
        if ($response->isFinished()) {
            $metadata['session_ended'] = true;
            // Don't delete immediately - let the client handle it
        }

        return $this->buildResponse(
            content: $response->message,
            state: $response->state,
            actions: $actions,
            metadata: $metadata,
            success: $response->success
        );
    }

    /**
     * Check if a session is a data collection session
     */
    public function isDataCollectionSession(string $sessionId): bool
    {
        return $this->dataCollector->hasSession($sessionId);
    }

    /**
     * Get the current state of a data collection session
     */
    public function getSessionState(string $sessionId): ?DataCollectorState
    {
        return $this->dataCollector->getState($sessionId);
    }

    /**
     * Cancel a data collection session
     */
    public function cancelSession(string $sessionId): AIResponse
    {
        $state = $this->dataCollector->getState($sessionId);
        
        if (!$state) {
            return $this->buildErrorResponse(
                "No active session found.",
                'openai',
                'gpt-4o'
            );
        }

        $config = $this->dataCollector->getConfig($state->configName);
        
        // Process cancellation
        $response = $this->dataCollector->processMessage(
            $sessionId,
            'cancel',
            'openai',
            'gpt-4o'
        );

        return $this->buildResponse(
            content: $response->message,
            state: $response->state,
            actions: [],
            metadata: [
                'data_collector' => true,
                'is_cancelled' => true,
                'session_ended' => true,
            ]
        );
    }

    /**
     * Get collected data from a session
     */
    public function getCollectedData(string $sessionId): array
    {
        $state = $this->dataCollector->getState($sessionId);
        return $state?->getData() ?? [];
    }

    /**
     * Build interactive actions based on current state
     */
    protected function buildActions(?DataCollectorState $state, ?DataCollectorConfig $config): array
    {
        if (!$state || !$config) {
            return [];
        }

        $actions = [];

        // Add field-specific quick actions
        if ($state->status === DataCollectorState::STATUS_COLLECTING) {
            $currentField = $config->getField($state->currentField ?? '');
            
            if ($currentField && !empty($currentField->options)) {
                // Add option buttons for select fields
                foreach ($currentField->options as $option) {
                    $actions[] = new InteractiveAction(
                        id: 'option_' . md5($option),
                        type: new ActionTypeEnum(ActionTypeEnum::QUICK_REPLY),
                        label: ucfirst($option),
                        description: null,
                        data: [
                            'action' => 'select_option',
                            'field' => $currentField->name,
                            'value' => $option,
                            'reply' => $option,
                        ]
                    );
                }
            }

            // Add skip button for optional fields
            if ($currentField && !$currentField->required && $config->allowSkipOptional) {
                $actions[] = new InteractiveAction(
                    id: 'skip_field',
                    type: new ActionTypeEnum(ActionTypeEnum::BUTTON),
                    label: '⏭️ Skip this field',
                    description: null,
                    data: [
                        'action' => 'skip_field',
                        'field' => $currentField->name,
                        'reply' => 'skip',
                    ]
                );
            }
        }

        // Add confirmation actions
        if ($state->status === DataCollectorState::STATUS_CONFIRMING) {
            $actions[] = new InteractiveAction(
                id: 'confirm_yes',
                type: new ActionTypeEnum(ActionTypeEnum::BUTTON),
                label: '✅ Yes, confirm',
                description: null,
                data: [
                    'action' => 'confirm',
                    'reply' => 'yes',
                ]
            );

            if ($config->allowEnhancement) {
                $actions[] = new InteractiveAction(
                    id: 'confirm_modify',
                    type: new ActionTypeEnum(ActionTypeEnum::BUTTON),
                    label: '✏️ Make changes',
                    description: null,
                    data: [
                        'action' => 'modify',
                        'reply' => 'no',
                    ]
                );
            }
        }

        // Add enhancement actions
        if ($state->status === DataCollectorState::STATUS_ENHANCING) {
            // Add buttons for each field that can be modified
            foreach ($config->getFields() as $name => $field) {
                $actions[] = new InteractiveAction(
                    id: 'edit_' . $name,
                    type: new ActionTypeEnum(ActionTypeEnum::QUICK_REPLY),
                    label: "Edit " . ucwords(str_replace('_', ' ', $name)),
                    description: null,
                    data: [
                        'action' => 'edit_field',
                        'field' => $name,
                        'reply' => "I want to change the {$name}",
                    ]
                );
            }

            $actions[] = new InteractiveAction(
                id: 'done_editing',
                type: new ActionTypeEnum(ActionTypeEnum::BUTTON),
                label: '✅ Done editing',
                description: null,
                data: [
                    'action' => 'done_editing',
                    'reply' => 'done',
                ]
            );
        }

        // Always add cancel action (except when complete)
        if ($state->isInProgress()) {
            $actions[] = new InteractiveAction(
                id: 'cancel_collection',
                type: new ActionTypeEnum(ActionTypeEnum::BUTTON),
                label: '❌ Cancel',
                description: null,
                data: [
                    'action' => 'cancel',
                    'reply' => 'cancel',
                ]
            );
        }

        return $actions;
    }

    /**
     * Build an AIResponse
     */
    protected function buildResponse(
        string $content,
        ?DataCollectorState $state,
        array $actions,
        array $metadata,
        bool $success = true
    ): AIResponse {
        return new AIResponse(
            content: $content,
            engine: new EngineEnum(EngineEnum::OPENAI),
            model: new EntityEnum(EntityEnum::GPT_4O),
            metadata: $metadata,
            actions: array_map(fn($a) => $a instanceof InteractiveAction ? $a->toArray() : $a, $actions),
            success: $success,
            conversationId: $state?->sessionId
        );
    }

    /**
     * Build an error response
     */
    protected function buildErrorResponse(
        string $error,
        string $engine,
        string $model
    ): AIResponse {
        return new AIResponse(
            content: $error,
            engine: EngineEnum::from($engine),
            model: EntityEnum::from($model),
            metadata: ['data_collector' => true, 'error' => true],
            error: $error,
            success: false
        );
    }

    /**
     * Register a data collector configuration
     */
    public function registerConfig(DataCollectorConfig $config): self
    {
        $this->dataCollector->registerConfig($config);
        return $this;
    }

    /**
     * Get a registered configuration
     */
    public function getConfig(string $name): ?DataCollectorConfig
    {
        return $this->dataCollector->getConfig($name);
    }

    /**
     * Create a quick data collector from array definition
     * 
     * Example:
     * $chatService->createCollector('course_creator', [
     *     'title' => 'Create a New Course',
     *     'fields' => [
     *         'name' => 'Course name | required | min:3',
     *         'description' => 'Course description | required | min:50',
     *     ],
     *     'onComplete' => fn($data) => Course::create($data),
     * ]);
     */
    public function createCollector(string $name, array $definition): DataCollectorConfig
    {
        $definition['name'] = $name;
        $config = DataCollectorConfig::fromArray($definition);
        $this->registerConfig($config);
        return $config;
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
        return $this->dataCollector->extractDataFromContent($sessionId, $content, $fields, $language, $fieldConfig);
    }

    /**
     * Apply extracted data to session and move to confirmation
     * 
     * @param string $sessionId
     * @param array $extractedData
     * @param string $language
     * @return AIResponse
     */
    public function applyExtractedData(
        string $sessionId,
        array $extractedData,
        string $language = 'en'
    ): AIResponse {
        $response = $this->dataCollector->applyExtractedData($sessionId, $extractedData, $language);
        
        // Get config for building actions
        $config = $this->dataCollector->getConfig($response->state?->configName ?? '');
        
        // Build actions based on response state
        $actions = $this->buildActions($response->state, $config);

        // Get collected field names
        $collectedFieldNames = array_keys($response->state?->collectedData ?? []);
        $totalFields = $config ? count($config->getFieldNames()) : count($collectedFieldNames);
        
        return $this->buildResponse(
            content: $response->message,
            state: $response->state,
            actions: $actions,
            metadata: [
                'data_collector' => true,
                'status' => $response->state?->status ?? 'confirming',
                'requires_confirmation' => true,
                'collected_fields' => $collectedFieldNames,
                'fields' => $config?->getFieldNames() ?? $collectedFieldNames,
                'progress' => 100,
            ]
        );
    }
}
