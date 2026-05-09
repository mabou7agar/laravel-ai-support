<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\BusinessActions\BusinessActionOrchestrator;

class BusinessActionCatalogTool extends AgentTool
{
    public function __construct(protected BusinessActionOrchestrator $actions)
    {
    }

    public function getName(): string
    {
        return 'action_catalog';
    }

    public function getDescription(): string
    {
        return 'Lists confirmed business actions the application has explicitly exposed to the AI agent.';
    }

    public function getParameters(): array
    {
        return [
            'module' => ['type' => 'string', 'required' => false, 'description' => 'Optional module/action group filter.'],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success(
            'Business action catalog loaded.',
            $this->actions->catalog($parameters['module'] ?? null)
        );
    }
}
