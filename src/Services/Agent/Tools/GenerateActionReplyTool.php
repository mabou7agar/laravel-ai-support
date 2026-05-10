<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionReplyGeneratorService;

class GenerateActionReplyTool extends AgentTool
{
    public function __construct(private readonly ActionReplyGeneratorService $replies)
    {
    }

    public function getName(): string
    {
        return 'generate_action_reply';
    }

    public function getDescription(): string
    {
        return 'Generates a concise user-facing reply from a structured action workflow result without exposing internal workflow fields.';
    }

    public function getParameters(): array
    {
        return [
            'workflow_result' => [
                'type' => 'object',
                'required' => true,
                'description' => 'Structured result from action_flow_guide, update_action_draft, prepare_action, or execute_action.',
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $workflowResult = $parameters['workflow_result'] ?? [];
        if (!is_array($workflowResult)) {
            return ActionResult::failure(
                'workflow_result must be an object.',
                null,
                ['agent_strategy' => 'generate_action_reply']
            );
        }

        $reply = $this->replies->generate($workflowResult);

        return ActionResult::success(
            'Action reply generated.',
            $reply,
            [
                'agent_strategy' => 'generate_action_reply',
                'provider' => $reply['metadata']['workflow_reply_provider'] ?? null,
                'generated' => $reply['metadata']['workflow_reply_generated'] ?? false,
            ]
        );
    }
}
