<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

interface AccessScopeProviderInterface
{
    /**
     * Return access and ownership metadata used for graph publishing and retrieval scoping.
     *
     * @return array<string, mixed>
     */
    public function getAccessScope(): array;
}
