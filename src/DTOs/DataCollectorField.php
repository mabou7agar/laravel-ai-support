<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

/**
 * Represents a single field in a data collection form
 * 
 * Example:
 * new DataCollectorField(
 *     name: 'course_name',
 *     type: 'string',
 *     description: 'The name of the course you want to create',
 *     validation: 'required|string|min:3|max:255',
 *     required: true,
 *     examples: ['Laravel Fundamentals', 'Advanced PHP Programming']
 * )
 */
class DataCollectorField
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly string $validation = '',
        public readonly bool $required = true,
        public readonly array $examples = [],
        public readonly mixed $default = null,
        public readonly array $options = [], // For select/enum types
        public readonly ?string $prompt = null, // Custom AI prompt for this field
        public readonly ?int $order = null, // Collection order
    ) {}

    /**
     * Create from array definition
     */
    public static function fromArray(string $name, array|string $definition): self
    {
        // Simple string definition: 'string with validations'
        if (is_string($definition)) {
            return self::parseStringDefinition($name, $definition);
        }

        return new self(
            name: $name,
            type: $definition['type'] ?? 'string',
            description: $definition['description'] ?? '',
            validation: $definition['validation'] ?? '',
            required: $definition['required'] ?? true,
            examples: $definition['examples'] ?? [],
            default: $definition['default'] ?? null,
            options: $definition['options'] ?? [],
            prompt: $definition['prompt'] ?? null,
            order: $definition['order'] ?? null,
        );
    }

    /**
     * Parse string definition like 'string with validations'
     * Format: "description text | validation:rules | type:string | required:true"
     */
    protected static function parseStringDefinition(string $name, string $definition): self
    {
        $parts = array_map('trim', explode('|', $definition));
        
        $description = '';
        $validation = '';
        $type = 'string';
        $required = true;
        $examples = [];

        foreach ($parts as $index => $part) {
            // First part is always description if it doesn't contain ':'
            if ($index === 0 && !str_contains($part, ':')) {
                $description = $part;
                continue;
            }

            // Parse key:value pairs
            if (str_contains($part, ':')) {
                [$key, $value] = array_map('trim', explode(':', $part, 2));
                
                switch (strtolower($key)) {
                    case 'type':
                        $type = $value;
                        break;
                    case 'required':
                        $required = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'validation':
                        $validation = $value;
                        break;
                    case 'examples':
                        $examples = array_map('trim', explode(',', $value));
                        break;
                    case 'min':
                    case 'max':
                    case 'email':
                    case 'url':
                    case 'numeric':
                    case 'integer':
                    case 'date':
                    case 'regex':
                        // These are validation rules
                        $validation .= ($validation ? '|' : '') . $part;
                        break;
                    default:
                        // Assume it's a validation rule
                        $validation .= ($validation ? '|' : '') . $part;
                }
            } else {
                // Standalone validation rules like 'required', 'email', etc.
                $validation .= ($validation ? '|' : '') . $part;
            }
        }

        return new self(
            name: $name,
            type: $type,
            description: $description,
            validation: $validation,
            required: $required,
            examples: $examples,
        );
    }

    /**
     * Get field metadata for AI to generate prompts naturally
     * Returns structured information without hardcoded language
     */
    public function getFieldInfo(): array
    {
        $info = [
            'name' => $this->name,
            'description' => $this->description ?: $this->name,
            'type' => $this->type,
            'required' => $this->required,
        ];

        if (!empty($this->examples)) {
            $info['examples'] = $this->examples;
        }

        if (!empty($this->options)) {
            $info['options'] = $this->options;
        }

        if ($this->validation) {
            $info['validation'] = $this->validation;
        }

        return $info;
    }
    
    /**
     * Get the AI prompt for collecting this field (for backward compatibility)
     * @deprecated Use getFieldInfo() instead and let AI generate the prompt
     */
    public function getCollectionPrompt(): string
    {
        if ($this->prompt) {
            return $this->prompt;
        }

        // Return just the description - AI will handle the rest
        return $this->description ?: $this->name;
    }


    /**
     * Validate a value against this field's rules
     * Returns structured error data instead of hardcoded messages
     */
    public function validate(mixed $value): array
    {
        $errors = [];

        if ($this->required && ($value === null || $value === '')) {
            $errors[] = ['rule' => 'required', 'field' => $this->name];
            return $errors;
        }

        if (!$this->validation || ($value === null || $value === '')) {
            return $errors;
        }

        $rules = explode('|', $this->validation);

        foreach ($rules as $rule) {
            $error = $this->validateRule($value, $rule);
            if ($error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Validate a single rule
     * Returns structured error data instead of hardcoded messages
     */
    protected function validateRule(mixed $value, string $rule): ?array
    {
        if (str_starts_with($rule, 'min:')) {
            $min = (int) substr($rule, 4);
            if (is_string($value) && strlen($value) < $min) {
                return ['rule' => 'min', 'field' => $this->name, 'min' => $min, 'actual' => strlen($value)];
            }
            if (is_numeric($value) && $value < $min) {
                return ['rule' => 'min', 'field' => $this->name, 'min' => $min, 'actual' => $value];
            }
        }

        if (str_starts_with($rule, 'max:')) {
            $max = (int) substr($rule, 4);
            if (is_string($value) && strlen($value) > $max) {
                return ['rule' => 'max', 'field' => $this->name, 'max' => $max, 'actual' => strlen($value)];
            }
            if (is_numeric($value) && $value > $max) {
                return ['rule' => 'max', 'field' => $this->name, 'max' => $max, 'actual' => $value];
            }
        }

        if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['rule' => 'email', 'field' => $this->name];
        }

        if ($rule === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) {
            return ['rule' => 'url', 'field' => $this->name];
        }

        if ($rule === 'numeric' && !is_numeric($value)) {
            return ['rule' => 'numeric', 'field' => $this->name];
        }

        if ($rule === 'integer' && !filter_var($value, FILTER_VALIDATE_INT)) {
            return ['rule' => 'integer', 'field' => $this->name];
        }

        if (str_starts_with($rule, 'in:')) {
            $options = explode(',', substr($rule, 3));
            if (!in_array($value, $options)) {
                return ['rule' => 'in', 'field' => $this->name, 'options' => $options];
            }
        }

        return null;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'validation' => $this->validation,
            'required' => $this->required,
            'examples' => $this->examples,
            'default' => $this->default,
            'options' => $this->options,
        ];
    }
}
