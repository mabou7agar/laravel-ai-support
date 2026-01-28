<?php

namespace LaravelAIEngine\Contracts;

/**
 * Interface for models that support vector search
 * 
 * Models using the Vectorizable trait should implement this interface
 * to ensure all required methods are properly defined.
 * 
 * Required methods:
 * - getVectorContent(): Returns the text content to be embedded
 * - getVectorMetadata(): Returns metadata for filtering in vector DB
 * 
 * Optional but recommended:
 * - toRAGContent(): Returns formatted content for RAG responses
 * - getVectorCollectionName(): Custom collection name (default: table name)
 * - shouldBeIndexed(): Whether this record should be indexed
 * 
 * Usage:
 * ```php
 * use LaravelAIEngine\Traits\Vectorizable;
 * use LaravelAIEngine\Contracts\VectorizableInterface;
 * 
 * class Product extends Model implements VectorizableInterface
 * {
 *     use Vectorizable;
 * 
 *     public function getVectorContent(): string
 *     {
 *         return "{$this->name}\n{$this->description}";
 *     }
 * 
 *     public function getVectorMetadata(): array
 *     {
 *         return [
 *             'category_id' => $this->category_id,
 *             'price' => $this->price,
 *             'in_stock' => $this->in_stock,
 *         ];
 *     }
 * }
 * ```
 */
interface VectorizableInterface
{
    /**
     * Get the text content to be vectorized/embedded
     * 
     * This is the primary content that will be converted to embeddings
     * for semantic search. Should include all searchable text fields.
     * 
     * Best practices:
     * - Include meaningful text (name, description, content)
     * - Add context (type names, category names, related entity names)
     * - Format consistently for better embedding quality
     * - Avoid including sensitive data (passwords, tokens)
     * 
     * @return string The content to embed
     */
    public function getVectorContent(): string;

    /**
     * Get metadata for filtering in vector database
     * 
     * Metadata is stored alongside vectors and used for filtering
     * search results. Include fields commonly used for filtering.
     * 
     * Best practices:
     * - Include user/tenant identifiers for access control
     * - Include status/type fields for filtering
     * - Include foreign keys for relationship filtering
     * - Keep values simple (int, string, bool) - avoid nested arrays
     * 
     * Common fields to include:
     * - user_id / created_by / owner_id (for user scoping)
     * - workspace_id / tenant_id (for multi-tenancy)
     * - status / is_active / is_enabled
     * - type / category_id
     * - created_at timestamp
     * 
     * @return array<string, mixed> Key-value pairs for filtering
     */
    public function getVectorMetadata(): array;

    /**
     * Format content for RAG (Retrieval Augmented Generation) display
     * 
     * This is used when showing search results to users or providing
     * context to AI. Should be human-readable and well-formatted.
     * 
     * Best practices:
     * - Use markdown formatting
     * - Include key identifying information
     * - Keep it concise but informative
     * - Include relevant relationships
     * 
     * @return string Formatted content for display
     */
    public function toRAGContent(): string;

    /**
     * Get the vector collection name for this model
     * 
     * Override to use a custom collection name instead of table name.
     * The configured prefix (default: vec_) will be applied automatically.
     * 
     * @return string Collection name
     */
    public function getVectorCollectionName(): string;

    /**
     * Determine if this record should be indexed
     * 
     * Override to skip indexing certain records (e.g., drafts, deleted,
     * records that are too large, or records missing required data).
     * 
     * @return bool True if should be indexed, false to skip
     */
    public function shouldBeIndexed(): bool;

    /**
     * Get custom Qdrant indexes for this model
     * 
     * Define which metadata fields should have indexes in Qdrant
     * for efficient filtering. Only fields returned by getVectorMetadata()
     * that need fast filtering should be indexed.
     * 
     * @return array<string, string> Field name => index type ('keyword', 'integer', 'float', 'bool')
     */
    public function getQdrantIndexes(): array;
}
