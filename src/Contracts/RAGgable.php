<?php

namespace LaravelAIEngine\Contracts;

/**
 * RAGgable Interface
 * 
 * Marker interface for models that should be included in RAG searches.
 * Models implementing this interface will be automatically discovered
 * and used as RAG collections.
 */
interface RAGgable
{
    /**
     * Get the priority for this model in RAG searches
     * Higher priority models are searched first
     * 
     * @return int Priority (0-100, default: 50)
     */
    public function getRAGPriority(): int;

    /**
     * Determine if this model should be included in RAG for a given query
     * Allows dynamic filtering based on query context
     * 
     * @param string $query The user's query
     * @param array $context Additional context
     * @return bool
     */
    public function shouldIncludeInRAG(string $query, array $context = []): bool;
}
