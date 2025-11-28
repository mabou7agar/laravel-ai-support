<?php

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;

/**
 * Schema Analyzer Service
 * 
 * Analyzes models to suggest optimal vector indexing configuration
 */
class SchemaAnalyzer
{
    /**
     * Analyze a model and suggest indexing configuration
     * 
     * @param string $modelClass
     * @return array
     */
    public function analyze(string $modelClass): array
    {
        try {
            if (!class_exists($modelClass)) {
                throw new \InvalidArgumentException("Model class not found: {$modelClass}");
            }
            
            $model = new $modelClass;
            $table = $model->getTable();
            
            return [
                'model' => $modelClass,
                'table' => $table,
                'text_fields' => $this->getTextFields($table),
                'relationships' => $this->getRelationships($modelClass),
                'recommended_config' => $this->getRecommendedConfig($modelClass),
                'estimated_size' => $this->estimateIndexSize($table),
            ];
        } catch (\Exception $e) {
            // Return partial analysis on error
            return [
                'model' => $modelClass,
                'table' => null,
                'text_fields' => [],
                'relationships' => $this->getRelationships($modelClass),
                'recommended_config' => [],
                'estimated_size' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get text fields from table
     * 
     * @param string $table
     * @return array
     */
    protected function getTextFields(string $table): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }
        
        $columns = Schema::getColumnListing($table);
        $textFields = [];
        
        foreach ($columns as $column) {
            try {
                $type = Schema::getColumnType($table, $column);
                
                if (in_array($type, ['string', 'text', 'longtext', 'mediumtext'])) {
                    $textFields[] = [
                        'name' => $column,
                        'type' => $type,
                        'recommended' => $this->isRecommendedField($column),
                    ];
                }
            } catch (\Exception $e) {
                // Skip columns with unsupported types (e.g., enum)
                // Check if it's likely a text field by name
                if ($this->isRecommendedField($column)) {
                    $textFields[] = [
                        'name' => $column,
                        'type' => 'unknown',
                        'recommended' => true,
                    ];
                }
            }
        }
        
        return $textFields;
    }
    
    /**
     * Check if field is commonly used for search
     * 
     * @param string $fieldName
     * @return bool
     */
    protected function isRecommendedField(string $fieldName): bool
    {
        $recommendedFields = [
            'title', 'name', 'content', 'body', 'description', 
            'text', 'message', 'summary', 'excerpt', 'bio',
            'notes', 'details', 'comment', 'review'
        ];
        
        return in_array(strtolower($fieldName), $recommendedFields);
    }
    
    /**
     * Detect relationships using reflection
     * 
     * @param string $modelClass
     * @return array
     */
    protected function getRelationships(string $modelClass): array
    {
        $reflection = new ReflectionClass($modelClass);
        $relationships = [];
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip inherited methods from Model
            if ($method->class !== $modelClass) {
                continue;
            }
            
            // Skip magic methods and getters
            if (str_starts_with($method->getName(), '__') || 
                str_starts_with($method->getName(), 'get') ||
                str_starts_with($method->getName(), 'set')) {
                continue;
            }
            
            // Check return type
            $returnType = $method->getReturnType();
            if (!$returnType) {
                continue;
            }
            
            $returnTypeName = $returnType->getName();
            
            // Check if it's a relationship
            if (str_contains($returnTypeName, 'Illuminate\Database\Eloquent\Relations')) {
                $relationType = class_basename($returnTypeName);
                
                $relationships[] = [
                    'name' => $method->getName(),
                    'type' => $relationType,
                    'recommended' => $this->isRecommendedRelationship($relationType),
                ];
            }
        }
        
        return $relationships;
    }
    
    /**
     * Check if relationship type is recommended for indexing
     * 
     * @param string $relationType
     * @return bool
     */
    protected function isRecommendedRelationship(string $relationType): bool
    {
        // BelongsTo and HasMany are usually good for indexing
        // BelongsToMany might have too many records
        return in_array($relationType, ['BelongsTo', 'HasMany', 'HasOne', 'MorphOne']);
    }
    
    /**
     * Generate recommended configuration
     * 
     * @param string $modelClass
     * @return array
     */
    protected function getRecommendedConfig(string $modelClass): array
    {
        $model = new $modelClass;
        $table = $model->getTable();
        
        $textFields = $this->getTextFields($table);
        $relationships = $this->getRelationships($modelClass);
        
        // Get recommended fields
        $recommendedFields = array_filter($textFields, fn($f) => $f['recommended']);
        $vectorizable = array_column($recommendedFields, 'name');
        
        // If no recommended fields, use all text fields
        if (empty($vectorizable)) {
            $vectorizable = array_column($textFields, 'name');
        }
        
        // Get recommended relationships
        $recommendedRelations = array_filter($relationships, fn($r) => $r['recommended']);
        $vectorRelationships = array_column($recommendedRelations, 'name');
        
        // Determine depth based on number of relationships
        $maxDepth = count($vectorRelationships) > 3 ? 1 : 2;
        
        return [
            'vectorizable' => $vectorizable,
            'vectorRelationships' => $vectorRelationships,
            'maxRelationshipDepth' => $maxDepth,
            'ragPriority' => 50, // Default priority
        ];
    }
    
    /**
     * Estimate index size
     * 
     * @param string $table
     * @return array
     */
    protected function estimateIndexSize(string $table): array
    {
        try {
            $count = \DB::table($table)->count();
            
            // Rough estimation: ~1KB per vector
            $estimatedSize = $count * 1024;
            
            return [
                'record_count' => $count,
                'estimated_bytes' => $estimatedSize,
                'estimated_mb' => round($estimatedSize / 1024 / 1024, 2),
            ];
        } catch (\Exception $e) {
            return [
                'record_count' => 0,
                'estimated_bytes' => 0,
                'estimated_mb' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Analyze multiple models
     * 
     * @param array $modelClasses
     * @return array
     */
    public function analyzeModels(array $modelClasses): array
    {
        $results = [];
        
        foreach ($modelClasses as $modelClass) {
            try {
                $results[$modelClass] = $this->analyzeModel($modelClass);
            } catch (\Exception $e) {
                $results[$modelClass] = [
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get all vectorizable models in the application
     * 
     * @return array
     */
    public function discoverVectorizableModels(): array
    {
        return \discover_vectorizable_models();
    }
}
