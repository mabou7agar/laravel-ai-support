<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Contracts\ActionFlowHandler;

class SuggestActionTool extends AgentTool
{
    public function __construct(protected ActionFlowHandler $actions)
    {
    }

    public function getName(): string
    {
        return 'suggest_action';
    }

    public function getDescription(): string
    {
        return 'Suggests possible next actions from provided data. It does not persist data.';
    }

    public function getParameters(): array
    {
        return [
            'context' => ['type' => 'object', 'required' => false, 'description' => 'Records or user intent used for suggestions.'],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success(
            'Action suggestions generated.',
            $this->actions->suggest((array) ($parameters['context'] ?? []), $context)
        );
    }
}
