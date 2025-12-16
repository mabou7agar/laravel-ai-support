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
     * Get the AI prompt for collecting this field
     */
    public function getCollectionPrompt(): string
    {
        if ($this->prompt) {
            return $this->prompt;
        }

        $prompt = "Please provide the {$this->name}";
        
        if ($this->description) {
            $prompt .= ": {$this->description}";
        }

        if (!empty($this->examples)) {
            $prompt .= "\n\nExamples: " . implode(', ', $this->examples);
        }

        if (!empty($this->options)) {
            $prompt .= "\n\nAvailable options: " . implode(', ', $this->options);
        }

        if ($this->validation) {
            $prompt .= $this->getValidationHints();
        }

        return $prompt;
    }

    /**
     * Get human-readable validation hints
     */
    protected function getValidationHints(): string
    {
        $hints = [];
        $rules = explode('|', $this->validation);

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'min:')) {
                $min = substr($rule, 4);
                $hints[] = "minimum {$min} characters";
            } elseif (str_starts_with($rule, 'max:')) {
                $max = substr($rule, 4);
                $hints[] = "maximum {$max} characters";
            } elseif ($rule === 'email') {
                $hints[] = "must be a valid email address";
            } elseif ($rule === 'url') {
                $hints[] = "must be a valid URL";
            } elseif ($rule === 'numeric') {
                $hints[] = "must be a number";
            } elseif ($rule === 'integer') {
                $hints[] = "must be a whole number";
            } elseif (str_starts_with($rule, 'between:')) {
                $range = substr($rule, 8);
                $hints[] = "must be between {$range}";
            } elseif (str_starts_with($rule, 'in:')) {
                $options = substr($rule, 3);
                $hints[] = "must be one of: {$options}";
            }
        }

        if (!empty($hints)) {
            return "\n\nRequirements: " . implode(', ', $hints);
        }

        return '';
    }

    /**
     * Validate a value against this field's rules
     */
    public function validate(mixed $value): array
    {
        $errors = [];

        if ($this->required && ($value === null || $value === '')) {
            $errors[] = "The {$this->name} field is required.";
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
     */
    protected function validateRule(mixed $value, string $rule): ?string
    {
        if (str_starts_with($rule, 'min:')) {
            $min = (int) substr($rule, 4);
            if (is_string($value) && strlen($value) < $min) {
                return "The {$this->name} must be at least {$min} characters.";
            }
            if (is_numeric($value) && $value < $min) {
                return "The {$this->name} must be at least {$min}.";
            }
        }

        if (str_starts_with($rule, 'max:')) {
            $max = (int) substr($rule, 4);
            if (is_string($value) && strlen($value) > $max) {
                return "The {$this->name} must not exceed {$max} characters.";
            }
            if (is_numeric($value) && $value > $max) {
                return "The {$this->name} must not exceed {$max}.";
            }
        }

        if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "The {$this->name} must be a valid email address.";
        }

        if ($rule === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) {
            return "The {$this->name} must be a valid URL.";
        }

        if ($rule === 'numeric' && !is_numeric($value)) {
            return "The {$this->name} must be a number.";
        }

        if ($rule === 'integer' && !filter_var($value, FILTER_VALIDATE_INT)) {
            return "The {$this->name} must be a whole number.";
        }

        if (str_starts_with($rule, 'in:')) {
            $options = explode(',', substr($rule, 3));
            if (!in_array($value, $options)) {
                return "The {$this->name} must be one of: " . implode(', ', $options);
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
