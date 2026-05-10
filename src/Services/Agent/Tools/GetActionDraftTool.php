<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionDraftService;

class GetActionDraftTool extends AgentTool
{
    public function __construct(private readonly ActionDraftService $drafts)
    {
    }

    public function getName(): string
    {
        return 'get_action_draft';
    }

    public function getDescription(): string
    {
        return 'Read the current session action draft so the assistant can continue, correct, summarize, or confirm it.';
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

        return ActionResult::success('Action draft loaded.', [
            'action_id' => $actionId,
            'current_payload' => $this->drafts->get($context, $actionId),
        ]);
    }
}
