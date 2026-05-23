<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Skills;

use Closure;

class SkillRelationBuilder
{
    private ?string $field = null;

    private ?string $lookupTool = null;

    private ?string $createTool = null;

    /**
     * @var array<int, string>
     */
    private array $lookupFields = [];

    /**
     * @var array<int, string>
     */
    private array $createRequiredFields = [];

    private bool $safeCreate = true;

    /**
     * @param Closure(string):?string $normalizeTool
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $normalizeTool
    ) {
    }

    public function field(string $field): self
    {
        $this->field = trim($field) ?: null;

        return $this;
    }

    public function lookup(string $tool): self
    {
        $this->lookupTool = $this->normalizeTool($tool);

        return $this;
    }

    public function create(string $tool): self
    {
        $this->createTool = $this->normalizeTool($tool);

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function lookupFields(array $fields): self
    {
        $this->lookupFields = $this->stringList($fields);

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function createRequired(array $fields): self
    {
        $this->createRequiredFields = $this->stringList($fields);

        return $this;
    }

    public function safeCreate(bool $safe = true): self
    {
        $this->safeCreate = $safe;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'field' => $this->field,
            'lookup_tool' => $this->lookupTool,
            'create_tool' => $this->createTool,
            'lookup_fields' => $this->lookupFields,
            'create_required_fields' => $this->createRequiredFields,
            'safe_create' => $this->safeCreate,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    private function normalizeTool(string $tool): ?string
    {
        $normalize = $this->normalizeTool;

        return $normalize($tool);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        ))));
    }
}
