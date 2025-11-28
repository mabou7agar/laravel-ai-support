<?php

namespace LaravelAIEngine\Services;

use ReflectionClass;
use ReflectionMethod;
use Illuminate\Database\Eloquent\Model;

/**
 * Relationship Analyzer Service
 * 
 * Analyzes model relationships for vector indexing
 */
class RelationshipAnalyzer
{
    /**
     * Analyze relationships for a model
     * 
     * @param string $modelClass
     * @return array
     */
    public function analyzeRelationships(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class not found: {$modelClass}");
        }
        
        $relationships = $this->detectRelationships($modelClass);
        
        return [
            'model' => $modelClass,
            'relationships' => $relationships,
            'recommended' => $this->getRecommendedRelationships($relationships),
            'suggested_depth' => $this->suggestDepth($relationships),
            'warnings' => $this->getWarnings($relationships),
        ];
    }
    
    /**
     * Detect all relationships in a model
     * 
     * @param string $modelClass
     * @return array
     */
    protected function detectRelationships(string $modelClass): array
    {
        $reflection = new ReflectionClass($modelClass);
        $relationships = [];
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip inherited methods
            if ($method->class !== $modelClass) {
                continue;
            }
            
            // Skip magic methods and getters/setters
            $methodName = $method->getName();
            if (str_starts_with($methodName, '__') || 
                str_starts_with($methodName, 'get') ||
                str_starts_with($methodName, 'set') ||
                str_starts_with($methodName, 'scope')) {
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
                    'name' => $methodName,
                    'type' => $relationType,
                    'related_model' => $this->getRelatedModel($modelClass, $methodName),
                    'is_one_to_many' => $this->isOneToMany($relationType),
                    'is_many_to_many' => $this->isManyToMany($relationType),
                    'recommended_for_indexing' => $this->isRecommendedForIndexing($relationType),
                    'estimated_count' => $this->estimateRelatedCount($modelClass, $methodName),
                ];
            }
        }
        
        return $relationships;
    }
    
    /**
     * Get related model class for a relationship
     * 
     * @param string $modelClass
     * @param string $relationName
     * @return string|null
     */
    protected function getRelatedModel(string $modelClass, string $relationName): ?string
    {
        try {
            $model = new $modelClass;
            $relation = $model->$relationName();
            
            if (method_exists($relation, 'getRelated')) {
                return get_class($relation->getRelated());
            }
        } catch (\Exception $e) {
            // Can't determine related model
        }
        
        return null;
    }
    
    /**
     * Check if relationship type is one-to-many
     * 
     * @param string $relationType
     * @return bool
     */
    protected function isOneToMany(string $relationType): bool
    {
        return in_array($relationType, ['HasMany', 'HasManyThrough', 'MorphMany']);
    }
    
    /**
     * Check if relationship type is many-to-many
     * 
     * @param string $relationType
     * @return bool
     */
    protected function isManyToMany(string $relationType): bool
    {
        return in_array($relationType, ['BelongsToMany', 'MorphToMany']);
    }
    
    /**
     * Check if relationship is recommended for indexing
     * 
     * @param string $relationType
     * @return bool
     */
    protected function isRecommendedForIndexing(string $relationType): bool
    {
        // BelongsTo and HasOne are usually safe
        // HasMany can be included if count is reasonable
        // BelongsToMany might have too many records
        return in_array($relationType, ['BelongsTo', 'HasOne', 'MorphOne', 'HasMany']);
    }
    
    /**
     * Estimate count of related records
     * 
     * @param string $modelClass
     * @param string $relationName
     * @return string
     */
    protected function estimateRelatedCount(string $modelClass, string $relationName): string
    {
        try {
            $model = $modelClass::first();
            
            if (!$model) {
                return 'unknown';
            }
            
            $relation = $model->$relationName();
            
            if (method_exists($relation, 'count')) {
                $count = $relation->count();
                
                if ($count === 0) return 'none';
                if ($count === 1) return 'one';
                if ($count < 10) return 'few (< 10)';
                if ($count < 100) return 'moderate (< 100)';
                return 'many (100+)';
            }
        } catch (\Exception $e) {
            // Can't estimate
        }
        
        return 'unknown';
    }
    
    /**
     * Get recommended relationships for indexing
     * 
     * @param array $relationships
     * @return array
     */
    protected function getRecommendedRelationships(array $relationships): array
    {
        return array_filter($relationships, function ($rel) {
            // Recommend if:
            // 1. It's a recommended type
            // 2. Count is not "many"
            return $rel['recommended_for_indexing'] && 
                   !str_contains($rel['estimated_count'], 'many');
        });
    }
    
    /**
     * Suggest optimal depth for relationships
     * 
     * @param array $relationships
     * @return int
     */
    protected function suggestDepth(array $relationships): int
    {
        $recommendedCount = count($this->getRecommendedRelationships($relationships));
        
        // If many relationships, keep depth low
        if ($recommendedCount > 5) {
            return 1;
        }
        
        // If few relationships, can go deeper
        if ($recommendedCount <= 2) {
            return 2;
        }
        
        return 1;
    }
    
    /**
     * Get warnings about relationships
     * 
     * @param array $relationships
     * @return array
     */
    protected function getWarnings(array $relationships): array
    {
        $warnings = [];
        
        foreach ($relationships as $rel) {
            // Warn about many-to-many
            if ($rel['is_many_to_many']) {
                $warnings[] = "Relationship '{$rel['name']}' is many-to-many - may have many records";
            }
            
            // Warn about high counts
            if (str_contains($rel['estimated_count'], 'many')) {
                $warnings[] = "Relationship '{$rel['name']}' has many records - consider excluding";
            }
            
            // Warn about missing related model
            if ($rel['related_model'] === null) {
                $warnings[] = "Could not determine related model for '{$rel['name']}'";
            }
        }
        
        return $warnings;
    }
    
    /**
     * Get nested relationships (depth > 1)
     * 
     * @param string $modelClass
     * @param int $maxDepth
     * @param int $currentDepth
     * @return array
     */
    public function getNestedRelationships(
        string $modelClass,
        int $maxDepth = 2,
        int $currentDepth = 0
    ): array {
        if ($currentDepth >= $maxDepth) {
            return [];
        }
        
        $relationships = $this->detectRelationships($modelClass);
        $nested = [];
        
        foreach ($relationships as $rel) {
            if ($rel['related_model'] && $rel['recommended_for_indexing']) {
                $nested[$rel['name']] = [
                    'type' => $rel['type'],
                    'model' => $rel['related_model'],
                    'children' => $this->getNestedRelationships(
                        $rel['related_model'],
                        $maxDepth,
                        $currentDepth + 1
                    ),
                ];
            }
        }
        
        return $nested;
    }
    
    /**
     * Build dot-notation relationship paths
     * 
     * @param array $nested
     * @param string $prefix
     * @return array
     */
    public function buildRelationshipPaths(array $nested, string $prefix = ''): array
    {
        $paths = [];
        
        foreach ($nested as $name => $data) {
            $path = $prefix ? "{$prefix}.{$name}" : $name;
            $paths[] = $path;
            
            if (!empty($data['children'])) {
                $childPaths = $this->buildRelationshipPaths($data['children'], $path);
                $paths = array_merge($paths, $childPaths);
            }
        }
        
        return $paths;
    }
}
