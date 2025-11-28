<?php

namespace LaravelAIEngine\Traits;

/**
 * RAGgable Trait
 * 
 * Marker trait for models that should be included in RAG searches.
 * Simply add this trait to any Vectorizable model to enable it for RAG.
 * 
 * The Vectorizable trait already handles:
 * - Content extraction (toVectorContent)
 * - Metadata
 * - Permissions
 * - Indexing
 * 
 * This trait just marks the model as RAG-enabled.
 */
trait RAGgable
{
    /**
     * Get the priority for this model in RAG searches
     * Higher priority = searched first
     * 
     * @return int Priority (0-100, default: 50)
     */
    public function getRAGPriority(): int
    {
        return property_exists($this, 'ragPriority') ? $this->ragPriority : 50;
    }

    /**
     * Determine if this model should be included in RAG for a given query
     * Override this for custom filtering logic
     * 
     * @param string $query The user's query
     * @param array $context Additional context
     * @return bool
     */
    public function shouldIncludeInRAG(string $query, array $context = []): bool
    {
        return true;
    }
}
