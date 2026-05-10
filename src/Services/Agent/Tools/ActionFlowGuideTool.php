<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\Contracts\ActionWorkflowHandler;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionFlowGuideService;

class ActionFlowGuideTool extends AgentTool
{
    public function __construct(
        private readonly ActionWorkflowHandler $actions,
        private readonly ActionFlowGuideService $flows
    ) {
    }

    public function getName(): string
    {
        return 'action_flow_guide';
    }

    public function getDescription(): string
    {
        return 'Return the declarative step-by-step flow, relation review rules, required fields, and confirmation guardrails for an action.';
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
        $result = $this->flows->guide($actionId, $this->actions->action($actionId, $context));

        if ($result['success'] ?? false) {
            return ActionResult::success(
                $result['message'] ?? 'Action flow guide loaded.',
                $result,
                ['agent_strategy' => 'action_flow_guide']
            );
        }

        return ActionResult::failure($result['message'] ?? 'Action flow guide is not available.', $result);
    }
}
