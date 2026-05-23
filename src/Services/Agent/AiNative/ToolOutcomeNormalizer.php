<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\ActionResult;

class ToolOutcomeNormalizer
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function normalize(string $toolName, array $params, ActionResult $result): array
    {
        $data = is_array($result->data) ? $result->data : [];
        $entity = $this->entityData($data);
        $entityType = $this->entityType($toolName, $data, $result->metadata);
        $entityId = $this->firstValue($entity, ['id', "{$entityType}_id", 'uuid']);

        return array_filter([
            'tool' => $toolName,
            'outcome' => $this->outcome($toolName, $result, $data),
            'success' => $result->success,
            'needs_user_input' => $result->requiresUserInput(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'label' => $this->label($entity, $params, $toolName),
            'display' => $this->displayData($entity),
            'visible_to_user' => false,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    private function entityType(string $toolName, array $data, array $metadata): string
    {
        foreach ([$metadata['entity_type'] ?? null, $data['entity_type'] ?? null, $data['type'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return Str::snake(Str::singular($value));
            }
        }

        $name = Str::snake($toolName);
        $name = preg_replace('/^(create|find|lookup|search|update|delete|remove|send|generate)_/', '', $name) ?: $name;

        return Str::singular($name);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function entityData(array $data): array
    {
        foreach ($data as $value) {
            if (is_array($value) && $this->looksLikeEntity($value)) {
                return $value;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function looksLikeEntity(array $data): bool
    {
        return array_key_exists('id', $data)
            || array_key_exists('uuid', $data)
            || array_key_exists('label', $data)
            || array_key_exists('name', $data)
            || array_key_exists('title', $data)
            || array_key_exists('number', $data)
            || $this->hasFieldSuffix($data, ['_name', '_number', '_email', '_code']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function outcome(string $toolName, ActionResult $result, array $data): string
    {
        if ($result->requiresUserInput()) {
            return 'needs_input';
        }

        if (($data['found'] ?? null) === false) {
            return 'not_found';
        }

        if (!$result->success) {
            return 'failed';
        }

        $toolName = Str::snake($toolName);

        return match (true) {
            str_starts_with($toolName, 'create_') => 'created',
            str_starts_with($toolName, 'update_') => 'updated',
            str_starts_with($toolName, 'delete_'), str_starts_with($toolName, 'remove_') => 'deleted',
            str_starts_with($toolName, 'find_'), str_starts_with($toolName, 'lookup_'), str_starts_with($toolName, 'search_') => 'found',
            str_starts_with($toolName, 'send_') => 'sent',
            default => 'completed',
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $params
     */
    private function label(array $data, array $params, string $toolName): string
    {
        foreach ([
            'label',
            'name',
            'title',
            'number',
            'email',
            'query',
        ] as $key) {
            $value = $data[$key] ?? $params[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        foreach ([$data, $params] as $source) {
            foreach ($source as $key => $value) {
                if (!is_string($key) || !is_scalar($value) || trim((string) $value) === '') {
                    continue;
                }

                if ($this->fieldHasSuffix($key, ['_name', '_number', '_email', '_code'])) {
                    return (string) $value;
                }
            }
        }

        return str_replace('_', ' ', $toolName);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $suffixes
     */
    private function hasFieldSuffix(array $data, array $suffixes): bool
    {
        foreach ($data as $key => $value) {
            if (!is_string($key) || $value === null || $value === '') {
                continue;
            }

            if ($this->fieldHasSuffix($key, $suffixes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $suffixes
     */
    private function fieldHasSuffix(string $key, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function displayData(array $data): array
    {
        return array_filter($data, static function (mixed $value, string|int $key): bool {
            $key = (string) $key;

            return $value !== null
                && $value !== ''
                && $key !== 'id'
                && $key !== 'uuid'
                && !str_ends_with($key, '_id');
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    private function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }
}
