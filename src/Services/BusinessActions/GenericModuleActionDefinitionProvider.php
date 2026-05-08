<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\BusinessActions;

use LaravelAIEngine\Contracts\BusinessActionDefinitionProvider;

class GenericModuleActionDefinitionProvider implements BusinessActionDefinitionProvider
{
    public function __construct(private readonly GenericModuleActionService $actions)
    {
    }

    /**
     * @return iterable<string|int, array<string, mixed>>
     */
    public function actions(): iterable
    {
        return $this->actions->definitions();
    }
}
