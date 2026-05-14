<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use LaravelAIEngine\Contracts\ActionFlowHandler;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class DefaultActionFlowHandler implements ActionFlowHandler
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly ActionOrchestrator $actions
    ) {
    }

    public function action(string $actionId, ?UnifiedActionContext $context = null): ?array
    {
        return $this->registry->get($actionId);
    }

    public function catalog(?UnifiedActionContext $context = null, ?string $module = null): array
    {
        return $this->actions->catalog($module);
    }

    public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
    {
        return $this->actions->prepare($actionId, $payload, $context);
    }

    public function execute(string $actionId, array $payload, bool $confirmed, ?UnifiedActionContext $context = null): ActionResult|array
    {
        return $this->actions->execute($actionId, $payload, $confirmed, $context);
    }

    public function suggest(array $contextData = [], ?UnifiedActionContext $context = null): array
    {
        return $this->actions->suggest($contextData, $context);
    }
}
