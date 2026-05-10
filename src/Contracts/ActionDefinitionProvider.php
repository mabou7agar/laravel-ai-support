<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

interface ActionDefinitionProvider
{
    /**
     * @return iterable<string|int, array<string, mixed>>
     */
    public function actions(): iterable;
}
