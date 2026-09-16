<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\ActionResult;

class AgentTaskStateService
{
    public function __construct(private readonly ToolOutcomeNormalizer $outcomes)
    {
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     */
    public function markPendingConfirmation(array &$state, string $toolName, array $params): void
    {
        $frame = $this->frame($state);
        $frame['status'] = 'confirming';
        if (empty($frame['active_objective'])) {
            $frame['active_objective'] = $this->objectiveFromTool($toolName);
        }
        $frame['pending_tool'] = [
            'name' => $toolName,
            'signature' => $this->writeSignature($toolName, $params),
            'summary' => $this->summary($params),
        ];

        $state['task_frame'] = $frame;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function clearPendingConfirmation(array &$state): void
    {
        unset($state['pending_tool']);

        $frame = $this->frame($state);
        unset($frame['pending_tool']);
        if (($frame['status'] ?? null) === 'confirming') {
            $frame['status'] = 'working';
        }

        $state['task_frame'] = $frame;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     */
    public function recordToolResult(array &$state, string $toolName, array $params, ActionResult $result, bool $writeTool = false): void
    {
        $outcome = $this->outcomes->normalize($toolName, $params, $result);
        $state['recent_outcomes'][] = $outcome;
        $state['recent_outcomes'] = array_slice((array) $state['recent_outcomes'], -12);

        $frame = $this->frame($state);
        if (empty($frame['active_objective'])) {
            $frame['active_objective'] = $this->objectiveFromTool($toolName);
        }
        $frame['recent_outcomes'] = array_slice((array) ($frame['recent_outcomes'] ?? []), -8);
        $frame['recent_outcomes'][] = $outcome;

        $objectiveCompleted = false;
        if ($writeTool && $result->success && !$result->requiresUserInput()) {
            $toolObjective = $this->objectiveFromTool($toolName);
            $activeObjective = trim((string) ($frame['active_objective'] ?? ''));
            $objectiveCompleted = $activeObjective === ''
                || $activeObjective === $toolName
                || $activeObjective === $toolObjective
                || $this->sameObjective($activeObjective, $toolName);
            $frame['status'] = $objectiveCompleted ? 'completed' : 'working';
            if ($objectiveCompleted) {
                // The draft has been written. Keeping it as the working payload would merge
                // it into the user's NEXT request for the same kind of record (e.g. a second
                // invoice inheriting the first one's customer and type).
                unset($frame['current_payload'], $frame['current_payload_source']);
                // Only when the task itself is done: a supporting write mid-task (e.g. creating
                // a missing product) must keep the lookups the task still relies on.
                $this->dropReadsOlderThanWrite($state, $frame, $toolName);
                // What remains belongs to the finished task. A later request for the same kind
                // of record must not count this write as its own final tool having run.
                foreach ((array) ($state['tool_results'] ?? []) as $index => $entry) {
                    if (is_array($entry)) {
                        $state['tool_results'][$index]['task_closed'] = true;
                    }
                }
            }
            $frame['pending_tool'] = null;
            $frame['completed_writes'][] = [
                'tool' => $toolName,
                'signature' => $this->writeSignature($toolName, $params),
                'params' => Arr::except($params, ['confirmed']),
                'label' => $outcome['label'] ?? $toolName,
                'outcome' => $outcome['outcome'] ?? 'completed',
            ];
        } elseif ($result->requiresUserInput()) {
            $frame['status'] = 'collecting';
        } elseif ($result->success) {
            $frame['status'] = $frame['status'] ?? 'working';
        }

        $state['task_frame'] = $frame;

        if ($objectiveCompleted) {
            return;
        }

        $payload = $this->payloadFromResult($result);
        if ($payload === []) {
            $payload = $this->payloadFromNotFoundOutcome($outcome, $state);
        }

        if ($payload !== []) {
            $this->rememberCurrentPayload($state, $payload, 'tool_result');
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $payload
     */
    public function rememberCurrentPayload(array &$state, array $payload, string $source = 'runtime'): void
    {
        $payload = $this->compactPayload($payload);
        if ($payload === []) {
            return;
        }

        $frame = $this->frame($state);
        $existing = is_array($frame['current_payload'] ?? null) ? $frame['current_payload'] : [];
        $frame['current_payload'] = $this->mergePayload($existing, $payload);
        $frame['current_payload_source'] = $source;
        $state['task_frame'] = $frame;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     */
    public function hasCompletedWrite(array $state, string $toolName, array $params): bool
    {
        $signature = $this->writeSignature($toolName, $params);
        foreach ((array) data_get($state, 'task_frame.completed_writes', []) as $write) {
            if (!is_array($write)) {
                continue;
            }

            if (($write['signature'] ?? null) === $signature) {
                return true;
            }

            if (($write['tool'] ?? null) === $toolName
                && is_array($write['params'] ?? null)
                && $this->matchesResolvedRelationPayload($write['params'], $params)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function writeSignature(string $toolName, array $params): string
    {
        $params = Arr::except($params, ['confirmed']);
        $params = $this->sortRecursive($params);

        return hash('sha256', $toolName.':'.json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function frame(array $state): array
    {
        $frame = $state['task_frame'] ?? [];

        return is_array($frame) ? $frame : [];
    }

    /**
     * Computed reads recorded before the task's write (counts, lists, aggregates such as
     * "3 sales invoices") describe the data as it was. Keeping them lets the planner answer
     * the next question from that stale result instead of reading again. Writes and entity
     * lookups (a resolved customer id) stay valid and remain as tool evidence.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $frame
     */
    private function dropReadsOlderThanWrite(array &$state, array &$frame, string $writeTool): void
    {
        $writes = array_flip(array_merge(
            [$writeTool],
            array_map(static fn (mixed $write): string => (string) (is_array($write) ? ($write['tool'] ?? '') : ''), (array) ($frame['completed_writes'] ?? []))
        ));
        $keepOutcome = static fn (mixed $outcome): bool => is_array($outcome)
            && (isset($writes[(string) ($outcome['tool'] ?? '')]) || ($outcome['entity_id'] ?? null) !== null);

        $outcomes = (array) ($state['recent_outcomes'] ?? []);
        $entityTools = [];
        foreach (array_merge($outcomes, (array) ($frame['recent_outcomes'] ?? [])) as $outcome) {
            if ($keepOutcome($outcome)) {
                $entityTools[(string) ($outcome['tool'] ?? '')] = true;
            }
        }

        if (isset($state['outcomes_at_turn_start'])) {
            // Keep the in-turn marker pointing at the same boundary after pruning.
            $beforeTurn = array_slice($outcomes, 0, (int) $state['outcomes_at_turn_start']);
            $state['outcomes_at_turn_start'] = count(array_filter($beforeTurn, $keepOutcome));
        }
        $state['recent_outcomes'] = array_values(array_filter($outcomes, $keepOutcome));
        $frame['recent_outcomes'] = array_values(array_filter((array) ($frame['recent_outcomes'] ?? []), $keepOutcome));

        if (isset($state['tool_results']) && is_array($state['tool_results'])) {
            $state['tool_results'] = array_values(array_filter(
                $state['tool_results'],
                static fn (mixed $entry): bool => is_array($entry) && isset($entityTools[(string) ($entry['tool'] ?? '')])
            ));
        }
    }

    /**
     * Whether an objective label (often a skill id such as "invoice_create" or "invoices")
     * names the same entity as a tool ("create_invoice"), ignoring verbs and plurals.
     */
    private function sameObjective(string $objective, string $toolName): bool
    {
        $entity = static function (string $value): array {
            $verbs = ['find', 'lookup', 'search', 'create', 'update', 'delete', 'remove', 'send', 'generate', 'new', 'add', 'make', 'issue'];
            $tokens = array_values(array_filter(
                explode('_', Str::snake($value)),
                static fn (string $token): bool => $token !== '' && !in_array($token, $verbs, true)
            ));
            $tokens = array_map(static fn (string $token): string => Str::singular($token), $tokens);
            sort($tokens);

            return $tokens;
        };

        $objectiveEntity = $entity($objective);

        return $objectiveEntity !== [] && $objectiveEntity === $entity($toolName);
    }

    private function objectiveFromTool(string $toolName): string
    {
        return Str::snake(preg_replace('/^(find|list|lookup|search|create|update|delete|remove|send|generate)_/', '', Str::snake($toolName)) ?: $toolName);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function summary(array $params): array
    {
        $summary = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if ($key === 'confirmed' || $value === null || $value === '' || $key === 'id' || str_ends_with($key, '_id')) {
                continue;
            }

            $label = preg_replace('/[_\-. ]name$/i', '', $key);
            $summary[Str::headline(str_replace(['.', '-'], '_', $label ?: $key))] = is_array($value)
                ? $this->summaryList($value)
                : $value;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromResult(ActionResult $result): array
    {
        $data = is_array($result->data) ? $result->data : [];
        foreach (['current_payload', 'draft_payload', 'payload'] as $key) {
            if (is_array($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        if (is_array($data['draft']['payload'] ?? null)) {
            return $data['draft']['payload'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private function payloadFromNotFoundOutcome(array $outcome, array $state): array
    {
        if (($outcome['outcome'] ?? null) !== 'not_found') {
            return [];
        }

        $entity = Str::snake(Str::singular(trim((string) ($outcome['entity_type'] ?? ''))));
        $label = trim((string) ($outcome['label'] ?? ''));
        if ($entity === '' || $label === '') {
            return [];
        }

        if (!$this->currentPayloadHasTopLevelEntityField($state, $entity)) {
            return [];
        }

        return [$entity.'_name' => $label];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function currentPayloadHasTopLevelEntityField(array $state, string $entity): bool
    {
        $payload = data_get($state, 'task_frame.current_payload');
        if (!is_array($payload)) {
            return false;
        }

        foreach (array_keys($payload) as $key) {
            if (is_string($key) && str_starts_with($key, $entity.'_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        return array_filter($payload, function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (!is_array($value)) {
                return true;
            }

            return $this->compactPayload($value) !== [];
        });
    }

    /**
     * @param array<int|string, mixed> $existing
     * @param array<int|string, mixed> $incoming
     * @return array<int|string, mixed>
     */
    private function mergePayload(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value) && is_array($existing[$key] ?? null) && !$this->isListPayload($value) && !$this->isListPayload($existing[$key])) {
                $existing[$key] = $this->mergePayload($existing[$key], $value);
                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isListPayload(array $value): bool
    {
        return array_is_list($value);
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int|string, mixed>
     */
    private function summaryList(array $items): array
    {
        return array_map(fn (mixed $item): mixed => is_array($item) ? $this->summary($item) : $item, $items);
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        ksort($value);

        return $value;
    }

    private function isRelationIdField(string $key): bool
    {
        return $key === 'id' || str_ends_with($key, '_id');
    }

    /**
     * @param array<int|string, mixed> $completed
     * @param array<int|string, mixed> $candidate
     */
    private function matchesResolvedRelationPayload(array $completed, array $candidate): bool
    {
        $candidate = Arr::except($candidate, ['confirmed']);

        return $this->sortRecursive($completed) === $this->sortRecursive(
            $this->removeNewlyResolvedRelationIds($completed, $candidate)
        );
    }

    /**
     * @param array<int|string, mixed> $completed
     * @param array<int|string, mixed> $candidate
     * @return array<int|string, mixed>
     */
    private function removeNewlyResolvedRelationIds(array $completed, array $candidate): array
    {
        foreach ($candidate as $key => $item) {
            if (is_array($item)) {
                $completedChild = is_array($completed[$key] ?? null) ? $completed[$key] : [];
                $candidate[$key] = $this->removeNewlyResolvedRelationIds($completedChild, $item);
                continue;
            }

            $keyString = (string) $key;
            if (!$this->isRelationIdField($keyString) || array_key_exists($key, $completed)) {
                continue;
            }

            if ($this->hasDescriptiveSibling($candidate, $keyString)
                && $this->hasDescriptiveSibling($completed, $keyString)) {
                unset($candidate[$key]);
            }
        }

        return $candidate;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function hasDescriptiveSibling(array $value, string $idKey): bool
    {
        $entity = $idKey === 'id' ? '' : substr($idKey, 0, -3);
        $candidateKeys = array_filter([
            'name',
            'title',
            'label',
            'email',
            'number',
            'code',
            'slug',
            $entity !== '' ? "{$entity}_name" : null,
            $entity !== '' ? "{$entity}_email" : null,
            $entity !== '' ? "{$entity}_number" : null,
            $entity !== '' ? "{$entity}_code" : null,
            $entity !== '' ? "{$entity}_slug" : null,
        ]);

        foreach ($candidateKeys as $candidateKey) {
            if (array_key_exists($candidateKey, $value) && $value[$candidateKey] !== null && $value[$candidateKey] !== '') {
                return true;
            }
        }

        foreach ($value as $key => $candidate) {
            if (!is_string($key) || $candidate === null || $candidate === '') {
                continue;
            }

            foreach (['_name', '_title', '_label', '_email', '_number', '_code', '_slug'] as $suffix) {
                if (str_ends_with($key, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
