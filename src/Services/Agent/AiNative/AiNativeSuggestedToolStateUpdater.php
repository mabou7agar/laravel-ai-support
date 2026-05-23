<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;

class AiNativeSuggestedToolStateUpdater
{
    /**
     * @param array<string, mixed> $state
     */
    public function recordAttemptResult(array &$state, string $signature, ActionResult $result): void
    {
        $state['suggested_tool_attempts'][$signature] = array_merge(
            (array) ($state['suggested_tool_attempts'][$signature] ?? []),
            [
                'result_success' => $result->success,
                'needs_user_input' => $result->requiresUserInput(),
            ]
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    public function hasSuccessfulAttempt(array $state): bool
    {
        foreach ((array) ($state['suggested_tool_attempts'] ?? []) as $attempt) {
            if (!is_array($attempt) || ($attempt['valid'] ?? false) !== true) {
                continue;
            }

            if (($attempt['result_success'] ?? false) === true && ($attempt['needs_user_input'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $candidate
     */
    public function applyResultToContinuation(array &$state, array $candidate, ActionResult $result): void
    {
        if (!$result->success || $result->requiresUserInput()) {
            return;
        }

        $missingField = $candidate['missing_field'] ?? null;
        if (!is_string($missingField) || trim($missingField) === '') {
            return;
        }

        $entity = is_string($candidate['entity'] ?? null) ? (string) $candidate['entity'] : null;
        $value = $this->resolvedValueForMissingField($missingField, $entity, $result);
        if ($value === null || $value === '') {
            return;
        }

        $payload = data_get($state, 'suggested_tool_continuation.tool_result.data.current_payload');
        $payload = is_array($payload) ? $payload : [];
        data_set($payload, $missingField, $value);
        $state['suggested_tool_continuation']['tool_result']['data']['current_payload'] = $payload;

        $currentPayload = data_get($state, 'task_frame.current_payload');
        if (is_array($currentPayload)) {
            data_set($currentPayload, $missingField, $value);
            $state['task_frame']['current_payload'] = $currentPayload;
            $state['task_frame']['current_payload_source'] = 'suggested_tool_result';
        }
    }

    private function resolvedValueForMissingField(string $missingField, ?string $entity, ActionResult $result): mixed
    {
        $data = is_array($result->data) ? $result->data : [];
        $entityData = $this->resultEntityData($data);
        $field = basename(str_replace('.', '/', $missingField));
        $candidates = array_values(array_filter([
            $field,
            $entity !== null && $entity !== '' ? $entity.'_id' : null,
            str_ends_with($field, '_id') ? 'id' : null,
            'uuid',
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        foreach ($candidates as $key) {
            if (array_key_exists($key, $entityData) && $entityData[$key] !== null && $entityData[$key] !== '') {
                return $entityData[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resultEntityData(array $data): array
    {
        foreach ($data as $value) {
            if (is_array($value) && $this->looksLikeEntityData($value)) {
                return $value;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function looksLikeEntityData(array $data): bool
    {
        return array_key_exists('id', $data)
            || array_key_exists('uuid', $data)
            || array_key_exists('name', $data)
            || array_key_exists('title', $data)
            || array_key_exists('label', $data)
            || array_key_exists('number', $data)
            || array_key_exists('email', $data);
    }
}
