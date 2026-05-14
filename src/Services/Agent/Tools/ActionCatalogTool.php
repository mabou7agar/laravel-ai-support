<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Contracts\ActionFlowHandler;

class ActionCatalogTool extends AgentTool
{
    public function __construct(protected ActionFlowHandler $actions)
    {
    }

    public function getName(): string
    {
        return 'action_catalog';
    }

    public function getDescription(): string
    {
        return 'Lists confirmed actions the application has explicitly exposed to the AI agent.';
    }

    public function getParameters(): array
    {
        return [
            'module' => ['type' => 'string', 'required' => false, 'description' => 'Optional module/action group filter.'],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $module = $parameters['module'] ?? null;

        return ActionResult::success(
            'Action catalog loaded.',
            $this->actions->catalog($context, is_scalar($module) ? (string) $module : null)
        );
    }
}
