<?php

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class ExplainFieldTool extends AgentTool
{
    public function __construct(
        protected AIEngineService $ai
    ) {}

    public function getName(): string
    {
        return 'explain_field';
    }

    public function getDescription(): string
    {
        return 'Explain what a field is for and provide guidance on what to enter';
    }

    public function getParameters(): array
    {
        return [
            'field_name' => [
                'type' => 'string',
                'description' => 'Name of the field to explain',
                'required' => true,
            ],
            'field_description' => [
                'type' => 'string',
                'description' => 'Existing field description',
                'required' => false,
            ],
            'validation_rules' => [
                'type' => 'string',
                'description' => 'Validation rules for the field',
                'required' => false,
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $fieldName = $parameters['field_name'];
        $fieldDescription = $parameters['field_description'] ?? '';
        $validationRules = $parameters['validation_rules'] ?? '';

        $explanation = $this->generateExplanation($fieldName, $fieldDescription, $validationRules, $context);

        if (!$explanation) {
            return ActionResult::failure(
                error: "Could not generate explanation for field '{$fieldName}'",
                data: ['field' => $fieldName]
            );
        }

        return ActionResult::success(
            message: $explanation,
            data: [
                'field' => $fieldName,
                'explanation' => $explanation,
            ]
        );
    }

    protected function generateExplanation(
        string $fieldName,
        string $fieldDescription,
        string $validationRules,
        UnifiedActionContext $context
    ): ?string {
        $prompt = "Explain the field '{$fieldName}' to a user in a friendly, helpful way.\n\n";

        if (!empty($fieldDescription)) {
            $prompt .= "Field Description: {$fieldDescription}\n\n";
        }

        if (!empty($validationRules)) {
            $prompt .= "Validation Rules: {$validationRules}\n\n";
        }

        $prompt .= "Provide a clear, concise explanation that includes:\n";
        $prompt .= "1. What this field is for\n";
        $prompt .= "2. What kind of information should be entered\n";
        $prompt .= "3. Any requirements or constraints\n";
        $prompt .= "4. An example if helpful\n\n";
        $prompt .= "Keep it friendly and conversational, 2-3 sentences max.";

        try {
            $request = new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0.7
            );

            $response = $this->ai->generate($request);
            
            return trim($response->content);
        } catch (\Exception $e) {
            return null;
        }
    }
}
