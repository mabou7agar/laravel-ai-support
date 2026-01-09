<?php

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use Illuminate\Support\Facades\Validator;

class ValidateFieldTool extends AgentTool
{
    public function getName(): string
    {
        return 'validate_field';
    }

    public function getDescription(): string
    {
        return 'Validate a field value against validation rules';
    }

    public function getParameters(): array
    {
        return [
            'field_name' => [
                'type' => 'string',
                'description' => 'Name of the field to validate',
                'required' => true,
            ],
            'value' => [
                'type' => 'mixed',
                'description' => 'Value to validate',
                'required' => true,
            ],
            'rules' => [
                'type' => 'string',
                'description' => 'Laravel validation rules (e.g., "required|email|max:255")',
                'required' => true,
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $fieldName = $parameters['field_name'];
        $value = $parameters['value'];
        $rules = $parameters['rules'];

        $validator = Validator::make(
            [$fieldName => $value],
            [$fieldName => $rules]
        );

        if ($validator->fails()) {
            return ActionResult::failure(
                error: 'Validation failed',
                data: [
                    'field' => $fieldName,
                    'value' => $value,
                    'errors' => $validator->errors()->get($fieldName),
                ]
            );
        }

        return ActionResult::success(
            message: "Field '{$fieldName}' is valid",
            data: [
                'field' => $fieldName,
                'value' => $value,
                'valid' => true,
            ]
        );
    }
}
