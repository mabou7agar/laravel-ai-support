<?php

namespace LaravelAIEngine\Builders;

class WorkflowConfigBuilder
{
    protected array $config = [
        'goal' => '',
        'fields' => [],
        'entities' => [],
        'conversational_guidance' => [],
        'final_action' => null,
        'extraction_example' => null,
    ];

    /**
     * Set the workflow goal/description
     */
    public function goal(string $goal): self
    {
        $this->config['goal'] = $goal;
        return $this;
    }

    /**
     * Add a field to collect
     *
     * @param string $name Field name
     * @param string|array $config Field configuration (string for simple, array for detailed)
     */
    public function field(string $name, string|array $config): self
    {
        if (is_string($config)) {
            // Parse simple format: "Description | required | type:email"
            $this->config['fields'][$name] = $this->parseSimpleField($config);
        } else {
            $this->config['fields'][$name] = $config;
        }

        return $this;
    }

    /**
     * Add multiple fields at once
     */
    public function fields(array $fields): self
    {
        foreach ($fields as $name => $config) {
            $this->field($name, $config);
        }

        return $this;
    }

    /**
     * Add an entity to resolve
     *
     * @param string $name Entity name (e.g., 'customer', 'products')
     * @param string $modelClass Model class to resolve
     * @param array $options Additional options
     */
    public function entity(string $name, string $modelClass, array $options = []): self
    {
        $this->config['entities'][$name] = array_merge([
            'model' => $modelClass,
        ], $options);

        return $this;
    }

    /**
     * Add entity with identifier field
     */
    public function entityWithIdentifier(
        string $name,
        string $identifierField,
        string $modelClass,
        array $options = []
    ): self {
        return $this->entity($name, $modelClass, array_merge([
            'identifier_field' => $identifierField,
        ], $options));
    }

    /**
     * Add entity with subworkflow for creation
     */
    public function entityWithSubflow(
        string $name,
        string $identifierField,
        string $modelClass,
        string $subflowClass,
        array $options = []
    ): self {
        return $this->entity($name, $modelClass, array_merge([
            'identifier_field' => $identifierField,
            'create_if_missing' => true,
            'subflow' => $subflowClass,
        ], $options));
    }

    /**
     * Add a single entity field using EntityFieldConfig DTO
     * Provides type-safe, fluent configuration
     */
    public function entityField(string $identifierField, \LaravelAIEngine\DTOs\EntityFieldConfig $config): self
    {
        $entityName = preg_replace('/_id$/', '', $identifierField);
        $configArray = $config->toArray();
        $configArray['identifier_field'] = $identifierField;
        
        $this->entity($entityName, $config->model, $configArray);
        
        // Also add field definition for data collection
        $this->field($identifierField, [
            'type' => 'entity',
            'required' => $configArray['required'] ?? true,
            'description' => $configArray['description'] ?? ucfirst($entityName),
            'prompt' => $configArray['prompt'] ?? "What is the {$entityName}?",
        ]);
        
        return $this;
    }

    /**
     * Add multiple entities field using EntityFieldConfig DTO
     * For array/collection of entities (e.g., products, items)
     * 
     * @param string $identifierField The field name (e.g., 'items')
     * @param \LaravelAIEngine\DTOs\EntityFieldConfig $config Entity configuration
     * @param string $entityName Entity name for context storage (e.g., 'products')
     */
    public function entitiesField(string $identifierField, \LaravelAIEngine\DTOs\EntityFieldConfig $config, string $entityName): self
    {
        $configArray = $config->toArray();
        $configArray['identifier_field'] = $identifierField;
        $configArray['multiple'] = true;
        
        $this->entity($entityName, $config->model, $configArray);
        
        // Also add field definition for data collection
        $this->field($identifierField, [
            'type' => 'entity',
            'required' => $configArray['required'] ?? true,
            'description' => $configArray['description'] ?? ucfirst($entityName),
            'prompt' => $configArray['prompt'] ?? "What {$entityName} would you like to add?",
        ]);
        
        return $this;
    }

    /**
     * Add multiple entities (like products)
     */
    public function multipleEntities(
        string $name,
        string $identifierField,
        string $modelClass,
        array $options = []
    ): self {
        return $this->entity($name, $modelClass, array_merge([
            'identifier_field' => $identifierField,
            'multiple' => true,
        ], $options));
    }

    /**
     * Add conversational guidance
     */
    public function guidance(array|string $guidance): self
    {
        if (is_string($guidance)) {
            $this->config['conversational_guidance'][] = $guidance;
        } else {
            $this->config['conversational_guidance'] = array_merge(
                $this->config['conversational_guidance'],
                $guidance
            );
        }

        return $this;
    }

    /**
     * Set the final action to execute
     */
    public function finalAction(callable|string $action): self
    {
        $this->config['final_action'] = $action;
        return $this;
    }

    /**
     * Enable confirmation before completing the workflow
     *
     * @param bool $confirm Whether to show confirmation
     * @param bool $skipInSubflow Whether to skip confirmation when running as a subflow (default: true)
     */
    public function confirmBeforeComplete(bool $confirm = true, bool $skipInSubflow = true): self
    {
        $this->config['confirm_before_complete'] = $confirm;
        $this->config['skip_confirmation_in_subflow'] = $skipInSubflow;
        return $this;
    }

    /**
     * Set custom confirmation message format rules
     * These rules are passed to the AI when generating confirmation messages
     */
    public function confirmationFormat(string $format): self
    {
        $this->config['confirmation_format'] = $format;
        return $this;
    }

    /**
     * Set example output format for AI extraction
     * Provides a concrete example to guide AI without being overly prescriptive
     */
    public function extractionExample(string|array $example): self
    {
        $this->config['extraction_example'] = is_array($example) ? json_encode($example) : $example;
        return $this;
    }

    /**
     * Import entity configuration from a model's AI config
     *
     * @param string $modelClass Model class with AI configuration
     * @param array $fieldMapping Map model fields to workflow entity names
     */
    public function fromModel(string $modelClass, array $fieldMapping = []): self
    {
        // Get model's AI configuration - support both static and instance methods
        $aiConfig = null;

        if (method_exists($modelClass, 'getAIConfig')) {
            // Static method (legacy)
            $aiConfig = $modelClass::getAIConfig();
        } elseif (method_exists($modelClass, 'initializeAI')) {
            // Instance method (new approach)
            $instance = new $modelClass();
            $aiConfig = $instance->initializeAI();
        } else {
            throw new \Exception("Model {$modelClass} does not have AI configuration (missing getAIConfig() or initializeAI() method)");
        }

        // Import goal from model if available
        if (empty($this->config['goal']) && !empty($aiConfig['goal'])) {
            $this->config['goal'] = $aiConfig['goal'];
        }

        // Fallback: Import description as goal if goal not set
        if (empty($this->config['goal']) && !empty($aiConfig['description'])) {
            $this->config['goal'] = $aiConfig['description'];
        }

        // Import regular fields
        if (!empty($aiConfig['fields'])) {
            foreach ($aiConfig['fields'] as $fieldName => $fieldConfig) {
                // Don't overwrite existing fields
                if (!isset($this->config['fields'][$fieldName])) {
                    $this->config['fields'][$fieldName] = $fieldConfig;
                }
            }
        }

        // Import entity fields from model
        if (!empty($aiConfig['entities'])) {
            foreach ($aiConfig['entities'] as $fieldName => $entityConfig) {
                // Determine entity name (use mapping or derive from field name)
                $entityName = $fieldMapping[$fieldName] ?? $this->deriveEntityName($fieldName);

                // Auto-generate field definition for data collection
                $isMultiple = !empty($entityConfig['multiple']) || str_ends_with($fieldName, 's') || str_contains($fieldName, 'items');

                // Generate context-aware prompt based on entity type
                $singlePrompt = $this->generateEntityPrompt($entityName, $entityConfig);
                $multiplePrompt = $this->generateEntityPrompt($entityName, $entityConfig, true);

                $fieldDef = [
                    'type' => 'entity', // Mark as entity type so AI extraction skips it
                    'required' => true,
                    'description' => ucfirst($entityName) . ' identifier',
                    'prompt' => $isMultiple ? $multiplePrompt : $singlePrompt,
                ];

                // Import parsing guide if available
                if (!empty($entityConfig['parsing_guide'])) {
                    $fieldDef['parsing_guide'] = $entityConfig['parsing_guide'];
                }

                $this->config['fields'][$fieldName] = $fieldDef;

                // Convert AI config entity to workflow entity
                $workflowEntity = [
                    'model' => $entityConfig['model'],
                ];

                // Add identifier field (use the field name from model)
                $workflowEntity['identifier_field'] = $fieldName;

                // Import search fields if available
                if (!empty($entityConfig['search_fields'])) {
                    $workflowEntity['search_fields'] = $entityConfig['search_fields'];
                }

                // Import filters if available
                if (!empty($entityConfig['filters'])) {
                    $workflowEntity['filters'] = $entityConfig['filters'];
                }

                // Import subflow if available
                if (!empty($entityConfig['subflow'])) {
                    $workflowEntity['subflow'] = $entityConfig['subflow'];
                    $workflowEntity['create_if_missing'] = true;
                }

                // Import identifier provider if available
                if (!empty($entityConfig['identifier_provider'])) {
                    $workflowEntity['identifier_provider'] = $entityConfig['identifier_provider'];
                }

                // Import confirm_before_create if available
                if (!empty($entityConfig['confirm_before_create'])) {
                    $workflowEntity['confirm_before_create'] = $entityConfig['confirm_before_create'];
                }

                // Import check_duplicates if available
                if (isset($entityConfig['check_duplicates'])) {
                    $workflowEntity['check_duplicates'] = $entityConfig['check_duplicates'];
                }

                // Import ask_on_duplicate if available
                if (isset($entityConfig['ask_on_duplicate'])) {
                    $workflowEntity['ask_on_duplicate'] = $entityConfig['ask_on_duplicate'];
                }

                // Check if it's a multiple entity (array)
                if ($isMultiple) {
                    $workflowEntity['multiple'] = true;
                }

                // Add to workflow entities
                $this->config['entities'][$entityName] = $workflowEntity;
            }
        }

        return $this;
    }

    /**
     * Derive entity name from field name
     * Examples: customer_id -> customer, items -> products
     */
    protected function deriveEntityName(string $fieldName): string
    {
        // Remove common suffixes
        $name = preg_replace('/_id$/', '', $fieldName);

        // Handle plural to singular for common cases
        if ($name === 'items') {
            return 'products';
        }

        return $name;
    }

    /**
     * Detect if this workflow is being used as a subflow
     * If the model has entities that reference this workflow as a subflow, skip entity imports
     */
    protected function detectSubflowContext(string $modelClass, array $aiConfig): bool
    {
        // Get the current workflow class from debug backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $workflowClass = null;

        foreach ($trace as $frame) {
            if (isset($frame['class']) && str_contains($frame['class'], 'Workflow')) {
                $workflowClass = $frame['class'];
                break;
            }
        }

        if (!$workflowClass) {
            return false;
        }

        // Check if any entity in the model config has this workflow as a subflow
        if (!empty($aiConfig['entities'])) {
            foreach ($aiConfig['entities'] as $entityConfig) {
                if (isset($entityConfig['subflow']) && $entityConfig['subflow'] === $workflowClass) {
                    // This workflow is being used as a subflow for this model
                    // Skip entity imports to prevent circular dependency
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Set arbitrary configuration value
     * Allows workflows to define custom configuration
     */
    public function set(string $key, mixed $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * Build and return the configuration array
     */
    public function build(): array
    {
        return $this->config;
    }

    /**
     * Parse simple field format: "Description | required | type:email"
     */
    protected function parseSimpleField(string $config): array
    {
        $parts = array_map('trim', explode('|', $config));

        $field = [
            'description' => $parts[0] ?? '',
            'required' => false,
            'type' => 'string',
        ];

        foreach (array_slice($parts, 1) as $part) {
            if ($part === 'required') {
                $field['required'] = true;
            } elseif (str_starts_with($part, 'type:')) {
                $field['type'] = substr($part, 5);
            } elseif (str_starts_with($part, 'prompt:')) {
                $field['prompt'] = substr($part, 7);
            }
        }

        return $field;
    }

    /**
     * Generate context-aware prompt using AI based on entity model's fields
     * AI-generated prompts are automatically in the user's language
     */
    protected function generateEntityPrompt(string $entityName, array $entityConfig, bool $isMultiple = false): string
    {
        // Check if a custom prompt is defined in the entity config
        if (!empty($entityConfig['prompt'])) {
            return $entityConfig['prompt'];
        }

        // Analyze the model's fields to determine what identifiers it supports
        $modelClass = $entityConfig['model'] ?? null;
        if (!$modelClass || !class_exists($modelClass)) {
            // Even fallback should be language-aware
            return $this->generateFallbackPrompt($entityName, $isMultiple);
        }

        // Get model's AI config to analyze available fields
        $aiConfig = method_exists($modelClass, 'getAIConfig')
            ? $modelClass::getAIConfig()
            : [];

        $fields = $aiConfig['fields'] ?? [];
        $searchFields = $entityConfig['search_fields'] ?? $aiConfig['search_fields'] ?? [];
        $displayField = $aiConfig['display_field'] ?? 'name';

        // Detect user's language from app locale
        $locale = app()->getLocale();
        $languageNames = [
            'en' => 'English',
            'ar' => 'Arabic',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
        ];
        $language = $languageNames[$locale] ?? 'English';

        // Use AI to generate an intelligent, language-aware prompt
        try {
            $aiService = app(\LaravelAIEngine\Services\AIEngineService::class);

            $systemPrompt = "You are a helpful assistant that generates concise, user-friendly prompts for data collection in the user's language. Generate a short, natural prompt (max 15 words) asking the user to provide an identifier for the entity. IMPORTANT: Generate the prompt in {$language} language.";

            $promptType = $isMultiple ? 'multiple items' : 'a single item';

            $userPrompt = "Generate a prompt in {$language} asking for {$promptType} of type '{$entityName}'.\n\n";
            $userPrompt .= "Entity: {$entityName}\n";
            $userPrompt .= "Model: " . class_basename($modelClass) . "\n";
            $userPrompt .= "Display field: {$displayField}\n";
            $userPrompt .= "Search fields: " . implode(', ', $searchFields) . "\n";
            $userPrompt .= "Available fields: " . implode(', ', array_keys($fields)) . "\n";
            $userPrompt .= "Type: " . ($isMultiple ? 'asking for multiple items to add' : 'asking for a single identifier') . "\n\n";
            $userPrompt .= "Generate a natural prompt in {$language} that asks for the most appropriate identifier(s). Be concise and friendly.";

            // Engine and model auto-selected from config inside AIRequest constructor
            $request = new \LaravelAIEngine\DTOs\AIRequest(
                prompt:       $userPrompt,
                systemPrompt: $systemPrompt,
                maxTokens:    50,
                temperature:  0.3
            );

            $response = $aiService->generateText($request);
            $generatedPrompt = trim($response);

            // Validate the generated prompt is reasonable
            if (!empty($generatedPrompt) && strlen($generatedPrompt) < 200) {
                \Illuminate\Support\Facades\Log::info('AI generated entity prompt', [
                    'entity' => $entityName,
                    'language' => $language,
                    'is_multiple' => $isMultiple,
                    'prompt' => $generatedPrompt,
                ]);
                return $generatedPrompt;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to generate AI prompt, using fallback', [
                'entity' => $entityName,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to AI-generated simple prompt if detailed generation fails
        return $this->generateFallbackPrompt($entityName, $isMultiple);
    }

    /**
     * Generate a simple fallback prompt using AI in the user's language
     */
    protected function generateFallbackPrompt(string $entityName, bool $isMultiple = false): string
    {
        try {
            $aiService = app(\LaravelAIEngine\Services\AIEngineService::class);
            $locale = app()->getLocale();
            $languageNames = [
                'en' => 'English',
                'ar' => 'Arabic',
                'es' => 'Spanish',
                'fr' => 'French',
                'de' => 'German',
            ];
            $language = $languageNames[$locale] ?? 'English';

            $promptType = $isMultiple
                ? "asking what {$entityName} items the user would like to add"
                : "asking for the {$entityName} name or identifier";

            // Engine and model auto-selected from config inside AIRequest constructor
            $request = new \LaravelAIEngine\DTOs\AIRequest(
                prompt:       "Generate a very short prompt (max 10 words) in {$language} {$promptType}. Just the prompt, nothing else.",
                systemPrompt: "Generate a concise prompt in {$language}. Output only the prompt text.",
                maxTokens:    30,
                temperature:  0.3
            );

            $response = $aiService->generateText($request);
            return trim($response);
        } catch (\Exception $e) {
            // Ultimate fallback - but this should rarely happen
            return $isMultiple
                ? "What {$entityName} would you like to add?"
                : "What is the {$entityName} name?";
        }
    }

    /**
     * Create a new builder instance
     */
    public static function make(): self
    {
        return new self();
    }
}
