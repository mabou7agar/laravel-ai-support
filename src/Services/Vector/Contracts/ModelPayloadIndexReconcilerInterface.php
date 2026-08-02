<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Vector\Contracts;

/**
 * Optional capability for vector drivers whose payload indexes are schema-aware.
 *
 * VectorSearchService invokes this before model-backed retrieval without leaking
 * internal model context into provider filters. Drivers that do not require
 * payload-index reconciliation can continue implementing VectorDriverInterface
 * only.
 */
interface ModelPayloadIndexReconcilerInterface
{
    public function reconcileModelPayloadIndexes(string $collection, string $modelClass): void;
}
