<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Models\AIVectorStore;

class VectorStoreRepository
{
    public function available(): bool
    {
        return Schema::hasTable('ai_vector_stores')
            && Schema::hasTable('ai_vector_store_documents');
    }

    public function create(string $storeId, string $name, array $metadata = []): AIVectorStore
    {
        return AIVectorStore::query()->create([
            'store_id' => $storeId,
            'name' => $name,
            'metadata' => $metadata,
        ]);
    }

    public function findByStoreId(string $storeId): ?AIVectorStore
    {
        return AIVectorStore::query()
            ->with('documents')
            ->where('store_id', $storeId)
            ->first();
    }

    public function addDocument(string $storeId, Document $document): AIVectorStore
    {
        $store = AIVectorStore::query()
            ->where('store_id', $storeId)
            ->firstOrFail();

        $store->documents()->updateOrCreate(
            ['document_id' => $document->id()],
            [
                'source' => $document->source,
                'disk' => $document->disk,
                'metadata' => $document->metadata,
            ]
        );

        return $store->fresh('documents');
    }
}
