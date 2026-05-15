<?php

declare(strict_types=1);

namespace LaravelAIEngine\Traits\Concerns;

use LaravelAIEngine\DTOs\SearchDocument;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;

trait HasVectorSearchDocuments
{
    /**
     * Format content for RAG (Retrieval Augmented Generation) display
     * 
     * Override this method for custom formatting.
     * Default implementation returns the vector content.
     * 
     * @return string Formatted content for display
     */
    public function toRAGContent(): string
    {
        // Default: use vector content
        // Models should override this for better formatting
        return $this->getVectorContent();
    }

    /**
     * Build the canonical search document for this model.
     *
     * Override this in new code instead of relying on inferred fields().
     */
    public function toSearchDocument(): SearchDocument|array
    {
        return app(SearchDocumentBuilder::class)->buildFromModelDefaults($this);
    }

    /**
     * Return a sanitized object payload for retrieval responses and follow-up actions.
     *
     * Override this in new code instead of relying on inferred metadata().
     *
     * @return array<string, mixed>
     */
    public function toGraphObject(): array
    {
        return app(SearchDocumentBuilder::class)->buildFromModelDefaults($this)->object;
    }

    /**
     * Return graph relationship descriptors for shared graph publishing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGraphRelations(): array
    {
        return [];
    }

    /**
     * Return ownership and access metadata for graph publishing and retrieval scoping.
     *
     * @return array<string, mixed>
     */
    public function getAccessScope(): array
    {
        return app(SearchDocumentBuilder::class)->buildFromModelDefaults($this)->accessScope;
    }

    /**
     * Return compact list/summary text.
     */
    public function toRAGSummary(): string
    {
        return app(SearchDocumentBuilder::class)->buildFromModelDefaults($this)->ragSummary ?? $this->toRAGContent();
    }

    /**
     * Return detailed RAG text.
     */
    public function toRAGDetail(): string
    {
        return app(SearchDocumentBuilder::class)->buildFromModelDefaults($this)->ragDetail ?? $this->toRAGContent();
    }

    /**
     * Return list-preview text.
     */
    public function toRAGListPreview(?string $locale = null): string
    {
        return app(SearchDocumentBuilder::class)->buildFromModelDefaults($this)->listPreview ?? $this->toRAGSummary();
    }
}
