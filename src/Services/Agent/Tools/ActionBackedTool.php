<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionOrchestrator;
use LaravelAIEngine\Services\Actions\ActionRegistry;

abstract class ActionBackedTool extends AgentTool
{
    public string $name = '';

    public string $description = '';

    public string $actionId = '';

    public function __construct(
        protected ?ActionOrchestrator $actions = null,
        protected ?ActionRegistry $registry = null
    ) {
    }

    public function getName(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        return $this->actionId !== ''
            ? $this->actionId
            : Str::snake(Str::beforeLast(class_basename($this), 'Tool'));
    }

    public function getDescription(): string
    {
        if ($this->description !== '') {
            return $this->description;
        }

        $action = $this->action();

        return (string) ($action['description'] ?? $action['label'] ?? 'Execute action ' . $this->actionId . '.');
    }

    public function getParameters(): array
    {
        $parameters = (array) ($this->action()['parameters'] ?? []);
        if ($this->requiresConfirmation()) {
            $parameters['confirmed'] ??= [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Set true only after the user explicitly confirms.',
            ];
        }

        return $parameters;
    }

    public function requiresConfirmation(): bool
    {
        return $this->orchestrator()->requiresConfirmation($this->actionId);
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        if ($this->actionId === '') {
            return ActionResult::failure('Action-backed tool is missing an action id.');
        }

        $payload = is_array($parameters['payload'] ?? null)
            ? (array) $parameters['payload']
            : Arr::except($parameters, ['confirmed', 'dry_run']);

        if ((bool) ($parameters['dry_run'] ?? false)) {
            $payload['_dry_run'] = true;
        }

        $result = $this->orchestrator()->execute(
            $this->actionId,
            $payload,
            confirmed: (bool) ($parameters['confirmed'] ?? false),
            context: $context
        );

        return $result
            ->withMetadata('action_backed_tool', true)
            ->withMetadata('tool_action_id', $this->actionId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function action(): array
    {
        return (array) ($this->orchestrator()->actionDefinition($this->actionId) ?? $this->registry()->get($this->actionId) ?? []);
    }

    protected function orchestrator(): ActionOrchestrator
    {
        return $this->actions ??= app(ActionOrchestrator::class);
    }

    protected function registry(): ActionRegistry
    {
        return $this->registry ??= app(ActionRegistry::class);
    }
}
