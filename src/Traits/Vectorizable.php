<?php

namespace LaravelAIEngine\Traits;

trait Vectorizable
{
    /**
     * Define which fields should be vectorized
     * Override this in your model
     */
    public array $vectorizable = [];

    /**
     * Get content to be vectorized
     * Override this method for custom content generation
     */
    public function getVectorContent(): string
    {
        if (!empty($this->vectorizable)) {
            $content = [];
            
            foreach ($this->vectorizable as $field) {
                if (isset($this->$field)) {
                    $content[] = $this->$field;
                }
            }
            
            return implode(' ', $content);
        }

        // Default behavior: use common text fields
        $commonFields = ['title', 'name', 'content', 'description', 'body', 'text'];
        $content = [];
        
        foreach ($commonFields as $field) {
            if (isset($this->$field)) {
                $content[] = $this->$field;
            }
        }

        return implode(' ', $content);
    }

    /**
     * Get metadata for vector storage
     * Override this method for custom metadata
     */
    public function getVectorMetadata(): array
    {
        $metadata = [];

        // Add common metadata
        if (isset($this->user_id)) {
            $metadata['user_id'] = $this->user_id;
        }

        if (isset($this->status)) {
            $metadata['status'] = $this->status;
        }

        if (isset($this->category_id)) {
            $metadata['category_id'] = $this->category_id;
        }

        if (isset($this->type)) {
            $metadata['type'] = $this->type;
        }

        return $metadata;
    }

    /**
     * Check if model should be indexed
     * Override this method for custom logic
     */
    public function shouldBeIndexed(): bool
    {
        // Don't index if content is empty
        if (empty($this->getVectorContent())) {
            return false;
        }

        // Don't index drafts by default
        if (isset($this->status) && $this->status === 'draft') {
            return false;
        }

        // Don't index soft-deleted models
        if (method_exists($this, 'trashed') && $this->trashed()) {
            return false;
        }

        return true;
    }
}
