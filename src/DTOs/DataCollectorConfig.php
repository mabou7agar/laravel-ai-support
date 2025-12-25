<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use Closure;
use Illuminate\Support\Str;

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
            $field = DataCollectorField::fromArray($name, $definition);
            
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
            
            $this->parsedFields[$name] = $field;
        }

        // Sort by order
        uasort($this->parsedFields, fn($a, $b) => ($a->order ?? 0) <=> ($b->order ?? 0));
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

        $prompt = "You are a helpful assistant collecting information from the user.\n\n";
        $prompt .= "TASK: {$this->title}\n";
        
        if ($this->description) {
            $prompt .= "DESCRIPTION: {$this->description}\n";
        }

        $prompt .= "\nFIELDS TO COLLECT:\n";
        
        foreach ($this->parsedFields as $name => $field) {
            $required = $field->required ? '(required)' : '(optional)';
            $prompt .= "- {$name} {$required}: {$field->description}";
            
            if (!empty($field->examples)) {
                $prompt .= " (e.g., " . implode(', ', $field->examples) . ")";
            }
            
            $prompt .= "\n";
        }

        $prompt .= "\nINSTRUCTIONS:\n";
        $prompt .= "1. Ask for ONE field at a time in a conversational manner\n";
        $prompt .= "2. Validate user input and ask for corrections if needed\n";
        $prompt .= "3. Be helpful and provide examples when the user seems unsure\n";
        $prompt .= "4. After collecting all required fields, provide a summary\n";
        $prompt .= "5. Ask for confirmation before completing\n";
        
        if ($this->allowEnhancement) {
            $prompt .= "6. Allow the user to modify any field before final confirmation\n";
        }

        if ($this->allowSkipOptional) {
            $prompt .= "7. Allow skipping optional fields if the user wants to\n";
        }

        $prompt .= "\nRESPONSE FORMAT:\n";
        $prompt .= "CRITICAL: When you extract ANY field value from the user's message, you MUST include a marker for EACH field:\n";
        $prompt .= "FIELD_COLLECTED:field_name=value\n\n";
        $prompt .= "Example - if user says 'The course is called Laravel Basics, it's 10 hours long for beginners':\n";
        $prompt .= "FIELD_COLLECTED:name=Laravel Basics\n";
        $prompt .= "FIELD_COLLECTED:duration=10\n";
        $prompt .= "FIELD_COLLECTED:level=beginner\n\n";
        $prompt .= "IMPORTANT: Extract ALL values mentioned in the user's message, not just one at a time.\n";
        $prompt .= "Place these markers at the END of your response, after your conversational text.\n\n";
        $prompt .= "When all fields are collected and user confirms, respond with:\n";
        $prompt .= "DATA_COLLECTION_COMPLETE\n";
        $prompt .= "If user wants to cancel, respond with:\n";
        $prompt .= "DATA_COLLECTION_CANCELLED\n";

        // Add language instructions
        if ($this->locale) {
            $prompt .= "\nLANGUAGE:\n";
            $prompt .= "You MUST respond in {$this->getLocaleName()} language.\n";
            $prompt .= "All your conversational responses, questions, and summaries must be in {$this->getLocaleName()}.\n";
            $prompt .= "The FIELD_COLLECTED markers should keep the field names in English, but values can be in the user's language.\n";
        } elseif ($this->detectLocale) {
            $prompt .= "\nLANGUAGE:\n";
            $prompt .= "Detect the language from the user's first message and respond in that same language.\n";
            $prompt .= "Continue using that language throughout the conversation.\n";
            $prompt .= "The FIELD_COLLECTED markers should keep the field names in English, but values can be in the user's language.\n";
        }

        return $prompt;
    }

    /**
     * Get human-readable locale name
     */
    public function getLocaleName(): string
    {
        $localeNames = [
            'en' => 'English',
            'ar' => 'Arabic',
            'fr' => 'French',
            'es' => 'Spanish',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'hi' => 'Hindi',
            'tr' => 'Turkish',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'he' => 'Hebrew',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'uk' => 'Ukrainian',
            'cs' => 'Czech',
            'ro' => 'Romanian',
            'hu' => 'Hungarian',
            'el' => 'Greek',
            'bg' => 'Bulgarian',
        ];

        return $localeNames[$this->locale] ?? $this->locale ?? 'English';
    }

    /**
     * Generate a summary of collected data
     */
    public function generateSummary(array $data): string
    {
        $summary = "## Summary: {$this->title}\n\n";

        foreach ($this->parsedFields as $name => $field) {
            $value = $data[$name] ?? '(not provided)';
            $label = ucwords(str_replace('_', ' ', $name));
            $required = $field->required ? '' : ' (optional)';
            
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            
            $summary .= "**{$label}**{$required}: {$value}\n";
        }

        return $summary;
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

        // Default action summary based on title (with locale support)
        if ($this->locale === 'ar') {
            return "سيتم إكمال عملية '{$this->title}' بالمعلومات التي قدمتها.";
        }
        return "This will complete the '{$this->title}' process with the information you provided.";
    }

    /**
     * Generate the full confirmation message with data summary and action summary
     */
    public function generateConfirmationMessage(array $data): string
    {
        $message = $this->generateSummary($data);
        $message .= "\n---\n\n";
        
        if ($this->locale === 'ar') {
            $message .= "## ما سيحدث:\n\n";
            $message .= $this->generateActionSummary($data);
            $message .= "\n\n---\n\n";
            $message .= "**يرجى التأكيد:**\n";
            $message .= "- قل **'نعم'** أو **'تأكيد'** للمتابعة\n";
            $message .= "- قل **'لا'** أو **'تغيير'** لتعديل أي معلومات\n";
            $message .= "- قل **'إلغاء'** لإلغاء العملية\n";
        } else {
            $message .= "## What will happen:\n\n";
            $message .= $this->generateActionSummary($data);
            $message .= "\n\n---\n\n";
            $message .= "**Please confirm:**\n";
            $message .= "- Say **'yes'** or **'confirm'** to proceed\n";
            $message .= "- Say **'no'** or **'change'** to modify any information\n";
            $message .= "- Say **'cancel'** to abort the process\n";
        }
        
        return $message;
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
