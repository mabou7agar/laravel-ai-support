<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Closure;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * A config-driven {@see ModelBackedUpsertTool} that needs no subclass — identity,
 * writable, and required fields plus optional defaults/scope resolvers are supplied at
 * construction. find-or-create (updateOrCreate by identity) + confirmation come from the
 * base. Built by {@see AiResource}.
 */
class GenericModelUpsertTool extends ModelBackedUpsertTool
{
    /** @var (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null */
    protected $defaultsResolver;

    /** @var (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null */
    protected $scopeResolver;

    protected ?string $confirmationMessage;

    /**
     * @param class-string             $model
     * @param array<int, string>       $identity
     * @param array<int, string>       $write
     * @param array<int, string>       $required
     * @param array<int, string>       $returns
     * @param array<string, mixed>     $defaults
     * @param (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null $defaultsResolver
     * @param (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null $scope
     */
    public function __construct(
        string $name,
        string $model,
        array $identity,
        array $write,
        array $required = [],
        array $returns = [],
        array $defaults = [],
        string $description = '',
        ?Closure $defaultsResolver = null,
        ?Closure $scope = null,
        ?string $confirmationMessage = null
    ) {
        $this->name = $name;
        $this->model = $model;
        $this->identity = array_values($identity);
        $this->write = array_values($write);
        $this->required = array_values($required !== [] ? $required : $write);
        if ($returns !== []) {
            $this->returns = array_values($returns);
        }
        $this->defaultValues = $defaults;
        $this->description = $description;
        $this->defaultsResolver = $defaultsResolver;
        $this->scopeResolver = $scope;
        $this->confirmationMessage = $confirmationMessage;
    }

    public function getConfirmationMessage(): ?string
    {
        return $this->confirmationMessage ?? parent::getConfirmationMessage();
    }

    protected function defaults(UnifiedActionContext $context, array $parameters): array
    {
        $base = $this->defaultValues;
        if ($this->defaultsResolver !== null) {
            $base = array_merge($base, (array) ($this->defaultsResolver)($context, $parameters));
        }

        return $base;
    }

    protected function scope(UnifiedActionContext $context, array $parameters): array
    {
        return $this->scopeResolver !== null
            ? (array) ($this->scopeResolver)($context, $parameters)
            : [];
    }
}
