<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts\RAG;

interface FederatedCollectionProvider
{
    public function isEnabled(): bool;

    public function discoverCollections(): array;

    public function discoverCollectionsWithDescriptions(): array;
}
