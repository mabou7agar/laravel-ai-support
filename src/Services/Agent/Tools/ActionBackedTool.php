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
        $parameters = $this->expandListParameters((array) ($this->action()['parameters'] ?? []));
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

    public function validate(array $parameters): array
    {
        return parent::validate($this->normalizeArguments($parameters));
    }

    public function previewConfirmation(array $parameters, UnifiedActionContext $context): ?ActionResult
    {
        $parameters = $this->normalizeArguments($parameters);

        if ($this->actionId === '') {
            return ActionResult::failure('Action-backed tool is missing an action id.');
        }

        $payload = is_array($parameters['payload'] ?? null)
            ? (array) $parameters['payload']
            : Arr::except($parameters, ['confirmed', 'dry_run']);

        $prepared = $this->orchestrator()->prepare($this->actionId, $payload, $context);

        if (!($prepared['success'] ?? false)) {
            return ActionResult::needsUserInput(
                (string) ($prepared['message'] ?? $prepared['error'] ?? 'More information is required before this action can run.'),
                $prepared,
                ['action_id' => $this->actionId]
            );
        }

        return ActionResult::success(
            (string) ($prepared['message'] ?? 'Action is ready for confirmation.'),
            $prepared,
            ['action_id' => $this->actionId, 'confirmation_preview' => true]
        );
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $parameters = $this->normalizeArguments($parameters);

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
     * Turn flattened list definitions ("items.*.product_name") into one array parameter with
     * an object item schema, which is what planners and native tool calling understand.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function expandListParameters(array $parameters): array
    {
        $expanded = [];
        foreach ($parameters as $name => $definition) {
            $name = (string) $name;
            if (!str_contains($name, '.*.')) {
                $expanded[$name] = $definition;
                continue;
            }

            [$list, $field] = explode('.*.', $name, 2);
            $expanded[$list] ??= ['type' => 'array', 'required' => false, 'items' => ['type' => 'object', 'properties' => []]];
            if (!is_array($expanded[$list]['items'] ?? null)) {
                $expanded[$list]['items'] = ['type' => 'object', 'properties' => []];
            }
            $expanded[$list]['items']['properties'][$field] = array_diff_key((array) $definition, ['required' => true]);
            if ((bool) ($definition['required'] ?? false)) {
                $expanded[$list]['items']['required'][] = $field;
            }
        }

        return $expanded;
    }

    /**
     * Apply the action's `argument_aliases` (e.g. ['vendor' => 'vendor_name',
     * 'line_items' => 'items', 'items.*.description' => 'items.*.product_name']) so the
     * spellings a planner naturally produces reach the executor under canonical names.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function normalizeArguments(array $parameters): array
    {
        $aliases = (array) ($this->action()['argument_aliases'] ?? []);
        if ($aliases === []) {
            return $parameters;
        }

        $nested = [];
        foreach ($aliases as $from => $to) {
            [$from, $to] = [(string) $from, (string) $to];
            if (str_contains($from, '.*.') && str_contains($to, '.*.')) {
                [$list, $fromField] = explode('.*.', $from, 2);
                $nested[$list][$fromField] = explode('.*.', $to, 2)[1];
                continue;
            }

            if (array_key_exists($from, $parameters)) {
                if (!array_key_exists($to, $parameters) || $parameters[$to] === null || $parameters[$to] === '') {
                    $parameters[$to] = $parameters[$from];
                }
                unset($parameters[$from]);
            }
        }

        foreach ($nested as $list => $fields) {
            if (!is_array($parameters[$list] ?? null)) {
                continue;
            }

            $rows = array_is_list($parameters[$list]) ? $parameters[$list] : [$parameters[$list]];
            $parameters[$list] = array_map(static function (mixed $row) use ($fields): mixed {
                if (!is_array($row)) {
                    return $row;
                }
                foreach ($fields as $fromField => $toField) {
                    if (array_key_exists($fromField, $row)) {
                        $row[$toField] ??= $row[$fromField];
                        unset($row[$fromField]);
                    }
                }

                return $row;
            }, $rows);
        }

        return $parameters;
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
