<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\BusinessActions\BusinessActionOrchestrator;

class ExecuteBusinessActionTool extends AgentTool
{
    public function __construct(protected BusinessActionOrchestrator $actions)
    {
    }

    public function getName(): string
    {
        return 'execute_action';
    }

    public function getDescription(): string
    {
        return 'Executes a previously prepared business action only when confirmation is present.';
    }

    public function getParameters(): array
    {
        return [
            'action_id' => ['type' => 'string', 'required' => true],
            'payload' => ['type' => 'object', 'required' => true],
            'confirmed' => ['type' => 'boolean', 'required' => true],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return $this->actions->execute(
            (string) ($parameters['action_id'] ?? ''),
            (array) ($parameters['payload'] ?? []),
            (bool) ($parameters['confirmed'] ?? false),
            $context
        );
    }
}
