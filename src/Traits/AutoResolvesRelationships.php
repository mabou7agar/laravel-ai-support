<?php

namespace LaravelAIEngine\Traits;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Automatic Relationship Resolution
 * 
 * Automatically resolves relationships from string values to IDs without manual handling.
 * Works with the fluent builder and HasAIActions trait.
 * 
 * Example:
 * - Input: ['name' => 'John Doe', 'category' => 'Electronics']
 * - Output: ['name' => 'John Doe', 'category_id' => 5]
 * 
 * Features:
 * - Auto-detects relationship fields (ending with _id or defined in config)
 * - Searches related models by name or custom field
 * - Creates related records if not found (optional)
 * - Uses vector search for semantic matching (if available)
 * - Handles nested relationships
 */
trait AutoResolvesRelationships
{
    /**
     * Automatically resolve relationships in data before creating/updating
     * Handles both top-level fields and nested arrays
     */
    protected static function autoResolveRelationships(array $data, array $config = []): array
    {
        $model = new static();
        $relationships = static::discoverRelationships($config);
        
        // Resolve top-level relationships
        foreach ($relationships as $field => $relationConfig) {
            // Determine which field in the data to use for resolution
            // Priority: 1) explicit 'field' mapping, 2) 'search_field' if it exists in data, 3) derive from field name
            $searchField = null;
            $searchBy = $relationConfig['search_field'] ?? 'name';
            
            // Check if there's an explicit field mapping
            if (isset($relationConfig['field'])) {
                $searchField = $relationConfig['field'];
            }
            // Check if search_field exists in data (e.g., 'email' for customer_id)
            elseif (isset($data[$searchBy]) && is_string($data[$searchBy])) {
                $searchField = $searchBy;
            }
            // Default: derive from field name (customer_id -> customer)
            else {
                $searchField = str_replace('_id', '', $field);
            }
            
            if (isset($data[$searchField]) && is_string($data[$searchField])) {
                $value = $data[$searchField];
                $relatedModel = $relationConfig['model'];
                $createIfMissing = $relationConfig['create_if_missing'] ?? false;
                
                Log::channel('ai-engine')->debug('Resolving relationship', [
                    'field' => $field,
                    'search_field' => $searchField,
                    'value' => $value,
                    'model' => $relatedModel,
                ]);
                
                // Try to find existing record
                $related = static::findRelatedRecord($relatedModel, $searchBy, $value);
                
                // Create if not found and allowed
                if (!$related && $createIfMissing) {
                    $related = static::createRelatedRecord($relatedModel, $searchBy, $value, $relationConfig);
                    
                    // If the created record has its own relationships, resolve them too
                    if ($related && method_exists($relatedModel, 'autoResolveRelationships')) {
                        try {
                            // Get the record's data including all fields
                            $relatedData = $related->toArray();
                            
                            // Ensure email field is present for nested resolution
                            if (!isset($relatedData['email']) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                $relatedData['email'] = $value;
                            }
                            
                            Log::channel('ai-engine')->debug('Attempting nested relationship resolution', [
                                'model' => $relatedModel,
                                'data_keys' => array_keys($relatedData),
                            ]);
                            
                            $resolvedData = $relatedModel::autoResolveRelationships($relatedData);
                            
                            // Update the record with resolved relationships
                            if ($resolvedData !== $relatedData) {
                                $related->update($resolvedData);
                                $related = $related->fresh();
                                
                                Log::channel('ai-engine')->info('Resolved nested relationships for created record', [
                                    'model' => $relatedModel,
                                    'id' => $related->id,
                                    'resolved_fields' => array_keys(array_diff_assoc($resolvedData, $relatedData)),
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::channel('ai-engine')->warning('Failed to resolve nested relationships', [
                                'model' => $relatedModel,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    }
                }
                
                if ($related) {
                    $data[$field] = $related->id;
                    unset($data[$searchField]);
                    
                    Log::channel('ai-engine')->info('Relationship resolved', [
                        'field' => $field,
                        'value' => $value,
                        'resolved_id' => $related->id,
                    ]);
                } else {
                    Log::channel('ai-engine')->warning('Relationship not resolved', [
                        'field' => $field,
                        'value' => $value,
                    ]);
                }
            }
        }
        
        // Resolve relationships in nested arrays (e.g., invoice items)
        $data = static::resolveNestedArrayRelationships($data);
        
        return $data;
    }
    
    /**
     * Resolve relationships within nested arrays
     * For example, resolve product_id and category_id in invoice items
     */
    protected static function resolveNestedArrayRelationships(array $data): array
    {
        // Get AI config to find array fields with item structures
        $model = new static();
        if (!method_exists($model, 'initializeAI')) {
            return $data;
        }
        
        try {
            $aiConfig = $model->initializeAI();
            
            foreach ($aiConfig['fields'] ?? [] as $fieldName => $fieldConfig) {
                // Check if this is an array field with item structure
                if (($fieldConfig['type'] ?? '') === 'array' && isset($fieldConfig['item_structure']) && isset($data[$fieldName])) {
                    $items = $data[$fieldName];
                    
                    if (!is_array($items)) {
                        continue;
                    }
                    
                    // Resolve relationships for each item
                    foreach ($items as $index => $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        
                        // Check each field in item structure for relationships
                        foreach ($fieldConfig['item_structure'] as $itemField => $itemFieldConfig) {
                            if (($itemFieldConfig['type'] ?? '') === 'relationship' && isset($itemFieldConfig['relationship'])) {
                                $relationConfig = $itemFieldConfig['relationship'];
                                // Use custom field mapping if provided, otherwise derive from field name
                                $searchField = $relationConfig['field'] ?? str_replace('_id', '', $itemField);
                                
                                // Check if we have a string value to resolve
                                if (isset($item[$searchField]) && is_string($item[$searchField])) {
                                    $value = $item[$searchField];
                                    $relatedModel = $relationConfig['model'];
                                    $searchBy = $relationConfig['search_field'] ?? 'name';
                                    $createIfMissing = $relationConfig['create_if_missing'] ?? false;
                                    
                                    Log::channel('ai-engine')->debug('Resolving nested relationship', [
                                        'array_field' => $fieldName,
                                        'item_index' => $index,
                                        'field' => $itemField,
                                        'value' => $value,
                                        'model' => $relatedModel,
                                    ]);
                                    
                                    // Find or create related record
                                    $related = static::findRelatedRecord($relatedModel, $searchBy, $value);
                                    
                                    if (!$related && $createIfMissing) {
                                        $related = static::createRelatedRecord($relatedModel, $searchBy, $value, $relationConfig);
                                    }
                                    
                                    if ($related) {
                                        $items[$index][$itemField] = $related->id;
                                        unset($items[$index][$searchField]);
                                        
                                        Log::channel('ai-engine')->info('Nested relationship resolved', [
                                            'field' => $itemField,
                                            'value' => $value,
                                            'resolved_id' => $related->id,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                    
                    $data[$fieldName] = $items;
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to resolve nested relationships', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $data;
    }
    
    /**
     * Discover relationships from model and config
     */
    protected static function discoverRelationships(array $config = []): array
    {
        $relationships = [];
        $model = new static();
        
        // Get relationships from AI config if available
        if (method_exists($model, 'initializeAI')) {
            $aiConfig = $model->initializeAI();
            
            if (isset($aiConfig['fields'])) {
                foreach ($aiConfig['fields'] as $field => $fieldConfig) {
                    if (isset($fieldConfig['type']) && $fieldConfig['type'] === 'relationship') {
                        $relationships[$field] = $fieldConfig['relationship'] ?? [];
                    }
                }
            }
        }
        
        // Auto-detect from _id fields
        $fillable = $model->getFillable();
        foreach ($fillable as $field) {
            if (str_ends_with($field, '_id') && !isset($relationships[$field])) {
                $relationName = Str::camel(str_replace('_id', '', $field));
                
                if (method_exists($model, $relationName)) {
                    try {
                        $relation = $model->$relationName();
                        $relatedModel = get_class($relation->getRelated());
                        
                        $relationships[$field] = [
                            'model' => $relatedModel,
                            'search_field' => 'name',
                            'create_if_missing' => false,
                        ];
                    } catch (\Exception $e) {
                        // Skip if relation can't be resolved
                    }
                }
            }
        }
        
        // Merge with provided config
        return array_merge($relationships, $config);
    }
    
    /**
     * Find related record by search field
     * Uses related model's AI config to determine best search fields
     */
    protected static function findRelatedRecord(string $modelClass, string $searchField, string $value)
    {
        // Check if related model has AI config with search hints
        $relatedModel = new $modelClass();
        $searchFields = [$searchField];
        
        if (method_exists($relatedModel, 'initializeAI')) {
            try {
                $aiConfig = $relatedModel->initializeAI();
                
                // Look for email fields if searching by name (common pattern)
                if ($searchField === 'name' && isset($aiConfig['fields'])) {
                    foreach ($aiConfig['fields'] as $field => $config) {
                        if (($config['type'] ?? '') === 'email' && str_contains($value, '@')) {
                            // Value looks like email, prioritize email field
                            array_unshift($searchFields, $field);
                            Log::channel('ai-engine')->debug('Detected email pattern, will search email field first', [
                                'model' => $modelClass,
                                'email_field' => $field,
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Continue with default search
            }
        }
        
        // Try vector search first if model is vectorizable
        if (method_exists($modelClass, 'vectorSearch')) {
            try {
                $results = $modelClass::vectorSearch($value, limit: 1);
                if (!empty($results) && isset($results[0])) {
                    $firstResult = $results[0];
                    $relevanceScore = $firstResult->relevance_score ?? 0;
                    
                    if ($relevanceScore > 0.7) {
                        Log::channel('ai-engine')->debug('Found via vector search', [
                            'model' => $modelClass,
                            'relevance' => $relevanceScore,
                        ]);
                        return $firstResult;
                    }
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug('Vector search failed, falling back to DB', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Try each search field in order
        foreach ($searchFields as $field) {
            $result = $modelClass::where($field, 'LIKE', "%{$value}%")->first();
            if ($result) {
                Log::channel('ai-engine')->debug('Found via DB search', [
                    'model' => $modelClass,
                    'field' => $field,
                ]);
                return $result;
            }
        }
        
        return null;
    }
    
    /**
     * Create related record if missing
     * Uses related model's AI config if available for smarter creation
     */
    protected static function createRelatedRecord(string $modelClass, string $searchField, string $value, array $config)
    {
        $data = [$searchField => $value];
        
        // If the value looks like an email, also set it as email field
        if ($searchField === 'name' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $data['email'] = $value;
        }
        
        // Check if related model has AI configuration
        $relatedModel = new $modelClass();
        $aiConfig = null;
        
        if (method_exists($relatedModel, 'initializeAI')) {
            try {
                $aiConfig = $relatedModel->initializeAI();
                Log::channel('ai-engine')->debug('Found AI config for related model', [
                    'model' => $modelClass,
                    'fields' => array_keys($aiConfig['fields'] ?? []),
                ]);
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug('Could not get AI config for related model', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Use AI config to determine required fields and defaults
        if ($aiConfig && isset($aiConfig['fields'])) {
            foreach ($aiConfig['fields'] as $field => $fieldConfig) {
                // Skip if already set
                if (isset($data[$field])) {
                    continue;
                }
                
                // Add default values from AI config
                if (isset($fieldConfig['default']) && $fieldConfig['default'] !== null) {
                    $defaultValue = $fieldConfig['default'];
                    
                    // Handle special defaults
                    if ($defaultValue === 'today' || $defaultValue === 'now') {
                        $defaultValue = now();
                    }
                    
                    $data[$field] = $defaultValue;
                }
                
                // Generate required fields if missing
                if (isset($fieldConfig['required']) && $fieldConfig['required']) {
                    if (!isset($data[$field])) {
                        // Try to generate sensible defaults for required fields
                        $data[$field] = static::generateDefaultValue($field, $fieldConfig, $value);
                    }
                }
            }
        }
        
        // Add default values from relationship config
        if (isset($config['defaults'])) {
            $data = array_merge($data, $config['defaults']);
        }
        
        // Add workspace if model has it
        if (in_array('workspace_id', $relatedModel->getFillable())) {
            $data['workspace_id'] = $data['workspace_id'] ?? getActiveWorkSpace() ?? 1;
        }
        if (in_array('created_by', $relatedModel->getFillable())) {
            $data['created_by'] = $data['created_by'] ?? auth()->id() ?? 1;
        }
        
        try {
            $created = $modelClass::create($data);
            
            Log::channel('ai-engine')->info('Created related record using AI config', [
                'model' => $modelClass,
                'data' => $data,
                'id' => $created->id,
                'used_ai_config' => $aiConfig !== null,
            ]);
            
            return $created;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to create related record', [
                'model' => $modelClass,
                'data' => $data,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }
    
    /**
     * Generate default value for required field
     */
    protected static function generateDefaultValue(string $field, array $fieldConfig, string $contextValue)
    {
        $type = $fieldConfig['type'] ?? 'string';
        
        // Generate based on field type
        switch ($type) {
            case 'email':
                // Generate email from context value
                $slug = Str::slug($contextValue);
                return $slug . '@generated.local';
                
            case 'boolean':
                return false;
                
            case 'integer':
            case 'number':
                return 0;
                
            case 'date':
                return now();
                
            case 'enum':
                // Use first option if available
                if (isset($fieldConfig['options']) && !empty($fieldConfig['options'])) {
                    return $fieldConfig['options'][0];
                }
                return null;
                
            default:
                // For string fields, try to generate from context
                if (str_contains($field, 'name')) {
                    return $contextValue;
                }
                return null;
        }
    }
    
    /**
     * Override executeAI to automatically resolve relationships
     */
    public static function executeAI(string $action, array $data)
    {
        // Auto-resolve relationships before processing
        $data = static::autoResolveRelationships($data);
        
        // Call parent executeAI if it exists
        if (method_exists(get_parent_class(), 'executeAI')) {
            return parent::executeAI($action, $data);
        }
        
        // Default implementation
        switch ($action) {
            case 'create':
                return static::create($data);
            case 'update':
                if (isset($data['id'])) {
                    $model = static::findOrFail($data['id']);
                    $model->update($data);
                    return $model;
                }
                break;
            case 'delete':
                if (isset($data['id'])) {
                    $model = static::findOrFail($data['id']);
                    $model->delete();
                    return ['success' => true, 'message' => 'Deleted successfully'];
                }
                break;
        }
        
        throw new \Exception("Unsupported action: {$action}");
    }
}
