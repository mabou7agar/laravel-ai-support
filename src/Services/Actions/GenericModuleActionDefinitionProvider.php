<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use LaravelAIEngine\Contracts\ActionDefinitionProvider;

class GenericModuleActionDefinitionProvider implements ActionDefinitionProvider
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
