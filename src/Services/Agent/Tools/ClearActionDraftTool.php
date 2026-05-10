<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionDraftService;

class ClearActionDraftTool extends AgentTool
{
    public function __construct(private readonly ActionDraftService $drafts)
    {
    }

    public function getName(): string
    {
        return 'clear_action_draft';
    }

    public function getDescription(): string
    {
        return 'Clear the current session action draft when the user cancels, restarts, or abandons a workflow.';
    }

    public function getParameters(): array
    {
        return [
            'action_id' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $actionId = (string) ($parameters['action_id'] ?? '');
        $this->drafts->forget($context, $actionId);

        return ActionResult::success('Action draft cleared.', [
            'action_id' => $actionId,
        ], ['agent_strategy' => 'action_cancelled']);
    }
}
