<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionDraftService;

class ExecuteActionTool extends AgentTool
{
    public function __construct(protected ActionDraftService $drafts)
    {
    }

    public function getName(): string
    {
        return 'execute_action';
    }

    public function getDescription(): string
    {
        return 'Executes a previously prepared action only when confirmation is present.';
    }

    public function getParameters(): array
    {
        return [
            'action_id' => ['type' => 'string', 'required' => true],
            'payload' => ['type' => 'object', 'required' => false],
            'confirmed' => ['type' => 'boolean', 'required' => true],
            'dry_run' => ['type' => 'boolean', 'required' => false],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $payload = is_array($parameters['payload'] ?? null) ? (array) $parameters['payload'] : null;
        if ((bool) ($parameters['dry_run'] ?? false)) {
            $payload = array_merge($payload ?? [], ['_dry_run' => true]);
        }

        $result = $this->drafts->execute(
            $context,
            (string) ($parameters['action_id'] ?? ''),
            (bool) ($parameters['confirmed'] ?? false),
            $payload
        );

        if ($result['success'] ?? false) {
            return ActionResult::success(
                $result['message'] ?? 'Action executed.',
                $result['data'] ?? $result,
                array_merge($result['metadata'] ?? [], ['agent_strategy' => 'action_execute'])
            );
        }

        if (($result['needs_user_input'] ?? false) || ($result['requires_confirmation'] ?? false)) {
            return ActionResult::needsUserInput(
                $result['message'] ?? $result['error'] ?? 'Action needs user input.',
                $result,
                ['agent_strategy' => 'action_needs_input']
            );
        }

        return ActionResult::failure($result['error'] ?? $result['message'] ?? 'Action failed.', $result);
    }
}
