<?php

namespace LaravelAIEngine\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Trait for automatically resolving AI-extracted relationship data
 * Handles fields ending with _id that contain string values (names)
 * Automatically finds or creates related records
 */
trait ResolvesAIRelationships
{
    /**
     * Resolve relationship fields from AI-extracted data
     * Automatically creates related records if they don't exist
     */
    public static function resolveAIRelationships(array $params): array
    {
        // Check if there's a marker for relationships to resolve
        $relationshipsToResolve = $params['_resolve_relationships'] ?? [];
        unset($params['_resolve_relationships']);

        // If no explicit marker, auto-detect fields ending with _id that have string values
        if (empty($relationshipsToResolve)) {
            foreach ($params as $key => $value) {
                if (str_ends_with($key, '_id') && !is_numeric($value) && is_string($value)) {
                    $relationshipsToResolve[$key] = $value;
                }
            }
        }

        if (empty($relationshipsToResolve)) {
            return $params;
        }

        Log::info('Resolving AI relationships', [
            'model' => static::class,
            'relationships' => $relationshipsToResolve
        ]);

        $model = new static();

        foreach ($relationshipsToResolve as $field => $value) {
            // Get the relationship name (remove _id suffix)
            $relationName = substr($field, 0, -3);

            // Check if model has this relationship
            if (method_exists($model, $relationName)) {
                try {
                    $relation = $model->$relationName();
                    $relatedClass = get_class($relation->getRelated());

                    Log::info('Resolving relationship', [
                        'field' => $field,
                        'relation' => $relationName,
                        'related_class' => $relatedClass,
                        'search_value' => $value
                    ]);

                    // Find or create the related record
                    $relatedId = static::findOrCreateRelated($relatedClass, $value);

                    if ($relatedId) {
                        $params[$field] = $relatedId;
                        Log::info('Relationship resolved', [
                            'field' => $field,
                            'id' => $relatedId
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to resolve relationship', [
                        'field' => $field,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $params;
    }

    /**
     * Find or create a related record by name
     * Uses vector search if the model is vectorizable for semantic matching
     */
    protected static function findOrCreateRelated(string $relatedClass, string $searchValue): ?int
    {
        try {
            // Check if related model uses Vectorizable trait (has vector search capability)
            $usesVectorSearch = method_exists($relatedClass, 'vectorSearch');
            
            if ($usesVectorSearch) {
                Log::info('Using vector search for semantic relationship matching', [
                    'class' => $relatedClass,
                    'search_value' => $searchValue
                ]);
                
                try {
                    // Use vector search for semantic matching
                    $results = $relatedClass::vectorSearch($searchValue, limit: 1);
                    
                    if (!empty($results)) {
                        $record = $results[0];
                        Log::info('Found related record via vector search', [
                            'class' => $relatedClass,
                            'id' => $record->id,
                            'name' => $record->name ?? 'N/A',
                            'relevance_score' => $record->relevance_score ?? 'N/A'
                        ]);
                        return $record->id;
                    }
                } catch (\Exception $e) {
                    Log::warning('Vector search failed, falling back to traditional search', [
                        'class' => $relatedClass,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Fallback to traditional database search
            $record = $relatedClass::where('name', 'LIKE', "%{$searchValue}%")->first();

            if ($record) {
                Log::info('Found existing related record via traditional search', [
                    'class' => $relatedClass,
                    'id' => $record->id,
                    'name' => $record->name
                ]);
                return $record->id;
            }

            // Not found - create new record
            $relatedModel = new $relatedClass();
            
            // Check if model has 'name' in fillable
            if (!in_array('name', $relatedModel->getFillable())) {
                Log::warning('Related model does not have name field in fillable', [
                    'class' => $relatedClass
                ]);
                return null;
            }

            // Create the record
            $data = ['name' => $searchValue];
            
            // Add workspace_id if the model uses it
            if (in_array('workspace_id', $relatedModel->getFillable())) {
                $data['workspace_id'] = auth()->user()->workspace_id ?? getActiveWorkSpace() ?? 1;
            }
            
            // Add created_by if the model uses it
            if (in_array('created_by', $relatedModel->getFillable())) {
                $data['created_by'] = auth()->id() ?? 1;
            }

            $newRecord = $relatedClass::create($data);

            Log::info('Created new related record', [
                'class' => $relatedClass,
                'id' => $newRecord->id,
                'name' => $newRecord->name
            ]);

            return $newRecord->id;

        } catch (\Exception $e) {
            Log::error('Failed to find/create related record', [
                'class' => $relatedClass,
                'search' => $searchValue,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
