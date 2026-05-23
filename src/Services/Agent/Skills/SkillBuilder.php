<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Skills;

use Illuminate\Support\Str;
use LaravelAIEngine\Services\Agent\AgentManifestService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class SkillBuilder
{
    private SkillTargetBuilder $target;

    /**
     * @var array<int, SkillToolUseBuilder>
     */
    private array $uses = [];

    private ?SkillToolUseBuilder $final = null;

    private ?string $prompt = null;

    /**
     * @var array<int, SkillRelationBuilder>
     */
    private array $relations = [];

    public function __construct()
    {
        $this->target = new SkillTargetBuilder();
    }

    public function target(): SkillTargetBuilder
    {
        return $this->target;
    }

    /**
     * @param class-string<AgentTool>|string $tool
     */
    public function use(string $tool): SkillToolUseBuilder
    {
        $builder = $this->toolUse($tool);
        $this->uses[] = $builder;

        return $builder;
    }

    /**
     * @param class-string<AgentTool>|string $tool
     */
    public function final(string $tool): SkillToolUseBuilder
    {
        $builder = $this->use($tool);
        $this->final = $builder;

        return $builder;
    }

    /**
     * @return array<int, string>
     */
    public function toolNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (SkillToolUseBuilder $tool): string => $tool->toolName(),
            $this->uses
        )));
    }

    public function finalToolName(): ?string
    {
        return $this->final?->toolName();
    }

    public function prompt(string $prompt): self
    {
        $this->prompt = trim($prompt) ?: null;

        return $this;
    }

    public function instructions(string $prompt): self
    {
        return $this->prompt($prompt);
    }

    public function promptText(): ?string
    {
        return $this->prompt;
    }

    public function relation(string $name): SkillRelationBuilder
    {
        $builder = new SkillRelationBuilder(
            name: trim($name),
            normalizeTool: fn (string $tool): ?string => $this->normalizeToolName($tool)
        );

        $this->relations[] = $builder;

        return $builder;
    }

    /**
     * @return array<string, mixed>
     */
    public function targetJson(): array
    {
        return $this->target->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $metadata = [];
        $target = $this->targetJson();

        if ($this->prompt !== null) {
            $metadata['prompt'] = $this->prompt;
        }

        $relations = array_values(array_filter(array_map(
            static fn (SkillRelationBuilder $relation): array => $relation->toArray(),
            $this->relations
        )));
        $relations = $this->mergeRelations($relations, $this->toolDeclaredRelations($relations));
        $relations = $this->mergeRelations($relations, $this->inferredRelations($target, $relations));
        if ($relations !== []) {
            $metadata['relations'] = $relations;
        }

        foreach ($this->uses as $tool) {
            $mapping = $tool->mapping($target);
            if ($mapping !== []) {
                $metadata['result_payload_mappings'][$tool->toolName()] = $mapping;
            }
        }

        if ($this->final !== null) {
            $metadata['final_tool'] = $this->final->toolName();
            if ($this->final->confirmationTerms() !== []) {
                $metadata['final_confirmation_terms'] = $this->final->confirmationTerms();
            }
        }

        return $metadata;
    }

    /**
     * @param class-string<AgentTool>|string $tool
     */
    private function toolUse(string $tool): SkillToolUseBuilder
    {
        $name = $this->normalizeToolName($tool) ?: $tool;
        $resultFields = $this->resultFields($tool);

        return new SkillToolUseBuilder(
            tool: $tool,
            toolName: $name,
            entity: $this->entityFromToolName($name),
            resultFields: $resultFields
        );
    }

    /**
     * @param class-string<AgentTool>|string $tool
     */
    private function normalizeToolName(string $tool): ?string
    {
        $this->ensureToolClassLoaded($tool);
        if (class_exists($tool) && is_subclass_of($tool, AgentTool::class)) {
            try {
                $instance = app($tool);
                if ($instance instanceof AgentTool) {
                    $name = trim($instance->getName());

                    return $name !== '' ? $name : null;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        $tool = trim($tool);

        return $tool !== '' ? $tool : null;
    }

    /**
     * @param class-string<AgentTool>|string $tool
     * @return array<int, string>
     */
    private function resultFields(string $tool): array
    {
        $instance = $this->toolInstance($tool);
        if (!$instance instanceof AgentTool) {
            return [];
        }

        $schema = $instance->getResultSchema();

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $key, mixed $value): string => is_string($key) ? $key : (string) $value,
            array_keys($schema),
            array_values($schema)
        ))));
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @return array<int, array<string, mixed>>
     */
    private function toolDeclaredRelations(array $existing): array
    {
        $relations = [];
        foreach ($this->uses as $toolUse) {
            $instance = $this->toolInstance($toolUse->tool());
            if (!$instance instanceof AgentTool) {
                continue;
            }

            foreach ($instance->getRelations() as $relation) {
                if (!is_array($relation)) {
                    continue;
                }

                $normalized = $this->normalizeRelation($relation, $toolUse, 'tool');
                if ($normalized !== []) {
                    $relations[] = $normalized;
                }
            }
        }

        return $relations;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, array<string, mixed>> $existing
     * @return array<int, array<string, mixed>>
     */
    private function inferredRelations(array $target, array $existing): array
    {
        $targets = $this->relationTargets($target);
        if ($targets === []) {
            return [];
        }

        $tools = $this->toolDescriptors();
        $relations = [];

        foreach ($targets as $targetField) {
            $name = $this->relationNameFromField($targetField['field']);
            if ($name === null || $this->hasRelation($existing, $targetField['field'], $name)) {
                continue;
            }

            $lookup = $this->firstToolForEntity($tools, $name, ['lookup', 'read']);
            $create = $this->firstToolForEntity($tools, $name, ['create']);
            if ($lookup === null && $create === null) {
                continue;
            }

            $relation = [
                'name' => $name,
                'field' => $targetField['field'],
                'lookup_tool' => $lookup['name'] ?? null,
                'create_tool' => $create['name'] ?? null,
                'lookup_fields' => $this->lookupFieldsForRelation($targetField, $target),
                'create_required_fields' => isset($create['tool']) && $create['tool'] instanceof AgentTool
                    ? $this->requiredParameters($create['tool'])
                    : [],
                'safe_create' => $create !== null,
                'source' => 'inferred',
            ];

            $relations[] = array_filter(
                $relation,
                static fn (mixed $value): bool => $value !== null && $value !== []
            );
        }

        return $relations;
    }

    /**
     * @return array<int, array{name:string, entity:string, role:string, tool:?AgentTool}>
     */
    private function toolDescriptors(): array
    {
        $descriptors = [];
        foreach ($this->uses as $toolUse) {
            $instance = $this->toolInstance($toolUse->tool());
            $entity = $instance instanceof AgentTool
                ? trim((string) ($instance->getEntityType() ?? ''))
                : '';
            $entity = $entity !== '' ? $entity : (string) ($toolUse->entity() ?? '');
            $role = $this->toolRole($toolUse->toolName(), $instance);

            if ($entity === '' || $role === '') {
                continue;
            }

            $descriptors[] = [
                'name' => $toolUse->toolName(),
                'entity' => Str::snake(Str::singular($entity)),
                'role' => $role,
                'tool' => $instance,
            ];
        }

        return $descriptors;
    }

    /**
     * @param array<int, array{name:string, entity:string, role:string, tool:?AgentTool}> $tools
     * @param array<int, string> $roles
     * @return array{name:string, entity:string, role:string, tool:?AgentTool}|null
     */
    private function firstToolForEntity(array $tools, string $entity, array $roles): ?array
    {
        $entity = Str::snake(Str::singular($entity));
        foreach ($tools as $tool) {
            if ($tool['entity'] === $entity && in_array($tool['role'], $roles, true)) {
                return $tool;
            }
        }

        return null;
    }

    private function toolRole(string $toolName, ?AgentTool $tool): string
    {
        $name = mb_strtolower(trim($toolName));
        $kind = $tool instanceof AgentTool ? mb_strtolower(trim((string) $tool->getToolKind())) : '';
        $capabilities = $tool instanceof AgentTool ? array_map('mb_strtolower', $tool->getCapabilities()) : [];

        if (in_array($kind, ['lookup', 'search', 'find', 'read'], true)
            || array_intersect($capabilities, ['lookup', 'search', 'find', 'read']) !== []
            || preg_match('/^(find|lookup|search|get|fetch)_/i', $name) === 1) {
            return 'lookup';
        }

        if (in_array($kind, ['create', 'upsert', 'write'], true)
            || array_intersect($capabilities, ['create', 'upsert']) !== []
            || preg_match('/^(create|upsert|ensure)_/i', $name) === 1) {
            return 'create';
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $base
     * @param array<int, array<string, mixed>> $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeRelations(array $base, array $incoming): array
    {
        foreach ($incoming as $relation) {
            $field = (string) ($relation['field'] ?? '');
            $name = (string) ($relation['name'] ?? '');
            if ($this->hasRelation($base, $field, $name)) {
                continue;
            }

            $base[] = $relation;
        }

        return array_values($base);
    }

    /**
     * @param array<int, array<string, mixed>> $relations
     */
    private function hasRelation(array $relations, string $field, string $name): bool
    {
        foreach ($relations as $relation) {
            if ($field !== '' && (string) ($relation['field'] ?? '') === $field) {
                return true;
            }

            if ($name !== '' && (string) ($relation['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $relation
     * @return array<string, mixed>
     */
    private function normalizeRelation(array $relation, SkillToolUseBuilder $toolUse, string $source): array
    {
        $name = trim((string) ($relation['name'] ?? $relation['relation'] ?? $toolUse->entity() ?? ''));
        $field = trim((string) ($relation['field'] ?? ''));
        if ($name === '' && $field !== '') {
            $name = $this->relationNameFromField($field) ?? '';
        }

        if ($name === '' && $field === '') {
            return [];
        }

        $role = trim((string) ($relation['role'] ?? ''));

        return array_filter([
            'name' => $name,
            'field' => $field,
            'lookup_tool' => ($relation['lookup_tool'] ?? null) ?: ($role === 'lookup' ? $toolUse->toolName() : null),
            'create_tool' => ($relation['create_tool'] ?? null) ?: ($role === 'create' ? $toolUse->toolName() : null),
            'lookup_fields' => array_values((array) ($relation['lookup_fields'] ?? [])),
            'create_required_fields' => array_values((array) ($relation['create_required_fields'] ?? $relation['required_fields'] ?? [])),
            'safe_create' => $relation['safe_create'] ?? null,
            'source' => $source,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param array<string, mixed> $target
     * @return array<int, array{field:string, path:string, local:string}>
     */
    private function relationTargets(array $target, string $prefix = ''): array
    {
        $targets = [];
        foreach ($target as $field => $value) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $path = $prefix !== '' ? "{$prefix}.{$field}" : $field;
            if (is_array($value) && array_is_list($value) && is_array($value[0] ?? null)) {
                $targets = array_merge($targets, $this->relationTargets($value[0], $path . '.*'));
                continue;
            }

            if ($this->relationNameFromField($path) !== null) {
                $targets[] = [
                    'field' => $path,
                    'path' => $prefix,
                    'local' => $field,
                ];
            }
        }

        return $targets;
    }

    private function relationNameFromField(string $field): ?string
    {
        $field = Str::snake(trim($field));
        if ($field === '') {
            return null;
        }

        $local = str_contains($field, '.') ? (string) Str::afterLast($field, '.') : $field;
        if (in_array($local, ['id', 'uuid'], true)) {
            return null;
        }

        foreach (['_id', '_uuid'] as $suffix) {
            if (str_ends_with($local, $suffix)) {
                return Str::singular(substr($local, 0, -strlen($suffix)));
            }
        }

        return null;
    }

    /**
     * @param array{field:string, path:string, local:string} $targetField
     * @param array<string, mixed> $target
     * @return array<int, string>
     */
    private function lookupFieldsForRelation(array $targetField, array $target): array
    {
        $name = $this->relationNameFromField($targetField['field']);
        if ($name === null) {
            return [];
        }

        $siblings = $this->targetSiblings($target, $targetField['path']);
        $fields = [];
        foreach (array_keys($siblings) as $field) {
            if (!is_string($field) || $field === $targetField['local']) {
                continue;
            }

            if (str_starts_with(Str::snake($field), $name . '_')) {
                $fields[] = $targetField['path'] !== ''
                    ? $targetField['path'] . '.' . $field
                    : $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function targetSiblings(array $target, string $path): array
    {
        if ($path === '') {
            return $target;
        }

        $segments = array_values(array_filter(explode('.', str_replace('.*', '', $path))));
        $current = $target;
        foreach ($segments as $segment) {
            $value = $current[$segment] ?? null;
            if (is_array($value) && array_is_list($value) && is_array($value[0] ?? null)) {
                $current = $value[0];
                continue;
            }

            return [];
        }

        return is_array($current) ? $current : [];
    }

    /**
     * @return array<int, string>
     */
    private function requiredParameters(AgentTool $tool): array
    {
        $parameters = $tool->getParameters();
        $required = [];
        foreach ($parameters as $name => $definition) {
            if (is_string($name) && is_array($definition) && ($definition['required'] ?? false) === true) {
                $required[] = $name;
            }
        }

        return $required;
    }

    private function toolInstance(string $tool): ?AgentTool
    {
        $this->ensureToolClassLoaded($tool);
        if (!class_exists($tool) || !is_subclass_of($tool, AgentTool::class)) {
            return null;
        }

        try {
            $instance = app($tool);
        } catch (\Throwable) {
            return null;
        }

        return $instance instanceof AgentTool ? $instance : null;
    }

    private function ensureToolClassLoaded(string $tool): void
    {
        if (class_exists($tool)) {
            return;
        }

        try {
            app(AgentManifestService::class)->tools();
        } catch (\Throwable) {
        }
    }

    private function entityFromToolName(string $name): ?string
    {
        $name = Str::snake(trim($name));
        if ($name === '') {
            return null;
        }

        $name = preg_replace('/^(find|lookup|search|create|upsert|update|get|fetch|ensure|resolve)_+/', '', $name) ?: $name;
        $name = preg_replace('/_tool$/', '', $name) ?: $name;
        $parts = array_values(array_filter(explode('_', $name)));
        if ($parts === []) {
            return null;
        }

        return Str::singular((string) end($parts));
    }
}
