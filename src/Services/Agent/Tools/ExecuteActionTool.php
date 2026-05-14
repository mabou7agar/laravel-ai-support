<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionDraftService;
use LaravelAIEngine\Services\Actions\ActionReplyGeneratorService;

class ExecuteActionTool extends AgentTool
{
    public function __construct(
        protected ActionDraftService $drafts,
        protected ActionReplyGeneratorService $replies
    )
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
            $reply = $this->replies->generate($result);

            return ActionResult::success(
                $reply['text'],
                $result['data'] ?? $result,
                array_merge($result['metadata'] ?? [], ['agent_strategy' => 'action_execute'], $reply['metadata'])
            );
        }

        if (($result['needs_user_input'] ?? false) || ($result['requires_confirmation'] ?? false)) {
            $reply = $this->replies->generate($result);

            return ActionResult::needsUserInput(
                $reply['text'],
                $result,
                ['agent_strategy' => 'action_needs_input'] + $reply['metadata']
            );
        }

        return ActionResult::failure($result['error'] ?? $result['message'] ?? 'Action failed.', $result);
    }
}
