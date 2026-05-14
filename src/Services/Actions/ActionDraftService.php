<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use DateInterval;
use DateTimeInterface;
use LaravelAIEngine\Contracts\ActionFlowHandler;
use LaravelAIEngine\Contracts\ConversationMemory;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class ActionDraftService
{
    public function __construct(
        private readonly ActionFlowHandler $actions,
        private readonly ConversationMemory $memory
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(UnifiedActionContext $context, string $actionId): array
    {
        $payload = $this->memory->get('action-draft:payload', $this->payloadKey($context, $actionId), []);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $payloadPatch
     * @return array<string, mixed>
     */
    public function patchAndPrepare(UnifiedActionContext $context, string $actionId, array $payloadPatch, bool $reset = false): array
    {
        $previousActionId = $this->activeAction($context);
        $switchedAction = $previousActionId !== null && $previousActionId !== $actionId;
        $currentPayload = $this->get($context, $actionId);
        $startsNewDraft = $reset || $switchedAction || $currentPayload === [];

        if ($switchedAction) {
            $this->forget($context, $previousActionId);
            $currentPayload = [];
        }

        if ($reset) {
            $this->forgetState($context, $actionId);
            $currentPayload = [];
        }

        $payloadPatch = $this->guardRelationApprovalPatch($context, $payloadPatch);
        $payloadPatch = $this->normalizeRelationApprovalPatch($context, $actionId, $payloadPatch, $currentPayload);
        $payloadPatch = $startsNewDraft
            ? $this->merge($this->initialPayload($context, $actionId), $payloadPatch)
            : $payloadPatch;

        $payload = ($reset || $switchedAction)
            ? $payloadPatch
            : $this->merge($currentPayload, $payloadPatch);

        $result = $this->actions->prepare($actionId, $payload, $context);

        $draftPayload = $result['draft']['payload'] ?? $payload;
        if (is_array($draftPayload)) {
            $payload = $draftPayload;
        }

        $this->putPayload($context, $actionId, $payload);
        $this->putActiveAction($context, $actionId);

        return array_merge($result, [
            'action_id' => $actionId,
            'current_payload' => $payload,
            'draft_cached' => true,
            'action_switched' => $switchedAction,
            'previous_action_id' => $switchedAction ? $previousActionId : null,
        ]);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    public function execute(UnifiedActionContext $context, string $actionId, bool $confirmed, ?array $payload = null): array
    {
        $payload ??= $this->get($context, $actionId);

        if ($confirmed && $payload !== []) {
            $executed = $this->executedResult($context, $actionId, $payload);
            if (is_array($executed)) {
                return array_merge($executed, [
                    'duplicate_confirmation_replayed' => true,
                ]);
            }
        }

        $result = $this->actions->execute($actionId, $payload, $confirmed, $context);
        $result = $result instanceof ActionResult ? $result->toArray() : $result;

        if (($result['success'] ?? false) && !($result['dry_run'] ?? false)) {
            $this->rememberExecutedResult($context, $actionId, $payload, $result);
            $this->forget($context, $actionId);
        }

        return $result;
    }

    public function forget(UnifiedActionContext $context, string $actionId): void
    {
        $this->memory->forget('action-draft:payload', $this->payloadKey($context, $actionId));
        $this->forgetState($context, $actionId);

        if ($this->activeAction($context) === $actionId) {
            $this->memory->forget('action-draft:active', $this->activeKey($context));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function state(UnifiedActionContext $context, string $actionId): array
    {
        $state = $this->memory->get('action-draft:state', $this->stateKey($context, $actionId), []);

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function putState(UnifiedActionContext $context, string $actionId, array $state): void
    {
        $this->memory->put('action-draft:state', $this->stateKey($context, $actionId), $this->compact($state), $this->ttl());
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function patchState(UnifiedActionContext $context, string $actionId, array $patch): array
    {
        $state = $this->merge($this->state($context, $actionId), $patch);
        $this->putState($context, $actionId, $state);

        return $state;
    }

    public function forgetState(UnifiedActionContext $context, string $actionId): void
    {
        $this->memory->forget('action-draft:state', $this->stateKey($context, $actionId));
    }

    public function activeAction(UnifiedActionContext $context): ?string
    {
        $actionId = $this->memory->get('action-draft:active', $this->activeKey($context));

        return is_string($actionId) && $actionId !== '' ? $actionId : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function putPayload(UnifiedActionContext $context, string $actionId, array $payload): void
    {
        $this->memory->put('action-draft:payload', $this->payloadKey($context, $actionId), $payload, $this->ttl());
    }

    private function putActiveAction(UnifiedActionContext $context, string $actionId): void
    {
        $this->memory->put('action-draft:active', $this->activeKey($context), $actionId, $this->ttl());
    }

    /**
     * @return array<string, mixed>
     */
    private function initialPayload(UnifiedActionContext $context, string $actionId): array
    {
        $action = $this->actions->action($actionId, $context);
        $payload = is_array($action['initial_payload'] ?? null) ? $action['initial_payload'] : [];

        return $this->resolveInitialPayloadTokens($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveInitialPayloadTokens(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->resolveInitialPayloadTokens($value);
                continue;
            }

            if (!is_string($value) || !str_starts_with($value, '@today')) {
                continue;
            }

            if (preg_match('/^@today(?:([+-])(\d+)d)?$/', $value, $matches) !== 1) {
                continue;
            }

            $date = now();
            if (($matches[1] ?? '') !== '' && isset($matches[2])) {
                $days = (int) $matches[2];
                $date = $matches[1] === '+' ? $date->addDays($days) : $date->subDays($days);
            }

            $payload[$key] = $date->toDateString();
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $patch
     * @param array<string, mixed> $currentPayload
     * @return array<string, mixed>
     */
    private function normalizeRelationApprovalPatch(UnifiedActionContext $context, string $actionId, array $patch, array $currentPayload): array
    {
        if (!array_key_exists('approved_missing_relations', $patch)) {
            return $patch;
        }

        $keys = $this->approvalKeys($context, $patch['approved_missing_relations'], $actionId, $currentPayload);
        if ($keys === []) {
            unset($patch['approved_missing_relations']);

            return $patch;
        }

        $patch['approved_missing_relations'] = array_values(array_unique(array_merge(
            array_map('strval', (array) ($currentPayload['approved_missing_relations'] ?? [])),
            $keys
        )));

        return $patch;
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function guardRelationApprovalPatch(UnifiedActionContext $context, array $patch): array
    {
        if (!array_key_exists('approved_missing_relations', $patch) || $this->looksLikeApproval($context)) {
            return $patch;
        }

        unset($patch['approved_missing_relations']);

        return $patch;
    }

    private function looksLikeApproval(UnifiedActionContext $context): bool
    {
        $message = mb_strtolower(trim((string) ($context->metadata['latest_user_message'] ?? '')));
        if ($message === '') {
            return false;
        }

        if (preg_match('/\b(no|not|don\'t|do not|cancel|stop|instead)\b/u', $message) === 1) {
            return false;
        }

        return preg_match('/\b(yes|approve|approved|confirm|create|add|go ahead|proceed|ok|okay|sure)\b/u', $message) === 1;
    }

    /**
     * @param array<string, mixed> $currentPayload
     * @return array<int, string>
     */
    private function approvalKeys(UnifiedActionContext $context, mixed $value, string $actionId, array $currentPayload): array
    {
        if ($value === true) {
            return $this->pendingApprovalKeys($context, $actionId, $currentPayload);
        }

        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        if (!is_array($value)) {
            return [];
        }

        $keys = [];
        array_walk_recursive($value, function (mixed $item) use (&$keys): void {
            if (is_string($item) && trim($item) !== '') {
                $keys[] = trim($item);
            }
        });

        return $keys !== [] ? array_values(array_unique($keys)) : $this->pendingApprovalKeys($context, $actionId, $currentPayload);
    }

    /**
     * @param array<string, mixed> $currentPayload
     * @return array<int, string>
     */
    private function pendingApprovalKeys(UnifiedActionContext $context, string $actionId, array $currentPayload): array
    {
        if ($currentPayload === []) {
            return [];
        }

        try {
            $prepared = $this->actions->prepare($actionId, $currentPayload, $context);
        } catch (\Throwable) {
            return [];
        }

        return collect($prepared['next_options'] ?? [])
            ->filter(fn (mixed $option): bool => is_array($option) && ($option['type'] ?? null) === 'relation_create_confirmation')
            ->pluck('approval_key')
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (string $key): string => trim($key))
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function merge(array $current, array $patch): array
    {
        $arrayOps = is_array($patch['_array_ops'] ?? null) ? $patch['_array_ops'] : [];
        unset($patch['_array_ops']);
        foreach ($arrayOps as $operation) {
            if (is_array($operation) && is_string($operation['path'] ?? null) && trim($operation['path']) !== '') {
                data_forget($patch, trim($operation['path']));
            }
        }

        foreach ($patch as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $current[$key] = $value;
                continue;
            }

            if (is_array($value) && is_array($current[$key] ?? null)) {
                $current[$key] = $this->merge($current[$key], $value);
                continue;
            }

            $current[$key] = $value;
        }

        $current = $this->applyArrayOperations($current, $arrayOps);

        return $this->compact($current);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $operations
     * @return array<string, mixed>
     */
    private function applyArrayOperations(array $payload, array $operations): array
    {
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $op = strtolower(trim((string) ($operation['op'] ?? '')));
            $path = trim((string) ($operation['path'] ?? ''));
            if ($op === '' || $path === '') {
                continue;
            }

            $items = data_get($payload, $path);
            $items = is_array($items) && array_is_list($items) ? $items : [];

            $items = match ($op) {
                'append', 'add' => $this->appendArrayOperationValues($items, $operation),
                'prepend' => array_values(array_merge($this->arrayOperationValues($operation), $items)),
                'update' => $this->updateArrayOperationValue($items, $operation),
                'increment' => $this->incrementArrayOperationValue($items, $operation, false),
                'decrement' => $this->incrementArrayOperationValue($items, $operation, true),
                'remove', 'delete' => $this->removeArrayOperationValues($items, $operation),
                'replace' => $this->arrayOperationValues($operation),
                default => $items,
            };

            data_set($payload, $path, $items);
        }

        return $payload;
    }

    /**
     * @param array<int, mixed> $items
     * @param array<string, mixed> $operation
     * @return array<int, mixed>
     */
    private function appendArrayOperationValues(array $items, array $operation): array
    {
        foreach ($this->arrayOperationValues($operation) as $value) {
            $items[] = $value;
        }

        return array_values($items);
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<int, mixed>
     */
    private function arrayOperationValues(array $operation): array
    {
        if (array_key_exists('values', $operation) && is_array($operation['values']) && array_is_list($operation['values'])) {
            return array_values(array_filter($operation['values'], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []));
        }

        if (array_key_exists('value', $operation)) {
            return [$operation['value']];
        }

        return [];
    }

    /**
     * @param array<int, mixed> $items
     * @param array<string, mixed> $operation
     * @return array<int, mixed>
     */
    private function updateArrayOperationValue(array $items, array $operation): array
    {
        $index = $this->arrayOperationIndex($items, $operation);
        if ($index === null) {
            return $items;
        }

        $value = $operation['value'] ?? [];
        $items[$index] = is_array($items[$index] ?? null) && is_array($value)
            ? $this->merge($items[$index], $value)
            : $value;

        return array_values($items);
    }

    /**
     * @param array<int, mixed> $items
     * @param array<string, mixed> $operation
     * @return array<int, mixed>
     */
    private function incrementArrayOperationValue(array $items, array $operation, bool $decrement): array
    {
        $index = $this->arrayOperationIndex($items, $operation);
        if ($index === null || !is_array($items[$index] ?? null)) {
            return $items;
        }

        $field = trim((string) ($operation['field'] ?? ''));
        if ($field === '') {
            return $items;
        }

        $amount = abs((float) ($operation['amount'] ?? 1));
        if ($decrement) {
            $amount *= -1;
        }

        $current = (float) data_get($items[$index], $field, 0);
        data_set($items[$index], $field, $current + $amount);

        return array_values($items);
    }

    /**
     * @param array<int, mixed> $items
     * @param array<string, mixed> $operation
     * @return array<int, mixed>
     */
    private function removeArrayOperationValues(array $items, array $operation): array
    {
        $index = $this->arrayOperationIndex($items, $operation);
        if ($index === null) {
            return $items;
        }

        unset($items[$index]);

        return array_values($items);
    }

    /**
     * @param array<int, mixed> $items
     * @param array<string, mixed> $operation
     */
    private function arrayOperationIndex(array $items, array $operation): ?int
    {
        if (isset($operation['index']) && is_numeric($operation['index'])) {
            $index = (int) $operation['index'];

            return array_key_exists($index, $items) ? $index : null;
        }

        $match = is_array($operation['match'] ?? null) ? $operation['match'] : [];
        if ($match === []) {
            return null;
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $matches = true;
            foreach ($match as $field => $expected) {
                if ((string) data_get($item, (string) $field) !== (string) $expected) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function compact(array $payload): array
    {
        return array_filter(array_map(function (mixed $value): mixed {
            if (is_array($value)) {
                return $this->compact($value);
            }

            return $value;
        }, $payload), fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executedResult(UnifiedActionContext $context, string $actionId, array $payload): ?array
    {
        $result = $this->memory->get('action-draft:executed', $this->executedKey($context, $actionId, $payload));

        return is_array($result) ? $result : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     */
    private function rememberExecutedResult(UnifiedActionContext $context, string $actionId, array $payload, array $result): void
    {
        if ($payload === []) {
            return;
        }

        $this->memory->put('action-draft:executed', $this->executedKey($context, $actionId, $payload), $result, now()->addDay());
    }

    private function payloadKey(UnifiedActionContext $context, string $actionId): string
    {
        return $this->ownerKey($context) . ':' . $context->sessionId . ':' . $actionId;
    }

    private function stateKey(UnifiedActionContext $context, string $actionId): string
    {
        return $this->ownerKey($context) . ':' . $context->sessionId . ':' . $actionId;
    }

    private function activeKey(UnifiedActionContext $context): string
    {
        return $this->ownerKey($context) . ':' . $context->sessionId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executedKey(UnifiedActionContext $context, string $actionId, array $payload): string
    {
        return $this->ownerKey($context) . ':' . $context->sessionId . ':' . $actionId . ':' . sha1(json_encode($this->compact($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function ownerKey(UnifiedActionContext $context): string
    {
        return is_scalar($context->userId) && (string) $context->userId !== ''
            ? (string) $context->userId
            : 'guest';
    }

    private function ttl(): DateTimeInterface|DateInterval|int
    {
        return now()->addSeconds(max(300, (int) config('ai-agent.memory.draft_ttl_seconds', 7200)));
    }
}
