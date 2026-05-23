<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class AiNativeSuggestedToolArgumentResolver
{
    /**
     * @param array<string, mixed> $continuation
     * @return array<int, array<string, mixed>>
     */
    public function candidates(array $continuation): array
    {
        $result = (array) ($continuation['tool_result'] ?? []);
        $data = (array) ($result['data'] ?? []);
        $payload = (array) ($data['current_payload'] ?? []);
        $missingFields = array_values(array_filter((array) ($data['missing_fields'] ?? []), static fn (mixed $field): bool => is_string($field) && trim($field) !== ''));
        $candidates = [];

        foreach ($missingFields as $field) {
            $parent = $this->valueAtPath($payload, $this->parentPath((string) $field));
            if (!is_array($parent)) {
                continue;
            }

            $entity = $this->entityNameFromMissingField((string) $field);
            $value = $this->candidateSearchValue($parent, $entity);
            if ($value === null) {
                continue;
            }

            $candidates[] = [
                'value' => $value,
                'entity' => $entity,
                'record' => $parent,
                'missing_field' => (string) $field,
            ];
        }

        if ($candidates === []) {
            $candidates = $this->listRecordCandidates($payload);
        }

        if ($candidates === []) {
            $value = $this->candidateSearchValue($payload, null);
            if ($value !== null) {
                $candidates[] = [
                    'value' => $value,
                    'entity' => null,
                    'record' => $payload,
                    'missing_field' => null,
                ];
            }
        }

        return $this->uniqueCandidates($candidates);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function listRecordCandidates(array $payload): array
    {
        $candidates = [];

        foreach ($payload as $records) {
            if (!is_array($records) || !$this->isList($records)) {
                continue;
            }

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $value = $this->candidateSearchValue($record, null);
                if ($value === null) {
                    continue;
                }

                $candidates[] = [
                    'value' => $value,
                    'entity' => null,
                    'record' => $record,
                    'missing_field' => null,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $continuation
     * @return array<string, mixed>
     */
    public function argumentsFor(AgentTool $tool, array $candidate, array $continuation): array
    {
        $parameters = $tool->getParameters();
        $arguments = [];
        $result = (array) ($continuation['tool_result'] ?? []);
        $data = (array) ($result['data'] ?? []);
        $payload = (array) ($data['current_payload'] ?? []);
        $record = is_array($candidate['record'] ?? null) ? $candidate['record'] : [];

        foreach ($parameters as $name => $definition) {
            $definition = is_array($definition) ? $definition : [];
            $required = (bool) ($definition['required'] ?? false);
            $value = null;

            if ($name === 'query' || $name === 'search') {
                $value = $candidate['value'] ?? null;
            } elseif (array_key_exists($name, $record)) {
                $value = $record[$name];
            } elseif (array_key_exists($name, $payload)) {
                $value = $payload[$name];
            } elseif (($candidate['entity'] ?? null) !== null && array_key_exists((string) $candidate['entity'].'_'.$name, $record)) {
                $value = $record[(string) $candidate['entity'].'_'.$name];
            } elseif (($candidate['entity'] ?? null) !== null && array_key_exists((string) $candidate['entity'].'_'.$name, $payload)) {
                $value = $payload[(string) $candidate['entity'].'_'.$name];
            } elseif ($name === 'name') {
                $value = $candidate['value'] ?? null;
            } else {
                $value = $this->suffixMatchedValue($name, $record)
                    ?? $this->suffixMatchedValue($name, $payload);
            }

            if ($value !== null && $value !== '') {
                $arguments[$name] = $value;
            } elseif ($required) {
                return [];
            }
        }

        return $arguments;
    }

    public function isLookupMissResult(AgentTool $tool, string $toolName, ActionResult $result): bool
    {
        $data = is_array($result->data) ? $result->data : [];
        if (($data['found'] ?? null) !== false) {
            return false;
        }

        $kind = mb_strtolower(trim((string) $tool->getToolKind()));
        $capabilities = array_map('mb_strtolower', $tool->getCapabilities());

        return in_array($kind, ['lookup', 'search', 'find', 'read'], true)
            || array_intersect($capabilities, ['lookup', 'search', 'find', 'read']) !== []
            || preg_match('/^(find|lookup|search|get|fetch)_/i', $toolName) === 1;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function suffixMatchedValue(string $parameter, array $record): mixed
    {
        foreach ($record as $key => $value) {
            if (!is_string($key) || $value === null || $value === '') {
                continue;
            }

            if ($key === $parameter || str_ends_with($key, '_'.$parameter)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function uniqueCandidates(array $candidates): array
    {
        $unique = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $key = mb_strtolower((string) ($candidate['entity'] ?? '')).'|'.mb_strtolower((string) ($candidate['value'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function candidateSearchValue(array $record, ?string $entity): mixed
    {
        $preferred = ['name', 'title', 'label', 'email', 'number', 'code', 'slug'];
        if ($entity !== null && $entity !== '') {
            array_unshift(
                $preferred,
                $entity.'_name',
                $entity.'_title',
                $entity.'_label',
                $entity.'_email',
                $entity.'_number',
                $entity.'_code',
                $entity.'_slug'
            );
        }

        foreach ($preferred as $key) {
            if (($record[$key] ?? null) !== null && $record[$key] !== '') {
                return $record[$key];
            }
        }

        foreach ($record as $key => $value) {
            if (!is_string($key) || $value === null || $value === '') {
                continue;
            }

            foreach (['_name', '_title', '_label', '_email', '_number', '_code', '_slug'] as $suffix) {
                if (str_ends_with($key, $suffix)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function entityNameFromMissingField(string $path): ?string
    {
        $field = basename(str_replace('.', '/', $path));
        if (str_ends_with($field, '_id')) {
            return substr($field, 0, -3);
        }

        return null;
    }

    private function parentPath(string $path): string
    {
        $parts = explode('.', $path);
        array_pop($parts);

        return implode('.', $parts);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function valueAtPath(array $payload, string $path): mixed
    {
        if ($path === '') {
            return $payload;
        }

        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
