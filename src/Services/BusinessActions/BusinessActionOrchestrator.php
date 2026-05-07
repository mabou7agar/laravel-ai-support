<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\BusinessActions;

use Illuminate\Support\Arr;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class BusinessActionOrchestrator
{
    public function __construct(protected BusinessActionRegistry $registry)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(?string $module = null): array
    {
        $actions = collect($this->registry->all())
            ->when($module, fn ($items) => $items->filter(
                fn (array $action): bool => strcasecmp((string) ($action['module'] ?? ''), $module) === 0
            ))
            ->map(fn (array $action): array => Arr::only($action, [
                'id',
                'module',
                'label',
                'description',
                'operation',
                'parameters',
                'required',
                'confirmation_required',
                'credit_cost',
            ]))
            ->values()
            ->all();

        return [
            'success' => true,
            'actions' => $actions,
            'count' => count($actions),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
    {
        $action = $this->registry->get($actionId);

        if (!$action || !($action['enabled'] ?? true)) {
            return ['success' => false, 'error' => "Action [{$actionId}] is not available."];
        }

        if (($error = $this->authorize($action, $context)) !== null) {
            return $error;
        }

        $missing = $this->missingRequired($action, $payload);
        if ($missing !== []) {
            return [
                'success' => false,
                'message' => 'More information is required before this action can run.',
                'missing_fields' => $missing,
                'needs_user_input' => true,
                'action' => Arr::only($action, ['id', 'module', 'label', 'operation']),
            ];
        }

        $normalizer = $action['prepare'] ?? null;
        if ($normalizer) {
            $payload = $this->call($normalizer, [$payload, $context, $action]);
        }

        return [
            'success' => true,
            'message' => $action['confirmation_message'] ?? 'Action is ready for confirmation.',
            'requires_confirmation' => (bool) ($action['confirmation_required'] ?? true),
            'action' => Arr::only($action, ['id', 'module', 'label', 'operation']),
            'draft' => [
                'action_id' => $actionId,
                'payload' => $payload,
                'summary' => $this->summary($action, $payload),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(
        string $actionId,
        array $payload,
        bool $confirmed = false,
        ?UnifiedActionContext $context = null
    ): ActionResult {
        $action = $this->registry->get($actionId);

        if (!$action || !($action['enabled'] ?? true)) {
            return ActionResult::failure("Action [{$actionId}] is not available.");
        }

        if (($error = $this->authorize($action, $context)) !== null) {
            return ActionResult::failure((string) $error['error'], null, $error);
        }

        $prepared = $this->prepare($actionId, $payload, $context);
        if (!($prepared['success'] ?? false)) {
            return ActionResult::needsUserInput(
                (string) ($prepared['message'] ?? $prepared['error'] ?? 'Action is not ready.'),
                $prepared,
                ['action_id' => $actionId]
            );
        }

        if (($action['confirmation_required'] ?? true) && !$confirmed) {
            return ActionResult::needsUserInput(
                'Please confirm before executing this action.',
                $prepared,
                ['action_id' => $actionId, 'requires_confirmation' => true]
            );
        }

        $handler = $action['handler'] ?? null;
        if (!$handler) {
            return ActionResult::failure("Action [{$actionId}] has no executable handler.");
        }

        $result = $this->call($handler, [$prepared['draft']['payload'], $context, $action]);

        if ($result instanceof ActionResult) {
            return $result->withActionInfo($actionId, (string) ($action['operation'] ?? 'custom'));
        }

        if (is_array($result)) {
            return ActionResult::fromArray(array_merge([
                'success' => true,
                'message' => 'Action executed.',
            ], $result))->withActionInfo($actionId, (string) ($action['operation'] ?? 'custom'));
        }

        return ActionResult::success('Action executed.', $result)
            ->withActionInfo($actionId, (string) ($action['operation'] ?? 'custom'));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function suggest(array $context = [], ?UnifiedActionContext $actionContext = null): array
    {
        $suggestions = [];

        foreach ($this->registry->all() as $action) {
            $suggester = $action['suggest'] ?? null;
            if (!$suggester) {
                continue;
            }

            $result = $this->call($suggester, [$context, $actionContext, $action]);
            foreach ((array) $result as $suggestion) {
                if (is_array($suggestion)) {
                    $suggestions[] = array_merge([
                        'action_id' => $action['id'],
                        'label' => $action['label'] ?? $action['id'],
                    ], $suggestion);
                }
            }
        }

        return [
            'success' => true,
            'suggestions' => $suggestions,
            'count' => count($suggestions),
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    protected function missingRequired(array $action, array $payload): array
    {
        return array_values(array_filter(
            (array) ($action['required'] ?? []),
            static fn (string $field): bool => !Arr::has($payload, $field) || Arr::get($payload, $field) === null || Arr::get($payload, $field) === ''
        ));
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>|null
     */
    protected function authorize(array $action, ?UnifiedActionContext $context): ?array
    {
        $authorizer = $action['authorize'] ?? null;
        if (!$authorizer) {
            return null;
        }

        $allowed = (bool) $this->call($authorizer, [$context, $action]);

        return $allowed ? null : ['success' => false, 'error' => 'Permission denied.'];
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function summary(array $action, array $payload): array
    {
        $fields = (array) ($action['summary_fields'] ?? array_keys($payload));

        return Arr::only($payload, $fields);
    }

    /**
     * @param callable|array|string $callable
     * @param array<int, mixed> $arguments
     */
    protected function call(callable|array|string $callable, array $arguments): mixed
    {
        if (is_string($callable) && class_exists($callable)) {
            return app($callable)(...$arguments);
        }

        if (is_array($callable) && isset($callable[0]) && is_string($callable[0]) && class_exists($callable[0])) {
            $callable[0] = app($callable[0]);
        }

        if (is_callable($callable)) {
            return $callable(...$arguments);
        }

        return app()->call($callable, $arguments);
    }
}
