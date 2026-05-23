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

        if ($writeTool && $result->success && !$result->requiresUserInput()) {
            $frame['status'] = 'completed';
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

        $payload = $this->payloadFromResult($result);
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

    private function objectiveFromTool(string $toolName): string
    {
        return Str::snake(preg_replace('/^(find|lookup|search|create|update|delete|remove|send|generate)_/', '', Str::snake($toolName)) ?: $toolName);
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
