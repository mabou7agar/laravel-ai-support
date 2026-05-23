<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Skills;

class SkillToolUseBuilder
{
    private ?string $listPath = null;

    private ?string $matchPayload = null;

    private ?string $matchResult = null;

    private ?string $matchParams = null;

    /**
     * @var array<int, string>
     */
    private array $confirmationTerms = [];

    public function __construct(
        private readonly string $tool,
        private readonly string $toolName,
        private readonly ?string $entity,
        private readonly array $resultFields,
    ) {
    }

    public function forList(string $path): self
    {
        $this->listPath = trim($path) ?: null;

        return $this;
    }

    public function matchBy(string $payloadField): self
    {
        $this->matchPayload = trim($payloadField) ?: null;

        return $this;
    }

    public function matchResult(string $resultField): self
    {
        $this->matchResult = trim($resultField) ?: null;

        return $this;
    }

    public function matchParams(string $parameter): self
    {
        $this->matchParams = trim($parameter) ?: null;

        return $this;
    }

    /**
     * @param array<int, string> $terms
     */
    public function confirmTerms(array $terms): self
    {
        $this->confirmationTerms = array_values(array_filter(array_map(
            static fn (mixed $term): string => mb_strtolower(trim((string) $term)),
            $terms
        )));

        return $this;
    }

    public function tool(): string
    {
        return $this->tool;
    }

    public function toolName(): string
    {
        return $this->toolName;
    }

    public function entity(): ?string
    {
        return $this->entity;
    }

    /**
     * @return array<int, string>
     */
    public function confirmationTerms(): array
    {
        return $this->confirmationTerms;
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function mapping(array $target): array
    {
        if ($this->entity === null || $this->resultFields === []) {
            return [];
        }

        $mapping = [];
        $fields = $this->fieldMapping($this->topLevelFields($target), $this->entity);
        if ($fields !== []) {
            $mapping['fields'] = $fields;
        }

        $listMapping = $this->listMapping($target);
        if ($listMapping !== []) {
            $mapping['lists'] = [$listMapping];
        }

        return $mapping;
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function listMapping(array $target): array
    {
        $list = $this->configuredList($target) ?? $this->inferList($target);
        if ($list === null) {
            return [];
        }

        [$path, $template] = $list;
        $fields = $this->fieldMapping($template, $this->entity ?? '');
        if ($fields === []) {
            return [];
        }

        $payloadField = $this->matchPayload ?: $this->firstEntityField($template, $this->entity ?? '', ['name', 'title']);
        if ($payloadField === null) {
            return [];
        }

        return [
            'path' => $path,
            'match' => [
                'payload' => $payloadField,
                'result' => $this->matchResult ?: $this->firstExistingResultField(['name', 'title']) ?: $payloadField,
                'params' => $this->matchParams ?: $this->defaultParameterName(),
            ],
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @return array{string, array<string, mixed>}|null
     */
    private function configuredList(array $target): ?array
    {
        if ($this->listPath === null) {
            return null;
        }

        $value = data_get($target, $this->listPath);
        if (!is_array($value) || !array_is_list($value) || !is_array($value[0] ?? null)) {
            return null;
        }

        return [$this->listPath, $value[0]];
    }

    /**
     * @param array<string, mixed> $target
     * @return array{string, array<string, mixed>}|null
     */
    private function inferList(array $target): ?array
    {
        foreach ($target as $path => $value) {
            if (!is_array($value) || !array_is_list($value) || !is_array($value[0] ?? null)) {
                continue;
            }

            if ($this->firstEntityField($value[0], $this->entity ?? '', ['name', 'title']) !== null) {
                return [(string) $path, $value[0]];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string|array<int, string>>
     */
    private function fieldMapping(array $fields, string $entity): array
    {
        $mapping = [];
        foreach (array_keys($fields) as $field) {
            if (!is_string($field)) {
                continue;
            }

            $source = $this->sourceForTargetField($field, $entity);
            if ($source !== null) {
                $mapping[$field] = $source;
            }
        }

        return $mapping;
    }

    /**
     * @return array<string, mixed>
     */
    private function topLevelFields(array $target): array
    {
        return array_filter(
            $target,
            static fn (mixed $value): bool => !is_array($value) || !array_is_list($value)
        );
    }

    private function sourceForTargetField(string $field, string $entity): string|array|null
    {
        if ($field === $entity . '_id' && $this->hasResultField('id')) {
            return 'id';
        }

        $suffix = str_starts_with($field, $entity . '_')
            ? substr($field, strlen($entity) + 1)
            : $field;

        if ($this->hasResultField($suffix)) {
            return $suffix;
        }

        if (str_ends_with($suffix, 'price')) {
            $prices = array_values(array_filter(
                ['sale_price', 'price', 'unit_price', 'amount'],
                fn (string $candidate): bool => $this->hasResultField($candidate)
            ));

            return $prices !== [] ? $prices : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, string> $suffixes
     */
    private function firstEntityField(array $fields, string $entity, array $suffixes): ?string
    {
        foreach ($suffixes as $suffix) {
            $field = $entity . '_' . $suffix;
            if (array_key_exists($field, $fields)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $fields
     */
    private function firstExistingResultField(array $fields): ?string
    {
        foreach ($fields as $field) {
            if ($this->hasResultField($field)) {
                return $field;
            }
        }

        return null;
    }

    private function defaultParameterName(): string
    {
        return str_starts_with($this->toolName, 'create_') || str_starts_with($this->toolName, 'upsert_')
            ? 'name'
            : 'query';
    }

    private function hasResultField(string $field): bool
    {
        return in_array($field, $this->resultFields, true);
    }
}
