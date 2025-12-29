<?php

namespace LaravelAIEngine\Traits;

use Illuminate\Support\Str;

/**
 * Simplified AI Configuration Trait
 * 
 * Provides convention-based AI configuration with minimal setup.
 * Automatically discovers fields from fillable/guarded and generates sensible defaults.
 */
trait HasSimpleAIConfig
{
    /**
     * Get AI configuration with auto-discovery
     */
    public function initializeAI(): array
    {
        $modelName = class_basename($this);
        
        return array_merge([
            'model_name' => $modelName,
            'description' => $this->getAIDescription(),
            'actions' => $this->getAIActions(),
            'fields' => $this->discoverAIFields(),
        ], $this->customAIConfig());
    }
    
    /**
     * Get AI description (override for custom description)
     */
    protected function getAIDescription(): string
    {
        return property_exists($this, 'aiDescription') 
            ? $this->aiDescription 
            : 'Create or manage ' . Str::plural(Str::lower(class_basename($this)));
    }
    
    /**
     * Get supported actions (override for custom actions)
     */
    protected function getAIActions(): array
    {
        return property_exists($this, 'aiActions') 
            ? $this->aiActions 
            : ['create', 'update', 'delete'];
    }
    
    /**
     * Auto-discover fields from model
     */
    protected function discoverAIFields(): array
    {
        $fields = [];
        $fillable = $this->getFillable();
        $guarded = $this->getGuarded();
        
        // Get all fillable fields or all fields if guarded is empty
        $modelFields = !empty($fillable) ? $fillable : $this->getAllModelFields();
        
        // Remove guarded fields
        if (!in_array('*', $guarded)) {
            $modelFields = array_diff($modelFields, $guarded);
        }
        
        // Remove common system fields
        $systemFields = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'workspace_id', 'workspace'];
        $modelFields = array_diff($modelFields, $systemFields);
        
        foreach ($modelFields as $field) {
            $fields[$field] = $this->inferFieldConfig($field);
        }
        
        // Merge with custom field configurations
        if (method_exists($this, 'customAIFields')) {
            $fields = array_merge($fields, $this->customAIFields());
        }
        
        return $fields;
    }
    
    /**
     * Infer field configuration from field name and model properties
     */
    protected function inferFieldConfig(string $field): array
    {
        $config = [
            'type' => $this->inferFieldType($field),
            'description' => $this->generateFieldDescription($field),
            'required' => $this->isFieldRequired($field),
        ];
        
        // Add defaults for common patterns
        if (str_ends_with($field, '_date') || str_ends_with($field, '_at')) {
            $config['default'] = 'today';
        }
        
        if (str_ends_with($field, '_id')) {
            $config['type'] = 'relationship';
            $config['relationship'] = $this->inferRelationship($field);
        }
        
        return $config;
    }
    
    /**
     * Infer field type from name
     */
    protected function inferFieldType(string $field): string
    {
        // Check casts first
        if (property_exists($this, 'casts') && isset($this->casts[$field])) {
            $cast = $this->casts[$field];
            if (in_array($cast, ['int', 'integer'])) return 'integer';
            if (in_array($cast, ['float', 'double', 'decimal'])) return 'number';
            if (in_array($cast, ['bool', 'boolean'])) return 'boolean';
            if (in_array($cast, ['array', 'json'])) return 'array';
            if (in_array($cast, ['date', 'datetime'])) return 'date';
        }
        
        // Infer from field name patterns
        if (str_ends_with($field, '_id')) return 'integer';
        if (str_ends_with($field, '_at') || str_ends_with($field, '_date')) return 'date';
        if (str_ends_with($field, '_count') || str_ends_with($field, 'quantity')) return 'integer';
        if (str_contains($field, 'price') || str_contains($field, 'amount') || str_contains($field, 'total')) return 'number';
        if (str_contains($field, 'is_') || str_starts_with($field, 'has_')) return 'boolean';
        if (str_contains($field, 'email')) return 'email';
        if (str_contains($field, 'phone')) return 'phone';
        if (str_contains($field, 'url') || str_contains($field, 'link')) return 'url';
        if (in_array($field, ['description', 'notes', 'content', 'body'])) return 'text';
        
        return 'string';
    }
    
    /**
     * Generate human-readable field description
     */
    protected function generateFieldDescription(string $field): string
    {
        return Str::title(str_replace('_', ' ', $field));
    }
    
    /**
     * Check if field is required (can be overridden)
     */
    protected function isFieldRequired(string $field): bool
    {
        // Check if there's a rules method
        if (method_exists($this, 'rules')) {
            $rules = $this->rules();
            if (isset($rules[$field])) {
                $fieldRules = is_array($rules[$field]) ? $rules[$field] : explode('|', $rules[$field]);
                return in_array('required', $fieldRules);
            }
        }
        
        // Common required fields
        return in_array($field, ['name', 'title', 'email']);
    }
    
    /**
     * Infer relationship from field name
     */
    protected function inferRelationship(string $field): ?array
    {
        $relationName = Str::camel(str_replace('_id', '', $field));
        
        if (method_exists($this, $relationName)) {
            try {
                $relation = $this->$relationName();
                $relatedModel = get_class($relation->getRelated());
                
                return [
                    'model' => $relatedModel,
                    'search_field' => 'name',
                ];
            } catch (\Exception $e) {
                return null;
            }
        }
        
        return null;
    }
    
    /**
     * Get all model fields from database
     */
    protected function getAllModelFields(): array
    {
        try {
            $table = $this->getTable();
            $columns = \Schema::getColumnListing($table);
            return $columns;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Override this method to add custom configuration
     */
    protected function customAIConfig(): array
    {
        return [];
    }
}
