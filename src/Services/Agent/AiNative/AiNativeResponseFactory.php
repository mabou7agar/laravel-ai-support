<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeResponseFactory
{
    public function __construct(
        private readonly AiNativeStateStore $stateStore,
        private readonly ToolRegistry $tools,
        private readonly AiNativeConfirmationPresenter $confirmationPresenter
    ) {}

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $data
     */
    public function final(UnifiedActionContext $context, array $state, string $message, array $data = []): AgentResponse
    {
        $this->stateStore->put($context, $state);

        return $this->success($context, $state, $message, $this->stateStore->redactedArray($data));
    }

    /**
     * @param array<string, mixed> $state
     */
    public function alreadyCompleted(UnifiedActionContext $context, array $state): AgentResponse
    {
        $this->stateStore->put($context, $state);

        return $this->success($context, $state, 'That action has already been completed.', [
            'already_completed' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function nonActionContext(UnifiedActionContext $context, array $state): AgentResponse
    {
        $this->stateStore->put($context, $state);

        return $this->success($context, $state, 'I noted that context. Tell me what you want to do with it next.', [
            'awaiting_action_request' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function toolCompleted(UnifiedActionContext $context, array $state, string $toolName, ActionResult $result): AgentResponse
    {
        $this->stateStore->put($context, $state);

        return $this->success(
            $context,
            $state,
            $result->message ?? str_replace('_', ' ', $toolName).' completed.',
            is_array($result->data) ? $this->stateStore->redactedArray($result->data) : []
        );
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, mixed> $requiredInputs
     * @param array<string, mixed> $data
     */
    public function needsUserInput(UnifiedActionContext $context, array $state, string $message, array $requiredInputs = [], array $data = []): AgentResponse
    {
        $response = AgentResponse::needsUserInput(
            message: $message,
            data: $this->stateStore->redactedArray(array_replace(['ai_native' => $state], $data)),
            context: $context,
            requiredInputs: $requiredInputs === [] ? null : $requiredInputs
        );
        $response->strategy = 'ai_native';
        $response->metadata = ['ai_native' => $this->stateStore->redactedState($state)];

        return $response;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     */
    public function confirmation(UnifiedActionContext $context, array $state, string $toolName, array $params, string $message, ?array $summary = null): AgentResponse
    {
        $tool = $this->tools->get($toolName);

        return $this->needsUserInput($context, $state, $this->confirmationPresenter->confirmationMessage($tool, $toolName, $params, $message, $summary), [[
            'name' => 'confirmation',
            'type' => 'select',
            'label' => 'Confirmation',
            'required' => true,
            'options' => [
                ['value' => 'confirm', 'label' => 'Confirm'],
                ['value' => 'change', 'label' => 'Change'],
            ],
        ]], [
            'pending_tool' => [
                'name' => $toolName,
                'params' => $params,
            ],
        ]);
    }

    /**
     * @param array<int, string> $validation
     * @return array<int, array<string, mixed>>
     */
    public function requiredInputsFromValidation(array $validation): array
    {
        return array_map(static fn (string $error): array => [
            'name' => $error,
            'type' => 'text',
            'required' => true,
        ], $validation);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $data
     */
    private function success(UnifiedActionContext $context, array $state, string $message, array $data): AgentResponse
    {
        $response = AgentResponse::success(
            message: $message,
            data: $data,
            context: $context
        );
        $response->strategy = 'ai_native';
        $response->metadata = ['ai_native' => $this->stateStore->redactedState($state)];

        return $response;
    }
}
