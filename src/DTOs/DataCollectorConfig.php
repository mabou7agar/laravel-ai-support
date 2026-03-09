<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use Closure;
use Illuminate\Support\Str;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

/**
 * Configuration for a Data Collector Chat session
 * 
 * Example usage:
 * 
 * $config = new DataCollectorConfig(
 *     name: 'course_creator',
 *     title: 'Create a New Course',
 *     description: 'I will help you create a new course by collecting the necessary information.',
 *     fields: [
 *         'name' => 'The course name | required | min:3 | max:255',
 *         'description' => [
 *             'type' => 'text',
 *             'description' => 'A detailed description of what students will learn',
 *             'validation' => 'required|min:50|max:5000',
 *             'examples' => ['Learn Laravel from scratch...', 'Master PHP programming...']
 *         ],
 *         'duration' => 'Course duration in hours | required | numeric | min:1',
 *         'level' => [
 *             'type' => 'select',
 *             'description' => 'The difficulty level',
 *             'options' => ['beginner', 'intermediate', 'advanced'],
 *             'required' => true
 *         ],
 *     ],
 *     onComplete: function(array $data) {
 *         return Course::create($data);
 *     },
 *     confirmBeforeComplete: true,
 *     allowEnhancement: true
 * );
 */
class DataCollectorConfig
{
    /** @var DataCollectorField[] */
    protected array $parsedFields = [];
    
    /** @var string Internal identifier (auto-generated UUID if not provided) */
    public readonly string $name;

    public function __construct(
        ?string $name = null,                        // Internal identifier (auto-generated UUID if not provided)
        public readonly string $title = '',          // Display title for the user
        public readonly string $description = '',
        public readonly array $fields = [],
        public readonly array $initialData = [],     // Pre-filled field values
        public readonly ?Closure $onComplete = null,
        public readonly ?string $onCompleteAction = null, // Alternative: action class name
        public readonly bool $confirmBeforeComplete = true,
        public readonly bool $allowEnhancement = true, // Allow user to modify after summary
        public readonly bool $allowSkipOptional = true, // Allow skipping optional fields
        public readonly ?string $successMessage = null,
        public readonly ?string $cancelMessage = null,
        public readonly array $metadata = [], // Additional metadata
        public readonly ?string $systemPrompt = null, // Custom system prompt
        public readonly ?string $actionSummary = null, // Description of what will happen on completion
        public readonly ?Closure $actionSummaryGenerator = null, // Dynamic action summary based on data
        public readonly ?string $actionSummaryPrompt = null, // AI prompt to generate action summary
        public readonly ?array $actionSummaryPromptConfig = null, // Config for AI-generated summary (engine, model, etc.)
        public readonly ?string $summaryPrompt = null, // AI prompt to generate data summary
        public readonly ?array $summaryPromptConfig = null, // Config for AI-generated data summary
        public readonly ?array $outputSchema = null, // Schema for AI-generated structured output
        public readonly ?string $outputPrompt = null, // Custom prompt for generating output
        public readonly ?array $outputConfig = null, // Config for output generation (engine, model, etc.)
        public readonly ?string $locale = null, // Force AI responses in specific language (e.g., 'en', 'ar', 'fr')
        public readonly bool $detectLocale = false, // Auto-detect language from user's first message
    ) {
        // Auto-generate UUID for name if not provided
        $this->name = $name ?? 'dc_' . Str::uuid()->toString();
        
        $this->parseFields();
    }

    /**
     * Parse field definitions into DataCollectorField objects
     */
    protected function parseFields(): void
    {
        $order = 0;
        foreach ($this->fields as $name => $definition) {
            [$resolvedName, $resolvedDefinition] = $this->normalizeFieldDefinition($name, $definition, $order);

            $field = DataCollectorField::fromArray($resolvedName, $resolvedDefinition);
            
            // Set order if not specified
            if ($field->order === null) {
                $field = new DataCollectorField(
                    name: $field->name,
                    type: $field->type,
                    description: $field->description,
                    validation: $field->validation,
                    required: $field->required,
                    examples: $field->examples,
                    default: $field->default,
                    options: $field->options,
                    prompt: $field->prompt,
                    order: $order++,
                );
            }
            
            $this->parsedFields[$field->name] = $field;
        }

        // Sort by order
        uasort($this->parsedFields, fn($a, $b) => ($a->order ?? 0) <=> ($b->order ?? 0));
    }

    /**
     * Normalize incoming field definitions so API clients can send either:
     * - associative fields map: {"customer_name": "..."}
     * - indexed field list: [{"name":"customer_name","description":"..."}]
     * - indexed single-key objects: [{"customer_name":"..."}]
     *
     * @return array{0:string,1:array|string}
     */
    protected function normalizeFieldDefinition(string|int $name, mixed $definition, int $index): array
    {
        $resolvedDefinition = $this->normalizeFieldValue($definition);

        if (is_string($name) && trim($name) !== '') {
            return [trim($name), $resolvedDefinition];
        }

        if (is_array($resolvedDefinition)) {
            if (isset($resolvedDefinition['name']) && is_scalar($resolvedDefinition['name'])) {
                $resolvedName = trim((string) $resolvedDefinition['name']);
                if ($resolvedName !== '') {
                    unset($resolvedDefinition['name']);

                    return [$resolvedName, $resolvedDefinition];
                }
            }

            if (count($resolvedDefinition) === 1) {
                $singleKey = array_key_first($resolvedDefinition);
                $singleValue = $singleKey !== null ? $resolvedDefinition[$singleKey] : null;
                $reservedKeys = [
                    'name',
                    'type',
                    'description',
                    'validation',
                    'required',
                    'examples',
                    'default',
                    'options',
                    'prompt',
                    'order',
                    'field_name',
                    'field',
                    'key',
                ];

                if (
                    is_string($singleKey)
                    && trim($singleKey) !== ''
                    && !in_array(strtolower($singleKey), $reservedKeys, true)
                    && (is_array($singleValue) || is_string($singleValue))
                ) {
                    return [trim($singleKey), $this->normalizeFieldValue($singleValue)];
                }
            }

            foreach (['field_name', 'field', 'key'] as $altNameKey) {
                if (!isset($resolvedDefinition[$altNameKey]) || !is_scalar($resolvedDefinition[$altNameKey])) {
                    continue;
                }

                $resolvedName = trim((string) $resolvedDefinition[$altNameKey]);
                if ($resolvedName !== '') {
                    unset($resolvedDefinition[$altNameKey]);

                    return [$resolvedName, $resolvedDefinition];
                }
            }
        }

        return ['field_' . ($index + 1), $resolvedDefinition];
    }

    /**
     * Ensure field value is compatible with DataCollectorField::fromArray().
     */
    protected function normalizeFieldValue(mixed $definition): array|string
    {
        if (is_array($definition) || is_string($definition)) {
            return $definition;
        }

        if (is_scalar($definition) || $definition === null) {
            return (string) $definition;
        }

        $encoded = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * Get all parsed fields
     * 
     * @return DataCollectorField[]
     */
    public function getFields(): array
    {
        return $this->parsedFields;
    }

    /**
     * Get a specific field
     */
    public function getField(string $name): ?DataCollectorField
    {
        return $this->parsedFields[$name] ?? null;
    }

    /**
     * Get required fields only
     * 
     * @return DataCollectorField[]
     */
    public function getRequiredFields(): array
    {
        return array_filter($this->parsedFields, fn($field) => $field->required);
    }

    /**
     * Get optional fields only
     * 
     * @return DataCollectorField[]
     */
    public function getOptionalFields(): array
    {
        return array_filter($this->parsedFields, fn($field) => !$field->required);
    }

    /**
     * Get field names in order
     */
    public function getFieldNames(): array
    {
        return array_keys($this->parsedFields);
    }

    /**
     * Get the next field to collect after the given field
     */
    public function getNextField(string $currentField): ?DataCollectorField
    {
        $found = false;
        foreach ($this->parsedFields as $name => $field) {
            if ($found) {
                return $field;
            }
            if ($name === $currentField) {
                $found = true;
            }
        }
        return null;
    }

    /**
     * Get the first field
     */
    public function getFirstField(): ?DataCollectorField
    {
        return reset($this->parsedFields) ?: null;
    }

    /**
     * Validate all collected data
     */
    public function validateAll(array $data): array
    {
        $errors = [];

        foreach ($this->parsedFields as $name => $field) {
            $value = $data[$name] ?? null;
            $fieldErrors = $field->validate($value);
            
            if (!empty($fieldErrors)) {
                $errors[$name] = $fieldErrors;
            }
        }

        return $errors;
    }

    /**
     * Check if all required fields are collected
     */
    public function isComplete(array $data): bool
    {
        foreach ($this->getRequiredFields() as $name => $field) {
            if (!isset($data[$name]) || $data[$name] === '' || $data[$name] === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get missing required fields
     * 
     * @return DataCollectorField[]
     */
    public function getMissingFields(array $data): array
    {
        $missing = [];
        
        foreach ($this->getRequiredFields() as $name => $field) {
            if (!isset($data[$name]) || $data[$name] === '' || $data[$name] === null) {
                $missing[$name] = $field;
            }
        }

        return $missing;
    }

    /**
     * Get all uncollected fields (both required and optional)
     * 
     * @return DataCollectorField[]
     */
    public function getUncollectedFields(array $data): array
    {
        $uncollected = [];
        
        foreach ($this->parsedFields as $name => $field) {
            if (!isset($data[$name]) || $data[$name] === '' || $data[$name] === null) {
                $uncollected[$name] = $field;
            }
        }

        return $uncollected;
    }

    /**
     * Execute the completion callback
     */
    public function executeOnComplete(array $data): mixed
    {
        if ($this->onComplete) {
            return ($this->onComplete)($data);
        }

        if ($this->onCompleteAction && class_exists($this->onCompleteAction)) {
            $action = app($this->onCompleteAction);
            if (method_exists($action, 'execute')) {
                return $action->execute($data);
            }
            if (method_exists($action, 'handle')) {
                return $action->handle($data);
            }
            if (is_callable($action)) {
                return $action($data);
            }
        }

        return $data;
    }

    /**
     * Generate the system prompt for the AI
     */
    public function getSystemPrompt(): string
    {
        if ($this->systemPrompt) {
            return $this->systemPrompt;
        }

        $descriptionLine = $this->description !== ''
            ? $this->translateRuntime(
                'data_collector.system_prompt.description_line',
                'DESCRIPTION: :description',
                ['description' => $this->description]
            )
            : '';
        $templatePrompt = '';
        if ($this->localeResources() !== null) {
            $templatePrompt = $this->localeResources()->renderPromptTemplate(
                'data_collector/system_prompt',
                [
                    'title' => $this->title,
                    'description_line' => $descriptionLine,
                    'fields_block' => $this->buildFieldsPromptBlock(),
                    'allow_enhancement_line' => $this->allowEnhancement
                        ? $this->translateRuntime('data_collector.system_prompt.allow_enhancement_line')
                        : '',
                    'allow_skip_optional_line' => $this->allowSkipOptional
                        ? $this->translateRuntime('data_collector.system_prompt.allow_skip_optional_line')
                        : '',
                    'language_block' => $this->buildLanguageSystemPromptBlock(),
                ],
                $this->locale
            );
        }

        if (trim($templatePrompt) !== '') {
            return trim($templatePrompt);
        }

        return $this->buildSystemPromptFallback($descriptionLine);
    }

    protected function buildFieldsPromptBlock(): string
    {
        $requiredLabel = $this->translateRuntime('data_collector.system_prompt.required_label', '(required)');
        $optionalLabel = $this->translateRuntime('data_collector.system_prompt.optional_label', '(optional)');
        $examplePrefix = $this->translateRuntime('data_collector.system_prompt.example_prefix', 'e.g.');

        $lines = [];
        foreach ($this->parsedFields as $name => $field) {
            $required = $field->required ? $requiredLabel : $optionalLabel;
            $line = "- {$name} {$required}: {$field->description}";

            if (!empty($field->examples)) {
                $line .= " ({$examplePrefix}, " . implode(', ', $field->examples) . ")";
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    protected function buildLanguageSystemPromptBlock(): string
    {
        $lines = [];
        if ($this->locale) {
            $lines[] = $this->translateRuntime(
                'data_collector.language_requirement.force_locale',
                '',
                ['language' => $this->getLocaleName()]
            );
            $lines[] = $this->translateRuntime(
                'data_collector.language_requirement.json_values',
                '',
                ['language' => $this->getLocaleName()]
            );
        } elseif ($this->detectLocale) {
            $lines[] = $this->translateRuntime('data_collector.language_requirement.match_user', '');
            $lines[] = $this->translateRuntime(
                'data_collector.language_requirement.json_values',
                '',
                ['language' => $this->getLocaleName()]
            );
        }

        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

        return implode("\n", $lines);
    }

    protected function buildSystemPromptFallback(string $descriptionLine): string
    {
        $prompt = "You are a helpful assistant collecting information from the user.\n\n";
        $prompt .= "TASK: {$this->title}\n";
        if ($descriptionLine !== '') {
            $prompt .= $descriptionLine . "\n";
        }

        $prompt .= "\nFIELDS TO COLLECT:\n";
        $prompt .= $this->buildFieldsPromptBlock() . "\n\n";
        $prompt .= "INSTRUCTIONS:\n";
        $prompt .= "1. Ask for one field at a time.\n";
        $prompt .= "2. Use FIELD_COLLECTED markers exactly like FIELD_COLLECTED:field_name=value.\n";
        $prompt .= "3. Ask for confirmation before completion.\n";
        if ($this->allowEnhancement) {
            $prompt .= "4. Allow user modifications before final confirmation.\n";
        }
        if ($this->allowSkipOptional) {
            $prompt .= "5. Allow skipping optional fields.\n";
        }
        $prompt .= "\nComplete token: DATA_COLLECTION_COMPLETE\n";
        $prompt .= "Cancel token: DATA_COLLECTION_CANCELLED\n";

        $languageBlock = $this->buildLanguageSystemPromptBlock();
        if ($languageBlock !== '') {
            $prompt .= "\nLANGUAGE:\n{$languageBlock}\n";
        }

        return $prompt;
    }

    /**
     * Get human-readable locale name
     */
    public function getLocaleName(): string
    {
        if ($this->localeResources() !== null) {
            return $this->localeResources()->languageName($this->locale);
        }

        $fallbackLocale = $this->locale ?? app()->getLocale();

        return strtoupper((string) $fallbackLocale);
    }

    /**
     * Generate a summary of collected data
     * Note: This is a simple data structure. AI will format it naturally in user's language.
     */
    public function generateSummary(array $data): string
    {
        $summary = '';

        foreach ($this->parsedFields as $name => $field) {
            $value = $data[$name] ?? '';
            
            // Skip empty optional fields
            if (empty($value) && !$field->required) {
                continue;
            }
            
            // Use field description (already in user's language)
            $label = $field->description ?: ucwords(str_replace('_', ' ', $name));
            
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            
            // Simple key-value format - AI will present this naturally
            if (!empty($value)) {
                $summary .= "{$label}: {$value}\n";
            }
        }

        return trim($summary);
    }

    /**
     * Generate the action summary describing what will happen
     */
    public function generateActionSummary(array $data): string
    {
        // Use dynamic generator if provided
        if ($this->actionSummaryGenerator) {
            return ($this->actionSummaryGenerator)($data);
        }

        // Use static action summary if provided
        if ($this->actionSummary) {
            // Replace placeholders like {name}, {description} with actual values
            $summary = $this->actionSummary;
            foreach ($data as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $summary = str_replace('{' . $key . '}', (string) $value, $summary);
                }
            }
            return $summary;
        }

        if ($this->localeResources() !== null) {
            $translated = $this->localeResources()->translation(
                'ai-engine::runtime.data_collector.default_action_summary',
                ['title' => $this->title],
                $this->locale
            );
            if ($translated !== '') {
                return $translated;
            }
        }

        // Default action summary
        return "This will complete the '{$this->title}' process with the information you provided.";
    }

    /**
     * Generate the full confirmation message with data summary and action summary
     */
    public function generateConfirmationMessage(array $data): string
    {
        $message = $this->generateSummary($data);
        $message .= "\n---\n\n";

        $whatWillHappen = $this->translateRuntime('data_collector.confirmation.what_will_happen', 'What will happen:');
        $pleaseConfirm = $this->translateRuntime('data_collector.confirmation.please_confirm', 'Please confirm:');

        $confirmLexicon = $this->localeLexicon('intent.confirm', ['yes', 'confirm']);
        $rejectLexicon = $this->localeLexicon('intent.reject', ['no', 'change']);
        $modifyLexicon = $this->localeLexicon('intent.modify', ['change']);
        $cancelLexicon = $this->localeLexicon('intent.cancel', ['cancel']);

        $yes = $confirmLexicon[0] ?? 'yes';
        $confirm = $confirmLexicon[1] ?? $yes;
        $no = $rejectLexicon[0] ?? 'no';
        $change = $modifyLexicon[0] ?? ($rejectLexicon[1] ?? 'change');
        $cancel = $cancelLexicon[0] ?? 'cancel';

        $proceedLine = $this->translateRuntime(
            'data_collector.confirmation.proceed_line',
            "Say ':yes' or ':confirm' to proceed",
            ['yes' => $yes, 'confirm' => $confirm]
        );
        $modifyLine = $this->translateRuntime(
            'data_collector.confirmation.modify_line',
            "Say ':no' or ':change' to modify any information",
            ['no' => $no, 'change' => $change]
        );
        $cancelLine = $this->translateRuntime(
            'data_collector.confirmation.cancel_line',
            "Say ':cancel' to abort the process",
            ['cancel' => $cancel]
        );

        $message .= "## {$whatWillHappen}\n\n";
        $message .= $this->generateActionSummary($data);
        $message .= "\n\n---\n\n";
        $message .= "**{$pleaseConfirm}**\n";
        $message .= "- {$proceedLine}\n";
        $message .= "- {$modifyLine}\n";
        $message .= "- {$cancelLine}\n";
        
        return $message;
    }

    protected function translateRuntime(string $key, string $fallback = '', array $replace = []): string
    {
        $fallbackReplace = [];
        foreach ($replace as $name => $value) {
            $fallbackReplace[':' . $name] = (string) $value;
        }

        if ($this->localeResources() === null) {
            return strtr($fallback, $fallbackReplace);
        }

        $translated = $this->localeResources()->translation(
            "ai-engine::runtime.{$key}",
            $replace,
            $this->locale
        );

        if ($translated === '') {
            $translated = $this->localeResources()->translation(
                "ai-engine::runtime.{$key}",
                $replace,
                $this->fallbackLocale()
            );
        }

        if ($translated !== '') {
            return $translated;
        }

        return $fallback !== '' ? strtr($fallback, $fallbackReplace) : '';
    }

    protected function fallbackLocale(): ?string
    {
        $fallback = config('ai-engine.localization.fallback_locale')
            ?: config('app.fallback_locale')
            ?: app()->getLocale();

        return is_string($fallback) && trim($fallback) !== '' ? $fallback : null;
    }

    protected function localeLexicon(string $key, array $default = []): array
    {
        if ($this->localeResources() === null) {
            return $default;
        }

        $values = $this->localeResources()->lexicon($key, $this->locale, $default);

        return $values !== [] ? $values : $default;
    }

    protected function localeResources(): ?LocaleResourceService
    {
        try {
            return app(LocaleResourceService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Create from array
     */
    public static function fromArray(array $config): self
    {
        return new self(
            name: $config['name'] ?? null,  // Will auto-generate UUID if null
            title: $config['title'] ?? '',
            description: $config['description'] ?? '',
            fields: $config['fields'] ?? [],
            initialData: $config['initialData'] ?? $config['initial_data'] ?? [],
            onComplete: $config['onComplete'] ?? null,
            onCompleteAction: $config['onCompleteAction'] ?? null,
            confirmBeforeComplete: $config['confirmBeforeComplete'] ?? true,
            allowEnhancement: $config['allowEnhancement'] ?? true,
            allowSkipOptional: $config['allowSkipOptional'] ?? true,
            successMessage: $config['successMessage'] ?? null,
            cancelMessage: $config['cancelMessage'] ?? null,
            metadata: $config['metadata'] ?? [],
            systemPrompt: $config['systemPrompt'] ?? null,
            actionSummary: $config['actionSummary'] ?? null,
            actionSummaryGenerator: $config['actionSummaryGenerator'] ?? null,
            actionSummaryPrompt: $config['actionSummaryPrompt'] ?? null,
            actionSummaryPromptConfig: $config['actionSummaryPromptConfig'] ?? null,
            summaryPrompt: $config['summaryPrompt'] ?? null,
            summaryPromptConfig: $config['summaryPromptConfig'] ?? null,
            outputSchema: $config['outputSchema'] ?? null,
            outputPrompt: $config['outputPrompt'] ?? null,
            outputConfig: $config['outputConfig'] ?? null,
            locale: $config['locale'] ?? null,
            detectLocale: $config['detectLocale'] ?? false,
        );
    }

    /**
     * Convert to array (includes all configuration for cache/database persistence)
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'fields' => array_map(fn($f) => $f->toArray(), $this->parsedFields),
            'initialData' => $this->initialData,
            'confirmBeforeComplete' => $this->confirmBeforeComplete,
            'allowEnhancement' => $this->allowEnhancement,
            'allowSkipOptional' => $this->allowSkipOptional,
            'successMessage' => $this->successMessage,
            'cancelMessage' => $this->cancelMessage,
            'metadata' => $this->metadata,
            'systemPrompt' => $this->systemPrompt,
            'actionSummary' => $this->actionSummary,
            'actionSummaryPrompt' => $this->actionSummaryPrompt,
            'actionSummaryPromptConfig' => $this->actionSummaryPromptConfig,
            'summaryPrompt' => $this->summaryPrompt,
            'summaryPromptConfig' => $this->summaryPromptConfig,
            'outputSchema' => $this->outputSchema,
            'outputPrompt' => $this->outputPrompt,
            'outputConfig' => $this->outputConfig,
            'locale' => $this->locale,
            'detectLocale' => $this->detectLocale,
            // Note: onComplete and actionSummaryGenerator closures cannot be serialized
            // They must be re-registered when loading from cache/database
            'onCompleteAction' => $this->onCompleteAction,
        ];
    }
}
