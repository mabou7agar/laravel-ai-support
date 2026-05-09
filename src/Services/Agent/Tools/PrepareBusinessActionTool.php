<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\BusinessActions\BusinessActionOrchestrator;

class PrepareBusinessActionTool extends AgentTool
{
    public function __construct(protected BusinessActionOrchestrator $actions)
    {
    }

    public function getName(): string
    {
        return 'prepare_action';
    }

    public function getDescription(): string
    {
        return 'Validates and drafts a business write action. This does not persist data.';
    }

    public function getParameters(): array
    {
        return [
            'action_id' => ['type' => 'string', 'required' => true],
            'payload' => ['type' => 'object', 'required' => true],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $result = $this->actions->prepare(
            (string) ($parameters['action_id'] ?? ''),
            (array) ($parameters['payload'] ?? []),
            $context
        );

        if ($result['success'] ?? false) {
            return ActionResult::success($result['message'] ?? 'Action prepared.', $result);
        }

        return ActionResult::needsUserInput(
            $result['message'] ?? $result['error'] ?? 'Action needs more information.',
            $result
        );
    }
}
