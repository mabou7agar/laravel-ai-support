<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Closure;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * A config-driven {@see ModelBackedLookupTool} that needs no subclass — the model,
 * searchable columns, returned columns, and an optional scope resolver are supplied at
 * construction. Built (with {@see GenericModelUpsertTool}) by {@see AiResource} so a host
 * can expose an Eloquent model to the agent without hand-writing a tool class.
 */
class GenericModelLookupTool extends ModelBackedLookupTool
{
    /** @var (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null */
    protected $scopeResolver;

    /**
     * @param class-string                 $model
     * @param array<int, string>           $search
     * @param array<int, string>           $returns
     * @param array<int, string>           $missingFields
     * @param (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null $scope
     */
    public function __construct(
        string $name,
        string $model,
        array $search,
        array $returns = [],
        string $description = '',
        array $missingFields = [],
        ?Closure $scope = null
    ) {
        $this->name = $name;
        $this->model = $model;
        $this->search = array_values($search);
        if ($returns !== []) {
            $this->returns = array_values($returns);
        }
        $this->description = $description;
        $this->missingFields = array_values($missingFields);
        $this->scopeResolver = $scope;
    }

    protected function scope(UnifiedActionContext $context, array $parameters): array
    {
        return $this->scopeResolver !== null
            ? (array) ($this->scopeResolver)($context, $parameters)
            : [];
    }
}
