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

    protected function searchOptions(string $fieldName, ?string $query = null, ?string $modelClass = null, UnifiedActionContext $context): ActionResult
    {
        return $this->useTool('search_options', [
            'field_name' => $fieldName,
            'query' => $query,
            'model_class' => $modelClass,
        ], $context);
    }

    protected function suggestValue(string $fieldName, string $fieldType = 'string', array $additionalContext = [], UnifiedActionContext $context): ActionResult
    {
        return $this->useTool('suggest_value', [
            'field_name' => $fieldName,
            'field_type' => $fieldType,
            'context' => $additionalContext,
        ], $context);
    }

    protected function explainField(string $fieldName, string $description = '', string $rules = '', UnifiedActionContext $context): ActionResult
    {
        return $this->useTool('explain_field', [
            'field_name' => $fieldName,
            'field_description' => $description,
            'validation_rules' => $rules,
        ], $context);
    }
}
