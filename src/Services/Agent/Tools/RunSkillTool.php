<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;

class RunSkillTool extends AgentTool
{
    private mixed $aiNativeRuntime;

    public function __construct(
        mixed $skills = null,
        mixed $memory = null,
        mixed $ai = null,
        mixed $policy = null,
        mixed $tools = null,
        mixed $intentSignals = null,
        mixed $aiNativeRuntime = null
    ) {
        $this->aiNativeRuntime = $skills instanceof AiNativeRuntime ? $skills : $aiNativeRuntime;
    }

    public function getName(): string
    {
        return 'run_skill';
    }

    public function getDescription(): string
    {
        return 'Run a matched skill using the tools declared by that skill. The AI chooses the next tool or asks for missing data.';
    }

    public function getParameters(): array
    {
        return [
            'skill_id' => ['type' => 'string', 'required' => true],
            'message' => ['type' => 'string', 'required' => false],
            'reset' => ['type' => 'boolean', 'required' => false],
            'fresh_start' => ['type' => 'boolean', 'required' => false],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return $this->executeAiNative($parameters, $context);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function executeAiNative(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $skillId = (string) ($parameters['skill_id'] ?? '');
        $message = (string) ($parameters['message'] ?? $context->metadata['latest_user_message'] ?? '');

        $state = is_array($context->metadata['ai_native'] ?? null)
            ? $context->metadata['ai_native']
            : [];

        if (!empty($parameters['reset'])) {
            $state = [];
        }

        $state['selected_skill_id'] = $skillId;
        $state['runtime_scope'] = 'skill';
        $state['fresh_start'] = !empty($parameters['fresh_start']);
        $context->metadata['ai_native'] = $state;

        $response = $this->aiNativeRuntime()->process($message, $context, [
            'skill_id' => $skillId,
            'runtime_scope' => 'skill',
            'fresh_start' => !empty($parameters['fresh_start']),
        ]);

        return $this->fromAgentResponse($response);
    }

    private function aiNativeRuntime(): AiNativeRuntime
    {
        if (!$this->aiNativeRuntime instanceof AiNativeRuntime) {
            $this->aiNativeRuntime = app(AiNativeRuntime::class);
        }

        return $this->aiNativeRuntime;
    }

    private function fromAgentResponse(AgentResponse $response): ActionResult
    {
        $metadata = array_merge($response->metadata ?? [], [
            'strategy' => $response->strategy ?? 'ai_native',
            'needs_user_input' => $response->needsUserInput,
        ]);

        if (is_array($response->requiredInputs)) {
            $metadata['required_inputs'] = $response->requiredInputs;
        }

        if ($response->needsUserInput) {
            return ActionResult::needsUserInput($response->message, $response->data, $metadata);
        }

        if (!$response->success) {
            return ActionResult::failure($response->message, $response->data, $metadata);
        }

        return ActionResult::success($response->message, $response->data, $metadata);
    }
}
