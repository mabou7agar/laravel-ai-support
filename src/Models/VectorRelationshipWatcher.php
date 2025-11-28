<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VectorRelationshipWatcher extends Model
{
    protected $fillable = [
        'vector_configuration_id',
        'parent_model',
        'related_model',
        'relationship_name',
        'relationship_type',
        'relationship_path',
        'depth',
        'watch_fields',
        'on_change_action',
        'enabled',
    ];

    protected $casts = [
        'watch_fields' => 'array',
        'depth' => 'integer',
        'enabled' => 'boolean',
    ];

    /**
     * Get the configuration for this watcher
     */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(VectorConfiguration::class, 'vector_configuration_id');
    }

    /**
     * Check if a field is being watched
     */
    public function isWatchingField(string $field): bool
    {
        if (empty($this->watch_fields)) {
            return true; // Watch all fields if none specified
        }

        return in_array($field, $this->watch_fields);
    }

    /**
     * Get the inverse relationship name
     * Simple heuristic: attachments -> attachment
     */
    public function getInverseRelationshipName(): string
    {
        $name = $this->relationship_name;
        
        // Remove trailing 's' for simple plurals
        if (str_ends_with($name, 's')) {
            return rtrim($name, 's');
        }
        
        return $name;
    }

    /**
     * Check if should reindex parent on change
     */
    public function shouldReindexParent(): bool
    {
        return $this->on_change_action === 'reindex_parent';
    }

    /**
     * Scope to get enabled watchers
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope for specific parent model
     */
    public function scopeForParentModel($query, string $parentModel)
    {
        return $query->where('parent_model', $parentModel);
    }

    /**
     * Scope for specific related model
     */
    public function scopeForRelatedModel($query, string $relatedModel)
    {
        return $query->where('related_model', $relatedModel);
    }

    /**
     * Scope for specific depth
     */
    public function scopeAtDepth($query, int $depth)
    {
        return $query->where('depth', $depth);
    }

    /**
     * Scope for shallow relationships (depth 1)
     */
    public function scopeShallow($query)
    {
        return $query->where('depth', 1);
    }

    /**
     * Scope for deep relationships (depth > 1)
     */
    public function scopeDeep($query)
    {
        return $query->where('depth', '>', 1);
    }
}
