<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use Closure;

/**
 * Configuration for AI-Autonomous Data Collection
 * 
 * Instead of defining rigid fields, you define:
 * - A goal (what you want to achieve)
 * - Helper tools (how AI can look up/create entities)
 * - Output schema (the JSON structure you expect)
 * 
 * The AI handles the conversation naturally and produces the final output.
 * 
 * Example:
 * ```php
 * $config = new AutonomousCollectorConfig(
 *     goal: 'Create a sales invoice',
 *     description: 'Collect customer and product information to create an invoice',
 *     tools: [
 *         'find_customer' => [
 *             'description' => 'Search for existing customer by name or email',
 *             'handler' => fn($query) => Customer::search($query)->get(),
 *         ],
 *         'create_customer' => [
 *             'description' => 'Create a new customer',
 *             'parameters' => ['name' => 'required', 'email' => 'required|email'],
 *             'handler' => fn($data) => Customer::create($data),
 *         ],
 *         'find_product' => [
 *             'description' => 'Search for products by name',
 *             'handler' => fn($query) => Product::search($query)->get(),
 *         ],
 *     ],
 *     outputSchema: [
 *         'customer_id' => 'integer|required',
 *         'items' => [
 *             'type' => 'array',
 *             'items' => [
 *                 'product_id' => 'integer|required',
 *                 'quantity' => 'integer|required|min:1',
 *                 'price' => 'numeric|required',
 *             ],
 *         ],
 *         'notes' => 'string|nullable',
 *     ],
 *     onComplete: fn($data) => Invoice::create($data),
 * );
 * ```
 */
class AutonomousCollectorConfig
{
    public function __construct(
        /**
         * The goal/task to accomplish
         * Example: "Create a sales invoice", "Book an appointment", "Register a new user"
         */
        public readonly string $goal,
        
        /**
         * Detailed description to help AI understand the context
         */
        public readonly string $description = '',
        
        /**
         * Tools the AI can use to look up or create entities
         * Each tool has: description, parameters (optional), handler (Closure)
         */
        public readonly array $tools = [],
        
        /**
         * The expected output JSON schema
         * AI will produce data matching this structure
         */
        public readonly array $outputSchema = [],
        
        /**
         * Callback to execute when collection is complete
         */
        public readonly ?Closure $onComplete = null,
        
        /**
         * Action class to execute on completion (alternative to closure)
         */
        public readonly ?string $onCompleteAction = null,
        
        /**
         * Whether to confirm with user before completing
         */
        public readonly bool $confirmBeforeComplete = true,
        
        /**
         * Custom system prompt additions
         */
        public readonly ?string $systemPromptAddition = null,
        
        /**
         * Context data available to AI (read-only reference data)
         * Unlike tools, this is static data passed at start
         */
        public readonly array $context = [],
        
        /**
         * Maximum conversation turns before forcing completion
         */
        public readonly int $maxTurns = 20,
        
        /**
         * Unique identifier for this config
         */
        public readonly ?string $name = null,
    ) {}

    /**
     * Build the system prompt for the AI
     */
    public function buildSystemPrompt(): string
    {
        $prompt = "You are an intelligent assistant helping to: {$this->goal}\n\n";
        
        if ($this->description) {
            $prompt .= "Context: {$this->description}\n\n";
        }
        
        // Describe available tools
        if (!empty($this->tools)) {
            $prompt .= "## Available Tools\n";
            $prompt .= "You can use these tools to look up or create data:\n\n";
            
            foreach ($this->tools as $toolName => $tool) {
                $prompt .= "### {$toolName}\n";
                $prompt .= $tool['description'] ?? "Tool: {$toolName}";
                $prompt .= "\n";
                
                if (!empty($tool['parameters'])) {
                    $prompt .= "Parameters: " . json_encode($tool['parameters'], JSON_PRETTY_PRINT) . "\n";
                }
                $prompt .= "\n";
            }
        }
        
        // Describe expected output
        if (!empty($this->outputSchema)) {
            $prompt .= "## Expected Output\n";
            $prompt .= "When you have collected all necessary information, produce a JSON object matching this schema:\n";
            $prompt .= "```json\n" . json_encode($this->outputSchema, JSON_PRETTY_PRINT) . "\n```\n\n";
        }
        
        // Instructions
        $prompt .= "## Instructions\n";
        $prompt .= "1. Have a natural conversation to collect the required information\n";
        $prompt .= "2. Use tools to search for existing entities before creating new ones\n";
        $prompt .= "3. Parse user input intelligently - understand context and intent\n";
        $prompt .= "4. When you have all required data, output the final JSON wrapped in ```json``` markers\n";
        $prompt .= "5. If user input is ambiguous, ask for clarification naturally\n";
        $prompt .= "6. Handle multiple items in a single message (e.g., '2 laptops and 3 mice')\n\n";
        
        if ($this->systemPromptAddition) {
            $prompt .= $this->systemPromptAddition . "\n";
        }
        
        return $prompt;
    }

    /**
     * Get tool definitions for function calling
     */
    public function getToolDefinitions(): array
    {
        $definitions = [];
        
        foreach ($this->tools as $toolName => $tool) {
            $definition = [
                'name' => $toolName,
                'description' => $tool['description'] ?? "Execute {$toolName}",
            ];
            
            if (!empty($tool['parameters'])) {
                $definition['parameters'] = $this->convertToJsonSchema($tool['parameters']);
            }
            
            $definitions[] = $definition;
        }
        
        return $definitions;
    }

    /**
     * Execute a tool by name
     */
    public function executeTool(string $toolName, array $arguments = []): mixed
    {
        if (!isset($this->tools[$toolName])) {
            throw new \InvalidArgumentException("Tool '{$toolName}' not found");
        }
        
        $tool = $this->tools[$toolName];
        $handler = $tool['handler'] ?? null;
        
        if (!$handler instanceof Closure) {
            throw new \InvalidArgumentException("Tool '{$toolName}' has no valid handler");
        }
        
        // If single argument, pass directly; otherwise pass as array
        if (count($arguments) === 1 && isset($arguments[array_key_first($arguments)])) {
            return $handler($arguments[array_key_first($arguments)]);
        }
        
        return $handler($arguments);
    }

    /**
     * Validate output against schema
     */
    public function validateOutput(array $data): array
    {
        $errors = [];
        $this->validateAgainstSchema($data, $this->outputSchema, '', $errors);
        return $errors;
    }

    /**
     * Execute completion callback
     */
    public function executeOnComplete(array $data): mixed
    {
        if ($this->onComplete) {
            return ($this->onComplete)($data);
        }
        
        if ($this->onCompleteAction && class_exists($this->onCompleteAction)) {
            $action = app($this->onCompleteAction);
            return $action->execute($data);
        }
        
        return $data;
    }

    /**
     * Convert simple parameter definitions to JSON Schema
     */
    protected function convertToJsonSchema(array $parameters): array
    {
        $properties = [];
        $required = [];
        
        foreach ($parameters as $name => $rules) {
            $property = ['type' => 'string'];
            
            if (is_string($rules)) {
                $ruleList = explode('|', $rules);
                
                foreach ($ruleList as $rule) {
                    if ($rule === 'required') {
                        $required[] = $name;
                    } elseif ($rule === 'integer') {
                        $property['type'] = 'integer';
                    } elseif ($rule === 'numeric' || $rule === 'float') {
                        $property['type'] = 'number';
                    } elseif ($rule === 'boolean') {
                        $property['type'] = 'boolean';
                    } elseif ($rule === 'array') {
                        $property['type'] = 'array';
                    }
                }
            } elseif (is_array($rules)) {
                $property = $rules;
                if (!empty($rules['required'])) {
                    $required[] = $name;
                }
            }
            
            $properties[$name] = $property;
        }
        
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Recursively validate data against schema
     */
    protected function validateAgainstSchema(array $data, array $schema, string $path, array &$errors): void
    {
        foreach ($schema as $key => $rules) {
            $currentPath = $path ? "{$path}.{$key}" : $key;
            $value = $data[$key] ?? null;
            
            if (is_array($rules) && isset($rules['type']) && $rules['type'] === 'array') {
                // Array type validation
                if (!is_array($value)) {
                    $errors[] = "{$currentPath} must be an array";
                    continue;
                }
                
                if (isset($rules['items'])) {
                    foreach ($value as $index => $item) {
                        if (is_array($item) && is_array($rules['items'])) {
                            $this->validateAgainstSchema($item, $rules['items'], "{$currentPath}[{$index}]", $errors);
                        }
                    }
                }
            } elseif (is_string($rules)) {
                // Simple validation rules
                $ruleList = explode('|', $rules);
                
                foreach ($ruleList as $rule) {
                    if ($rule === 'required' && ($value === null || $value === '')) {
                        $errors[] = "{$currentPath} is required";
                    } elseif ($rule === 'integer' && $value !== null && !is_int($value)) {
                        $errors[] = "{$currentPath} must be an integer";
                    } elseif ($rule === 'numeric' && $value !== null && !is_numeric($value)) {
                        $errors[] = "{$currentPath} must be numeric";
                    }
                }
            }
        }
    }

    /**
     * Create from array definition
     */
    public static function fromArray(array $data): self
    {
        return new self(
            goal: $data['goal'] ?? '',
            description: $data['description'] ?? '',
            tools: $data['tools'] ?? [],
            outputSchema: $data['output_schema'] ?? $data['outputSchema'] ?? [],
            onComplete: $data['on_complete'] ?? $data['onComplete'] ?? null,
            onCompleteAction: $data['on_complete_action'] ?? $data['onCompleteAction'] ?? null,
            confirmBeforeComplete: $data['confirm_before_complete'] ?? $data['confirmBeforeComplete'] ?? true,
            systemPromptAddition: $data['system_prompt_addition'] ?? $data['systemPromptAddition'] ?? null,
            context: $data['context'] ?? [],
            maxTurns: $data['max_turns'] ?? $data['maxTurns'] ?? 20,
            name: $data['name'] ?? null,
        );
    }
}
