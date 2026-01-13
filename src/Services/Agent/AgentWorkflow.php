<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\WorkflowStep;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

abstract class AgentWorkflow
{
    protected array $steps = [];
    protected AIEngineService $ai;
    protected ?ToolRegistry $tools = null;

    public function __construct(AIEngineService $ai, ?ToolRegistry $tools = null)
    {
        $this->ai = $ai;
        $this->tools = $tools;
        $this->steps = $this->defineSteps();
        
        Log::channel('ai-engine')->info('Workflow initialized', [
            'class' => get_class($this),
            'steps_count' => count($this->steps),
            'steps' => array_map(fn($s) => $s->getName(), $this->steps),
        ]);
    }

    abstract public function defineSteps(): array;

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getStep(string $name): ?WorkflowStep
    {
        foreach ($this->steps as $step) {
            if ($step->getName() === $name) {
                return $step;
            }
        }
        return null;
    }

    public function getFirstStep(): ?WorkflowStep
    {
        return $this->steps[0] ?? null;
    }

    protected function extractWithAI(string $message, array $fields, array $context = []): array
    {
        $prompt = $this->buildExtractionPrompt($message, $fields, $context);
        
        $request = new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from('openai'),
            model: EntityEnum::from('gpt-4o-mini'),
            maxTokens: 500,
            temperature: 0
        );

        $response = $this->ai->generate($request);
        
        return $this->parseExtractionResponse($response->content, $fields);
    }

    protected function buildExtractionPrompt(string $message, array $fields, array $context): string
    {
        $prompt = "Extract structured data from the user's message.\n\n";
        $prompt .= "User Message: \"{$message}\"\n\n";
        
        if (!empty($context)) {
            $prompt .= "Context:\n";
            foreach ($context as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $prompt .= "- {$key}: {$value}\n";
                }
            }
            $prompt .= "\n";
        }
        
        $prompt .= "Fields to extract:\n";
        foreach ($fields as $field => $rules) {
            $isRequired = str_contains($rules, 'required');
            $type = $this->extractType($rules);
            $prompt .= "- {$field} ({$type})" . ($isRequired ? ' [REQUIRED]' : ' [OPTIONAL]') . "\n";
        }
        
        $prompt .= "\nRespond in JSON format:\n";
        $prompt .= "{\n";
        $prompt .= "  \"extracted\": { \"field_name\": \"value\", ... },\n";
        $prompt .= "  \"missing\": [\"field1\", \"field2\"],\n";
        $prompt .= "  \"confidence\": 0.0-1.0\n";
        $prompt .= "}";
        
        return $prompt;
    }

    protected function extractType(string $rules): string
    {
        if (str_contains($rules, 'array')) return 'array';
        if (str_contains($rules, 'numeric')) return 'number';
        if (str_contains($rules, 'boolean')) return 'boolean';
        return 'string';
    }

    protected function parseExtractionResponse(string $content, array $fields): array
    {
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            
            if ($json) {
                $extracted = $json['extracted'] ?? [];
                $missing = $json['missing'] ?? [];
                
                $requiredFields = array_keys(array_filter($fields, fn($rules) => str_contains($rules, 'required')));
                $missingRequired = array_intersect($requiredFields, $missing);
                
                return [
                    'data' => $extracted,
                    'missing_fields' => $missing,
                    'complete' => empty($missingRequired),
                    'confidence' => $json['confidence'] ?? 0.5,
                ];
            }
        }
        
        return [
            'data' => [],
            'missing_fields' => array_keys($fields),
            'complete' => false,
            'confidence' => 0.0,
        ];
    }

    protected function askAI(string $question, array $context = []): string
    {
        $prompt = $question;
        
        if (!empty($context)) {
            $prompt .= "\n\nContext:\n";
            foreach ($context as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $prompt .= "- {$key}: {$value}\n";
                }
            }
        }
        
        $request = new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from('openai'),
            model: EntityEnum::from('gpt-4o-mini'),
            maxTokens: 200,
            temperature: 0.7
        );

        $response = $this->ai->generate($request);
        
        return trim($response->content);
    }

    public function getName(): string
    {
        return class_basename(static::class);
    }

    public function getDescription(): string
    {
        return "Workflow: " . $this->getName();
    }

    protected function useTool(string $toolName, array $parameters, UnifiedActionContext $context): ActionResult
    {
        if (!$this->tools) {
            return ActionResult::failure(
                error: 'Tool registry not available',
                metadata: ['tool' => $toolName]
            );
        }

        $tool = $this->tools->get($toolName);

        if (!$tool) {
            return ActionResult::failure(
                error: "Tool '{$toolName}' not found",
                metadata: ['tool' => $toolName]
            );
        }

        $errors = $tool->validate($parameters);
        if (!empty($errors)) {
            return ActionResult::failure(
                error: 'Tool parameter validation failed',
                data: ['errors' => $errors],
                metadata: ['tool' => $toolName]
            );
        }

        try {
            return $tool->execute($parameters, $context);
        } catch (\Exception $e) {
            return ActionResult::failure(
                error: "Tool execution failed: {$e->getMessage()}",
                metadata: [
                    'tool' => $toolName,
                    'exception' => get_class($e),
                ]
            );
        }
    }

    protected function validateField(string $fieldName, $value, string $rules, UnifiedActionContext $context): ActionResult
    {
        return $this->useTool('validate_field', [
            'field_name' => $fieldName,
            'value' => $value,
            'rules' => $rules,
        ], $context);
    }

    protected function searchOptions(string $fieldName, UnifiedActionContext $context, ?string $query = null, ?string $modelClass = null): ActionResult
    {
        return $this->useTool('search_options', [
            'field_name' => $fieldName,
            'query' => $query,
            'model_class' => $modelClass,
        ], $context);
    }

    protected function suggestValue(string $fieldName, UnifiedActionContext $context, string $fieldType = 'string', array $additionalContext = []): ActionResult
    {
        return $this->useTool('suggest_value', [
            'field_name' => $fieldName,
            'field_type' => $fieldType,
            'context' => $additionalContext,
        ], $context);
    }

    protected function explainField(string $fieldName, UnifiedActionContext $context, string $description = '', string $rules = ''): ActionResult
    {
        return $this->useTool('explain_field', [
            'field_name' => $fieldName,
            'field_description' => $description,
            'validation_rules' => $rules,
        ], $context);
    }

    /**
     * Check if user wants to cancel/abort the workflow
     * This is called automatically before each step execution
     */
    public function checkForCancellation(UnifiedActionContext $context): bool
    {
        $lastMessage = end($context->conversationHistory);
        if (!$lastMessage || $lastMessage['role'] !== 'user') {
            return false;
        }

        $content = strtolower(trim($lastMessage['content'] ?? ''));
        
        // Check for cancel keywords
        $cancelKeywords = ['cancel', 'abort', 'stop', 'quit', 'exit', 'nevermind', 'never mind'];
        
        foreach ($cancelKeywords as $keyword) {
            if ($content === $keyword || str_starts_with($content, $keyword . ' ')) {
                Log::channel('ai-engine')->info('User requested workflow cancellation', [
                    'workflow' => $this->getName(),
                    'session_id' => $context->sessionId,
                    'message' => $content,
                ]);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Handle workflow cancellation
     */
    public function handleCancellation(UnifiedActionContext $context): ActionResult
    {
        Log::channel('ai-engine')->info('Workflow cancelled by user', [
            'workflow' => $this->getName(),
            'session_id' => $context->sessionId,
        ]);

        // Clean up workflow state
        $this->cleanupAfterCompletion($context);

        return ActionResult::failure(
            error: 'Workflow cancelled by user',
            metadata: ['cancelled' => true]
        );
    }

    /**
     * Generic entity resolution helper
     * 
     * Resolves entities using GenericEntityResolver and model's aiConfig.
     * Auto-detects single vs multiple entities and delegates accordingly.
     * 
     * @param string $configField Field name in aiConfig (e.g., 'customer_id', 'items')
     * @param string $dataField Field name in collected data (e.g., 'customer_identifier', 'products')
     * @param array $aiConfig The model's aiConfig array
     * @param UnifiedActionContext $context Workflow context
     * @return ActionResult
     */
    protected function resolveEntityFromConfig(
        string $configField,
        string $dataField,
        array $aiConfig,
        UnifiedActionContext $context
    ): ActionResult {
        // Get entity config from aiConfig
        $entityConfig = $aiConfig['fields'][$configField] ?? null;
        
        if (!$entityConfig) {
            return ActionResult::failure(error: "Field '{$configField}' not configured in aiConfig");
        }
        
        // Get GenericEntityResolver
        $resolver = app(\LaravelAIEngine\Services\GenericEntityResolver::class);
        
        // Check if we're in the middle of creation
        $creationStep = $context->get("{$configField}_creation_step");
        
        if ($creationStep) {
            // Continue with stored identifier
            $identifier = $context->get("{$configField}_identifier", '');
        } else {
            // First time - get from collected data
            $data = method_exists($this, 'getCollectedData') 
                ? $this->getCollectedData($context) 
                : [];
            $identifier = $data[$dataField] ?? '';
        }

        // Auto-detect if single or multiple entities
        $isMultiple = $this->isMultipleEntities($entityConfig, $identifier);
        
        // Use GenericEntityResolver
        if ($isMultiple) {
            // Multiple entities (e.g., products)
            $items = is_array($identifier) ? $identifier : [$identifier];
            $result = $resolver->resolveEntities($configField, $entityConfig, $items, $context);
        } else {
            // Single entity (e.g., customer)
            $result = $resolver->resolveEntity($configField, $entityConfig, $identifier, $context);
        }
        
        // Store resolved ID(s) in context
        if ($result->success && isset($result->data[$configField])) {
            $context->set($configField, $result->data[$configField]);
        }
        
        return $result;
    }
    
    /**
     * Auto-detect if dealing with single or multiple entities
     */
    private function isMultipleEntities(array $config, $data): bool
    {
        // Check config type
        if (isset($config['type'])) {
            return $config['type'] === 'entities';
        }
        
        // Auto-detect from data
        if (is_array($data)) {
            // If it's an array with numeric keys, it's multiple
            if (array_keys($data) === range(0, count($data) - 1)) {
                return true;
            }
            // If it's an associative array with 'name' key, it's single
            if (isset($data['name'])) {
                return false;
            }
            // If it has multiple items, it's multiple
            return count($data) > 1;
        }
        
        // String or single value = single entity
        return false;
    }

    /**
     * Clean up workflow state after completion
     * This is called automatically when workflow reaches 'complete' step
     */
    public function cleanupAfterCompletion(UnifiedActionContext $context): void
    {
        Log::channel('ai-engine')->info('Cleaning up workflow state after completion', [
            'workflow' => $this->getName(),
            'session_id' => $context->sessionId,
        ]);

        // Clear workflow-specific state
        $context->workflowState = [];
        $context->currentWorkflow = null;
        $context->currentStep = null;
        
        // Keep conversation history for context
        // Keep user ID and session ID for tracking
        
        Log::channel('ai-engine')->info('Workflow state cleaned', [
            'workflow' => $this->getName(),
            'session_id' => $context->sessionId,
        ]);
    }
}
