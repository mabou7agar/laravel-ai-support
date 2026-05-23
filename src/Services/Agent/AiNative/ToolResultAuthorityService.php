<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

class ToolResultAuthorityService
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function sanitizeArguments(array $arguments, array $state): array
    {
        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $arguments[$key] = $this->sanitizeNested($value, $state, (string) $key);
                continue;
            }

            if ($this->isRelationIdField((string) $key) && !$this->isAuthorizedRelationValue((string) $key, $value, $state, (string) $key)) {
                unset($arguments[$key]);
            }
        }

        return $arguments;
    }

    /**
     * @param array<int|string, mixed> $value
     * @param array<string, mixed> $state
     * @return array<int|string, mixed>
     */
    private function sanitizeNested(array $value, array $state, string $path): array
    {
        foreach ($value as $key => $nested) {
            $nestedPath = $path . '.' . (string) $key;
            if (is_array($nested)) {
                $value[$key] = $this->sanitizeNested($nested, $state, $nestedPath);
                continue;
            }

            if ($this->isRelationIdField((string) $key) && !$this->isAuthorizedRelationValue((string) $key, $nested, $state, $nestedPath)) {
                unset($value[$key]);
            }
        }

        return $value;
    }

    private function isRelationIdField(string $field): bool
    {
        return $field === 'id' || str_ends_with($field, '_id');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isAuthorizedRelationValue(string $field, mixed $value, array $state, string $path): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $entity = $this->entityFromRelationPath($field, $path);
        if ($entity === null) {
            return false;
        }

        foreach ((array) ($state['tool_results'] ?? []) as $entry) {
            if (!is_array($entry) || ($entry['result']['success'] ?? false) !== true) {
                continue;
            }

            $tool = strtolower((string) ($entry['tool'] ?? ''));
            if ($entity !== null && !str_contains($tool, strtolower($entity))) {
                continue;
            }

            $data = is_array($entry['result']['data'] ?? null) ? $entry['result']['data'] : [];
            if ($this->containsSameId($data, $value)) {
                return true;
            }
        }

        return false;
    }

    private function entityFromRelationPath(string $field, string $path): ?string
    {
        if ($field !== 'id') {
            return substr($field, 0, -3);
        }

        $segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== '' && !is_numeric($segment)));
        $previous = $segments[count($segments) - 2] ?? null;
        if ($previous === null || $previous === '' || $previous === 'items') {
            return null;
        }

        return rtrim($previous, 's');
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function containsSameId(array $data, mixed $value): bool
    {
        foreach ($data as $key => $candidate) {
            if (is_array($candidate)) {
                if ($this->containsSameId($candidate, $value)) {
                    return true;
                }
                continue;
            }

            if (($key === 'id' || str_ends_with((string) $key, '_id')) && $this->same($candidate, $value)) {
                return true;
            }
        }

        return false;
    }

    private function same(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (string) (0 + $left) === (string) (0 + $right);
        }

        return (string) $left === (string) $right;
    }
}
