<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

interface GraphRelationProviderInterface
{
    /**
     * Return graph relationship descriptors for publishing into a shared graph store.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGraphRelations(): array;
}
