<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\GoalAgentService;

class RunSubAgentTool extends AgentTool
{
    public function getName(): string
    {
        return 'run_sub_agent';
    }

    public function getDescription(): string
    {
        return 'Delegate a target to one or more configured sub-agents.';
    }

    public function getParameters(): array
    {
        return [
            'target' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Target or objective for the delegated sub-agent plan.',
            ],
            'sub_agents' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Optional explicit sub-agent IDs or task definitions.',
            ],
            'stop_on_failure' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Stop when a critical sub-agent fails.',
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $target = trim((string) ($parameters['target'] ?? $parameters['input'] ?? ''));
        if ($target === '') {
            return ActionResult::needsUserInput(
                'A target is required to run a sub-agent.',
                metadata: ['required_inputs' => ['target']]
            );
        }

        $options = [
            'agent_goal' => true,
            'target' => $target,
        ];

        if (is_array($parameters['sub_agents'] ?? null)) {
            $options['sub_agents'] = $parameters['sub_agents'];
        }

        if (array_key_exists('stop_on_failure', $parameters)) {
            $options['stop_on_failure'] = (bool) $parameters['stop_on_failure'];
        }

        $response = app(GoalAgentService::class)->execute($target, $context, $options);
        $data = $response->toArray();

        if ($response->needsUserInput) {
            return ActionResult::needsUserInput($response->message, $data, [
                'agent_strategy' => 'run_sub_agent',
                'needs_user_input' => true,
                'required_inputs' => $response->requiredInputs ?? [],
            ]);
        }

        if (!$response->success) {
            return ActionResult::failure($response->message, $data, [
                'agent_strategy' => 'run_sub_agent',
            ]);
        }

        return ActionResult::success($response->message, $data, [
            'agent_strategy' => 'run_sub_agent',
        ]);
    }
}
