<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use LaravelAIEngine\Contracts\ActionAuditLogger;
use LaravelAIEngine\Contracts\ActionExecutor;
use LaravelAIEngine\Contracts\ActionRelationResolver;
use LaravelAIEngine\Contracts\ConversationMemory;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionRegistry;

class ActionOrchestrator
{
    /**
     * @param array<int, ActionRelationResolver|string> $relationResolvers
     */
    public function __construct(
        protected ActionRegistry $registry,
        protected array $relationResolvers = [],
        protected ?ConversationMemory $memory = null,
        protected ?ActionAuditLogger $auditLogger = null
    )
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

    public function canExecute(string $actionId, ?UnifiedActionContext $context = null): bool
    {
        $action = $this->registry->get($actionId);

        return $action !== null
            && ($action['enabled'] ?? true) === true
            && $this->authorize($action, $context) === null;
    }

    public function requiresConfirmation(string $actionId, array $payload = [], ?UnifiedActionContext $context = null): bool
    {
        $action = $this->registry->get($actionId);
        if (!$action || !($action['enabled'] ?? true) || $this->authorize($action, $context) !== null) {
            return false;
        }

        return (bool) ($action['confirmation_required'] ?? true);
    }

    public function idempotencyMetadata(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
    {
        $action = $this->registry->get($actionId);
        if (!$action) {
            return ['key' => null, 'cache_namespace' => 'action:idempotency', 'has_cached_result' => false];
        }

        $key = $this->idempotencyKey($actionId, $action, $payload, $context);

        return [
            'key' => $key,
            'cache_namespace' => 'action:idempotency',
            'has_cached_result' => $key !== null && is_array($this->memory()?->get('action:idempotency', $key)),
        ];
    }

    public function executionStepMetadata(string $actionId, ActionResult $result, array $payload = [], ?UnifiedActionContext $context = null): array
    {
        $action = $this->registry->get($actionId) ?? [];

        return [
            'action_id' => $actionId,
            'operation' => (string) ($action['operation'] ?? $result->actionType ?? 'custom'),
            'success' => $result->success,
            'requires_user_input' => $result->requiresUserInput(),
            'requires_confirmation' => $this->requiresConfirmation($actionId, $payload, $context),
            'idempotency' => $this->idempotencyMetadata($actionId, $payload, $context),
            'metadata' => $result->metadata,
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

        $relationResolution = $this->resolveExistingRelations($actionId, $payload, $context, $action);
        $payload = $relationResolution['payload'];

        $missing = $this->missingRequired($action, $payload);
        if ($missing !== []) {
            $result = [
                'success' => false,
                'message' => 'More information is required before this action can run.',
                'missing_fields' => $missing,
                'needs_user_input' => true,
                'action' => Arr::only($action, ['id', 'module', 'label', 'operation']),
            ];

            $this->auditLogger()?->prepared($actionId, $action, $payload, $result, $context);

            return $result;
        }

        if ($executor = $this->executor($action['executor'] ?? null)) {
            $prepared = $executor->prepare($payload, $context, $action);

            $result = $this->normalizePreparedResult($actionId, $action, $prepared, $payload, $relationResolution);
            $this->auditLogger()?->prepared($actionId, $action, $payload, $result, $context);

            return $result;
        }

        $normalizer = $action['prepare'] ?? null;
        if ($normalizer) {
            $prepared = $this->call($normalizer, [$payload, $context, $action]);
            if (is_array($prepared) && (
                array_key_exists('success', $prepared)
                || array_key_exists('draft', $prepared)
                || array_key_exists('needs_user_input', $prepared)
            )) {
                $result = $this->normalizePreparedResult($actionId, $action, $prepared, $payload, $relationResolution);
                $this->auditLogger()?->prepared($actionId, $action, $payload, $result, $context);

                return $result;
            }

            $payload = is_array($prepared) ? $prepared : $payload;
        }

        $result = [
            'success' => true,
            'message' => $action['confirmation_message'] ?? 'Action is ready for confirmation.',
            'requires_confirmation' => (bool) ($action['confirmation_required'] ?? true),
            'action' => Arr::only($action, ['id', 'module', 'label', 'operation']),
            'draft' => [
                'action_id' => $actionId,
                'payload' => $payload,
                'summary' => array_merge($this->summary($action, $payload), array_filter([
                    'resolved_relations' => $relationResolution['resolved_relations'],
                    'pending_relations' => $relationResolution['pending_relations'],
                ])),
            ],
        ];

        $this->auditLogger()?->prepared($actionId, $action, $payload, $result, $context);

        return $result;
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

        $idempotencyKey = $this->idempotencyKey($actionId, $action, $payload, $context);
        if ($idempotencyKey !== null) {
            $cached = $this->memory()?->get('action:idempotency', $idempotencyKey);
            if (is_array($cached)) {
                return ActionResult::fromArray($cached)
                    ->withMetadata('idempotent_replay', true)
                    ->withActionInfo($actionId, (string) ($action['operation'] ?? 'custom'));
            }
        }

        $payload = $prepared['draft']['payload'];
        $createdRelations = $this->createMissingRelations($actionId, $payload, $context, $action);
        $payload = $createdRelations['payload'];

        if ($executor = $this->executor($action['executor'] ?? null)) {
            $result = $executor->execute($payload, $context, $action);

            return $this->finalizeExecutionResult($result, $actionId, $action, $payload, $context, $createdRelations['created_relations'], $idempotencyKey);
        }

        $handler = $action['handler'] ?? null;
        if (!$handler) {
            return ActionResult::failure("Action [{$actionId}] has no executable handler.");
        }

        $result = $this->call($handler, [$payload, $context, $action]);

        return $this->finalizeExecutionResult($result, $actionId, $action, $payload, $context, $createdRelations['created_relations'], $idempotencyKey);
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
     * @param array<int, ActionRelationResolver|string> $resolvers
     */
    public function setRelationResolvers(array $resolvers): void
    {
        $this->relationResolvers = $resolvers;
    }

    public function addRelationResolver(ActionRelationResolver|string $resolver): void
    {
        $this->relationResolvers[] = $resolver;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, resolved_relations: array<int, array<string, mixed>>, pending_relations: array<int, array<string, mixed>>}
     */
    protected function resolveExistingRelations(string $actionId, array $payload, ?UnifiedActionContext $context, array $action): array
    {
        $resolved = [];
        $pending = [];

        foreach ($this->relationResolvers($action) as $resolver) {
            $result = $resolver->resolveExisting($actionId, $payload, $context, $action);
            $payload = is_array($result['payload'] ?? null) ? $result['payload'] : $payload;
            $resolved = array_merge($resolved, (array) ($result['resolved_relations'] ?? []));
            $pending = array_merge($pending, (array) ($result['pending_relations'] ?? []));
        }

        return [
            'payload' => $payload,
            'resolved_relations' => $resolved,
            'pending_relations' => $pending,
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, created_relations: array<int, array<string, mixed>>}
     */
    protected function createMissingRelations(string $actionId, array $payload, ?UnifiedActionContext $context, array $action): array
    {
        $created = [];

        foreach ($this->relationResolvers($action) as $resolver) {
            $result = $resolver->createMissing($actionId, $payload, $context, $action);
            $payload = is_array($result['payload'] ?? null) ? $result['payload'] : $payload;
            $created = array_merge($created, (array) ($result['created_relations'] ?? []));
        }

        return [
            'payload' => $payload,
            'created_relations' => $created,
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<int, ActionRelationResolver>
     */
    protected function relationResolvers(array $action): array
    {
        $resolvers = array_merge($this->relationResolvers, (array) ($action['relation_resolvers'] ?? []));

        return array_values(array_map(function (mixed $resolver): ActionRelationResolver {
            if (is_string($resolver) && class_exists($resolver)) {
                $resolver = app($resolver);
            }

            if (!$resolver instanceof ActionRelationResolver) {
                throw new InvalidArgumentException(sprintf(
                    'Action relation resolver must implement %s.',
                    ActionRelationResolver::class
                ));
            }

            return $resolver;
        }, $resolvers));
    }

    protected function executor(mixed $executor): ?ActionExecutor
    {
        if (!$executor) {
            return null;
        }

        if (is_string($executor) && class_exists($executor)) {
            $executor = app($executor);
        }

        if (!$executor instanceof ActionExecutor) {
            throw new InvalidArgumentException(sprintf(
                'Action executor must implement %s.',
                ActionExecutor::class
            ));
        }

        return $executor;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $prepared
     * @param array<string, mixed> $payload
     * @param array{resolved_relations: array<int, array<string, mixed>>, pending_relations: array<int, array<string, mixed>>} $relationResolution
     * @return array<string, mixed>
     */
    protected function normalizePreparedResult(
        string $actionId,
        array $action,
        array $prepared,
        array $payload,
        array $relationResolution
    ): array {
        if (!($prepared['success'] ?? true)) {
            return $prepared;
        }

        $draft = is_array($prepared['draft'] ?? null) ? $prepared['draft'] : [];
        $draftPayload = is_array($draft['payload'] ?? null) ? $draft['payload'] : ($prepared['payload'] ?? $payload);
        $summary = is_array($draft['summary'] ?? null) ? $draft['summary'] : ($prepared['summary'] ?? $this->summary($action, $draftPayload));

        return array_merge([
            'success' => true,
            'message' => $prepared['message'] ?? $action['confirmation_message'] ?? 'Action is ready for confirmation.',
            'requires_confirmation' => (bool) ($action['confirmation_required'] ?? true),
            'action' => Arr::only($action, ['id', 'module', 'label', 'operation']),
        ], $prepared, [
            'draft' => [
                'action_id' => $actionId,
                'payload' => $draftPayload,
                'summary' => array_merge($summary, array_filter([
                    'resolved_relations' => $relationResolution['resolved_relations'],
                    'pending_relations' => $relationResolution['pending_relations'],
                ])),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $action
     * @param array<int, array<string, mixed>> $createdRelations
     */
    protected function normalizeExecutionResult(mixed $result, string $actionId, array $action, array $createdRelations = []): ActionResult
    {
        if ($result instanceof ActionResult) {
            if ($createdRelations !== []) {
                $result->withMetadata('created_relations', $createdRelations);
            }

            return $result->withActionInfo($actionId, (string) ($action['operation'] ?? 'custom'));
        }

        if (is_array($result)) {
            if ($createdRelations !== []) {
                $result['metadata'] = array_merge((array) ($result['metadata'] ?? []), [
                    'created_relations' => $createdRelations,
                ]);
            }

            return ActionResult::fromArray(array_merge([
                'success' => true,
                'message' => 'Action executed.',
            ], $result))->withActionInfo($actionId, (string) ($action['operation'] ?? 'custom'));
        }

        return ActionResult::success('Action executed.', $result, $createdRelations === [] ? [] : [
            'created_relations' => $createdRelations,
        ])->withActionInfo($actionId, (string) ($action['operation'] ?? 'custom'));
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $createdRelations
     */
    protected function finalizeExecutionResult(
        mixed $result,
        string $actionId,
        array $action,
        array $payload,
        ?UnifiedActionContext $context,
        array $createdRelations = [],
        ?string $idempotencyKey = null
    ): ActionResult {
        $actionResult = $this->normalizeExecutionResult($result, $actionId, $action, $createdRelations);

        if ($actionResult->success && $idempotencyKey !== null) {
            $this->memory()?->put('action:idempotency', $idempotencyKey, $actionResult->toArray(), now()->addDay());
        }

        $this->auditLogger()?->executed($actionId, $action, $payload, $actionResult, $context);

        return $actionResult;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     */
    protected function idempotencyKey(string $actionId, array $action, array $payload, ?UnifiedActionContext $context): ?string
    {
        $key = $payload['_idempotency_key']
            ?? $payload['idempotency_key']
            ?? $context?->metadata['idempotency_key']
            ?? null;

        if (!is_scalar($key) || trim((string) $key) === '') {
            return null;
        }

        $owner = $context?->userId ?: 'guest';

        return $owner . ':' . $actionId . ':' . sha1((string) $key);
    }

    protected function memory(): ?ConversationMemory
    {
        if ($this->memory instanceof ConversationMemory) {
            return $this->memory;
        }

        return $this->memory = app()->bound(ConversationMemory::class)
            ? app(ConversationMemory::class)
            : null;
    }

    protected function auditLogger(): ?ActionAuditLogger
    {
        if ($this->auditLogger instanceof ActionAuditLogger) {
            return $this->auditLogger;
        }

        return $this->auditLogger = app()->bound(ActionAuditLogger::class)
            ? app(ActionAuditLogger::class)
            : null;
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
