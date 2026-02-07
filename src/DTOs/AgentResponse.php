<?php

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\DTOs\AIResponse;

class AgentResponse
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?array $data = null,
        public ?string $strategy = null,
        public ?UnifiedActionContext $context = null,
        public bool $needsUserInput = false,
        public ?array $actions = null,
        public ?array $metadata = null,
        public bool $isComplete = false,
        public ?string $nextStep = null,
        public ?array $requiredInputs = null
    ) {
    }

    public static function success(
        string $message,
        ?array $data = null,
        ?UnifiedActionContext $context = null
    ): self {
        return new self(
            success: true,
            message: $message,
            data: $data,
            context: $context,
            isComplete: true
        );
    }

    public static function failure(
        string $message,
        ?array $data = null,
        ?UnifiedActionContext $context = null
    ): self {
        return new self(
            success: false,
            message: $message,
            data: $data,
            context: $context,
            isComplete: true
        );
    }

    public static function needsUserInput(
        string $message,
        ?array $data = null,
        ?array $actions = null,
        ?UnifiedActionContext $context = null,
        ?string $nextStep = null,
        ?array $requiredInputs = null
    ): self {
        return new self(
            success: true,
            message: $message,
            data: $data,
            context: $context,
            needsUserInput: true,
            actions: $actions,
            isComplete: false,
            nextStep: $nextStep,
            requiredInputs: $requiredInputs
        );
    }

    public static function conversational(
        string $message,
        ?UnifiedActionContext $context = null,
        ?array $metadata = null
    ): self {
        return new self(
            success: true,
            message: $message,
            strategy: 'conversational',
            context: $context,
            metadata: $metadata,
            isComplete: true
        );
    }

    public static function fromActionResult(
        \LaravelAIEngine\DTOs\ActionResult $result,
        ?UnifiedActionContext $context = null
    ): self {
        return new self(
            success: $result->success,
            message: $result->message ?? ($result->success ? 'Action completed' : 'Action failed'),
            data: $result->data,
            strategy: 'quick_action',
            context: $context,
            metadata: $result->metadata,
            isComplete: true
        );
    }

    public static function fromDataCollectorState(
        $state,
        ?UnifiedActionContext $context = null
    ): self {
        $isComplete = in_array($state->status ?? '', ['completed', 'cancelled']);

        return new self(
            success: true,
            message: $state->message ?? 'Data collection in progress',
            data: [
                'state' => $state,
                'progress' => $state->progress ?? 0,
                'collected_fields' => $state->collectedFields ?? [],
                'remaining_fields' => $state->remainingFields ?? [],
            ],
            strategy: 'guided_flow',
            context: $context,
            needsUserInput: !$isComplete,
            actions: $state->actions ?? null,
            isComplete: $isComplete
        );
    }

    public static function fromDataCollectorResponse(
        $response,
        ?UnifiedActionContext $context = null
    ): self {
        return self::fromDataCollectorState($response->state, $context);
    }

    public function toAIResponse(): AIResponse
    {
        return new AIResponse(
            content: $this->message,
            engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
            model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o'),
            metadata: array_merge($this->metadata ?? [], [
                'strategy' => $this->strategy,
                'needs_user_input' => $this->needsUserInput,
                'is_complete' => $this->isComplete,
                'next_step' => $this->nextStep,
            ]),
            actions: $this->actions
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'strategy' => $this->strategy,
            'needs_user_input' => $this->needsUserInput,
            'actions' => $this->actions,
            'metadata' => $this->metadata,
            'is_complete' => $this->isComplete,
            'next_step' => $this->nextStep,
            'required_inputs' => $this->requiredInputs,
        ];
    }

    /**
     * Create a response that needs specific user inputs
     * 
     * @param string $message The message to display
     * @param array $inputs Array of input definitions, each with:
     *   - name: Field name (required)
     *   - type: Input type (text, number, select, confirm, email, etc.)
     *   - label: Display label
     *   - required: Whether field is required
     *   - options: For select type, array of options [{value, label}]
     *   - default: Default value
     *   - placeholder: Placeholder text
     *   - validation: Validation rules
     * @param array|null $data Additional data
     * @param array|null $actions Quick action buttons
     * @return self
     * 
     * Example:
     * ```php
     * AgentResponse::needsInputs(
     *     message: "Please provide product details:",
     *     inputs: [
     *         ['name' => 'name', 'type' => 'text', 'label' => 'Product Name', 'required' => true],
     *         ['name' => 'price', 'type' => 'number', 'label' => 'Price', 'required' => true],
     *         ['name' => 'category', 'type' => 'select', 'label' => 'Category', 'options' => [
     *             ['value' => 'electronics', 'label' => 'Electronics'],
     *             ['value' => 'clothing', 'label' => 'Clothing'],
     *         ]],
     *     ]
     * );
     * ```
     */
    public static function needsInputs(
        string $message,
        array $inputs,
        ?array $data = null,
        ?array $actions = null,
        ?UnifiedActionContext $context = null,
        ?string $nextStep = null
    ): self {
        return new self(
            success: true,
            message: $message,
            data: $data,
            context: $context,
            needsUserInput: true,
            actions: $actions,
            isComplete: false,
            nextStep: $nextStep,
            requiredInputs: $inputs
        );
    }

    /**
     * Create a confirmation response with yes/no options
     */
    public static function needsConfirmation(
        string $message,
        ?array $data = null,
        ?UnifiedActionContext $context = null,
        ?string $nextStep = null
    ): self {
        return self::needsInputs(
            message: $message,
            inputs: [
                [
                    'name' => 'confirmed',
                    'type' => 'confirm',
                    'label' => 'Confirm',
                    'required' => true,
                ],
            ],
            data: $data,
            actions: [
                ['type' => 'button', 'label' => 'Yes', 'value' => 'yes'],
                ['type' => 'button', 'label' => 'No', 'value' => 'no'],
            ],
            context: $context,
            nextStep: $nextStep
        );
    }

    /**
     * Create a selection response with multiple options
     */
    public static function needsSelection(
        string $message,
        array $options,
        ?array $data = null,
        ?UnifiedActionContext $context = null,
        ?string $nextStep = null
    ): self {
        return self::needsInputs(
            message: $message,
            inputs: [
                [
                    'name' => 'selection',
                    'type' => 'select',
                    'label' => 'Select an option',
                    'required' => true,
                    'options' => $options,
                ],
            ],
            data: $data,
            actions: array_map(fn($opt) => [
                'type' => 'button',
                'label' => $opt['label'] ?? $opt['value'],
                'value' => $opt['value'],
            ], $options),
            context: $context,
            nextStep: $nextStep
        );
    }
}
