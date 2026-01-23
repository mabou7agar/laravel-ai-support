<?php

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class SuggestValueTool extends AgentTool
{
    public function __construct(
        protected AIEngineService $ai
    ) {}

    public function getName(): string
    {
        return 'suggest_value';
    }

    public function getDescription(): string
    {
        return 'Suggest an appropriate value for a field based on context';
    }

    public function getParameters(): array
    {
        return [
            'field_name' => [
                'type' => 'string',
                'description' => 'Name of the field to suggest value for',
                'required' => true,
            ],
            'field_type' => [
                'type' => 'string',
                'description' => 'Type of field (string, number, date, etc.)',
                'required' => false,
            ],
            'context' => [
                'type' => 'object',
                'description' => 'Additional context data',
                'required' => false,
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $fieldName = $parameters['field_name'];
        $fieldType = $parameters['field_type'] ?? 'string';
        $additionalContext = $parameters['context'] ?? [];

        $suggestion = $this->generateSuggestion($fieldName, $fieldType, $additionalContext, $context);

        if (!$suggestion) {
            return ActionResult::failure(
                error: "Could not generate suggestion for field '{$fieldName}'",
                data: ['field' => $fieldName]
            );
        }

        return ActionResult::success(
            message: "Suggested value for '{$fieldName}': {$suggestion}",
            data: [
                'field' => $fieldName,
                'suggestion' => $suggestion,
                'type' => $fieldType,
            ]
        );
    }

    protected function generateSuggestion(
        string $fieldName,
        string $fieldType,
        array $additionalContext,
        UnifiedActionContext $context
    ): ?string {
        $collectedData = $context->workflowState;

        $prompt = "Suggest an appropriate value for the field '{$fieldName}'.\n\n";
        $prompt .= "Field Type: {$fieldType}\n\n";

        if (!empty($collectedData)) {
            $prompt .= "Already collected data:\n";
            foreach ($collectedData as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $prompt .= "- {$key}: {$value}\n";
                }
            }
            $prompt .= "\n";
        }

        if (!empty($additionalContext)) {
            $prompt .= "Additional context:\n";
            foreach ($additionalContext as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $prompt .= "- {$key}: {$value}\n";
                }
            }
            $prompt .= "\n";
        }

        $prompt .= "Based on the context, suggest a single appropriate value for '{$fieldName}'.\n";
        $prompt .= "Respond with ONLY the suggested value, nothing else.";

        try {
            $request = new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 100,
                temperature: 0.7
            );

            $response = $this->ai->generate($request);

            return trim($response->getContent());
        } catch (\Exception $e) {
            return null;
        }
    }
}
