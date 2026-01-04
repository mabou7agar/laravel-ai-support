<?php

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate Detection Service
 * 
 * Searches for existing records in vector database and regular database
 * to prevent duplicate record creation.
 */
class DuplicateDetectionService
{
    /**
     * Search for existing records that match the extracted data
     * 
     * @param string $modelClass The model class to search
     * @param array $extractedData The data extracted from user message
     * @param array $searchableFields Fields to use for searching (e.g., ['name', 'email'])
     * @return array Array of matching records with similarity scores
     */
    public function searchExistingRecords(string $modelClass, array $extractedData, array $searchableFields = []): array
    {
        $results = [];
        
        // If no searchable fields specified, try to detect them
        if (empty($searchableFields)) {
            $searchableFields = $this->detectSearchableFields($extractedData);
        }
        
        if (empty($searchableFields)) {
            return $results;
        }
        
        Log::channel('ai-engine')->info('Searching for existing records', [
            'model' => $modelClass,
            'searchable_fields' => $searchableFields,
            'extracted_data' => $extractedData
        ]);
        
        // Try vector search first (semantic similarity)
        $vectorResults = $this->vectorSearch($modelClass, $extractedData, $searchableFields);
        
        // Try database search (exact/fuzzy matching)
        $dbResults = $this->databaseSearch($modelClass, $extractedData, $searchableFields);
        
        // Merge and deduplicate results
        $results = $this->mergeResults($vectorResults, $dbResults);
        
        Log::channel('ai-engine')->info('Duplicate detection results', [
            'model' => $modelClass,
            'vector_results_count' => count($vectorResults),
            'db_results_count' => count($dbResults),
            'total_unique_results' => count($results)
        ]);
        
        return $results;
    }
    
    /**
     * Perform vector search for similar records
     */
    protected function vectorSearch(string $modelClass, array $extractedData, array $searchableFields): array
    {
        $results = [];
        
        try {
            // Check if model has vector search capability
            if (!class_exists($modelClass)) {
                return $results;
            }
            
            $reflection = new \ReflectionClass($modelClass);
            
            // Check if model uses HasVectorSearch trait
            $traits = [];
            $class = $reflection;
            while ($class) {
                $traits = array_merge($traits, $class->getTraitNames());
                $class = $class->getParentClass();
            }
            
            $hasVectorSearch = in_array('LaravelAIEngine\Traits\HasVectorSearch', $traits);
            
            // Also check if vectorSearch method exists (more reliable)
            if (!$hasVectorSearch && !method_exists($modelClass, 'vectorSearch')) {
                Log::channel('ai-engine')->debug('Model does not have vector search capability', [
                    'model' => $modelClass
                ]);
                return $results;
            }
            
            // Build search query from searchable fields
            $searchQuery = $this->buildSearchQuery($extractedData, $searchableFields);
            
            if (empty($searchQuery)) {
                return $results;
            }
            
            Log::channel('ai-engine')->debug('Performing vector search', [
                'model' => $modelClass,
                'query' => $searchQuery
            ]);
            
            // Perform vector search with additional safety check
            if (method_exists($modelClass, 'vectorSearch')) {
                $vectorResults = $modelClass::vectorSearch($searchQuery, limit: 5, threshold: 0.7);
            } else {
                Log::channel('ai-engine')->warning('vectorSearch method not found on model', [
                    'model' => $modelClass
                ]);
                return $results;
            }
            
            foreach ($vectorResults as $record) {
                $results[] = [
                    'id' => $record->id,
                    'data' => $record->toArray(),
                    'similarity' => $record->similarity ?? 0.8,
                    'source' => 'vector',
                    'model' => $record
                ];
            }
            
            Log::channel('ai-engine')->info('Vector search completed', [
                'model' => $modelClass,
                'results_count' => count($results)
            ]);
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Vector search failed', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
    }
    
    /**
     * Perform database search for matching records
     */
    protected function databaseSearch(string $modelClass, array $extractedData, array $searchableFields): array
    {
        $results = [];
        
        try {
            if (!class_exists($modelClass)) {
                return $results;
            }
            
            $query = $modelClass::query();
            $hasConditions = false;
            
            // Build WHERE clauses for searchable fields
            foreach ($searchableFields as $field) {
                if (!isset($extractedData[$field]) || empty($extractedData[$field])) {
                    continue;
                }
                
                $value = $extractedData[$field];
                
                // Use LIKE for string fields, exact match for others
                if (is_string($value)) {
                    $query->orWhere($field, 'LIKE', "%{$value}%");
                    $hasConditions = true;
                } else {
                    $query->orWhere($field, $value);
                    $hasConditions = true;
                }
            }
            
            if (!$hasConditions) {
                return $results;
            }
            
            Log::channel('ai-engine')->debug('Performing database search', [
                'model' => $modelClass,
                'fields' => $searchableFields
            ]);
            
            // Execute query and limit results
            $records = $query->limit(5)->get();
            
            foreach ($records as $record) {
                // Calculate simple similarity score based on field matches
                $similarity = $this->calculateSimilarity($extractedData, $record->toArray(), $searchableFields);
                
                $results[] = [
                    'id' => $record->id,
                    'data' => $record->toArray(),
                    'similarity' => $similarity,
                    'source' => 'database',
                    'model' => $record
                ];
            }
            
            Log::channel('ai-engine')->info('Database search completed', [
                'model' => $modelClass,
                'results_count' => count($results)
            ]);
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Database search failed', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
    }
    
    /**
     * Detect which fields should be used for searching
     */
    protected function detectSearchableFields(array $extractedData): array
    {
        $commonSearchableFields = ['name', 'email', 'title', 'sku', 'code', 'phone', 'username'];
        $searchableFields = [];
        
        foreach ($extractedData as $key => $value) {
            // Skip empty values and internal fields
            if (empty($value) || str_starts_with($key, '_')) {
                continue;
            }
            
            // Include common searchable fields
            if (in_array($key, $commonSearchableFields)) {
                $searchableFields[] = $key;
            }
            
            // Include fields that contain searchable keywords
            foreach ($commonSearchableFields as $searchable) {
                if (str_contains($key, $searchable)) {
                    $searchableFields[] = $key;
                    break;
                }
            }
        }
        
        return array_unique($searchableFields);
    }
    
    /**
     * Build search query from extracted data
     */
    protected function buildSearchQuery(array $extractedData, array $searchableFields): string
    {
        $queryParts = [];
        
        foreach ($searchableFields as $field) {
            if (isset($extractedData[$field]) && !empty($extractedData[$field])) {
                $value = $extractedData[$field];
                if (is_string($value)) {
                    $queryParts[] = $value;
                }
            }
        }
        
        return implode(' ', $queryParts);
    }
    
    /**
     * Calculate similarity score between extracted data and existing record
     */
    protected function calculateSimilarity(array $extractedData, array $recordData, array $searchableFields): float
    {
        $matches = 0;
        $total = 0;
        
        foreach ($searchableFields as $field) {
            if (!isset($extractedData[$field])) {
                continue;
            }
            
            $total++;
            
            if (!isset($recordData[$field])) {
                continue;
            }
            
            $extractedValue = strtolower(trim((string)$extractedData[$field]));
            $recordValue = strtolower(trim((string)$recordData[$field]));
            
            // Exact match
            if ($extractedValue === $recordValue) {
                $matches += 1.0;
            }
            // Partial match
            elseif (str_contains($recordValue, $extractedValue) || str_contains($extractedValue, $recordValue)) {
                $matches += 0.7;
            }
            // Similar (Levenshtein distance)
            else {
                $distance = levenshtein($extractedValue, $recordValue);
                $maxLength = max(strlen($extractedValue), strlen($recordValue));
                if ($maxLength > 0) {
                    $similarity = 1 - ($distance / $maxLength);
                    if ($similarity > 0.6) {
                        $matches += $similarity;
                    }
                }
            }
        }
        
        return $total > 0 ? ($matches / $total) : 0;
    }
    
    /**
     * Merge and deduplicate results from vector and database searches
     */
    protected function mergeResults(array $vectorResults, array $dbResults): array
    {
        $merged = [];
        $seenIds = [];
        
        // Add vector results first (usually higher quality)
        foreach ($vectorResults as $result) {
            $id = $result['id'];
            if (!isset($seenIds[$id])) {
                $merged[] = $result;
                $seenIds[$id] = true;
            }
        }
        
        // Add database results that weren't already found
        foreach ($dbResults as $result) {
            $id = $result['id'];
            if (!isset($seenIds[$id])) {
                $merged[] = $result;
                $seenIds[$id] = true;
            }
        }
        
        // Sort by similarity score (highest first)
        usort($merged, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        return $merged;
    }
    
    /**
     * Format existing records for user presentation
     */
    public function formatExistingRecordsForUser(array $existingRecords, string $modelName): string
    {
        if (empty($existingRecords)) {
            return '';
        }
        
        $message = "\n\n🔍 **Found Existing {$modelName}(s):**\n\n";
        $message .= "I found " . count($existingRecords) . " similar record(s) that might match what you're looking for:\n\n";
        
        foreach ($existingRecords as $index => $record) {
            $num = $index + 1;
            $data = $record['data'];
            $similarity = round($record['similarity'] * 100);
            
            $message .= "**{$num}. ";
            
            // Display key identifying fields
            $displayFields = $this->getDisplayFields($data);
            $message .= implode(' - ', $displayFields);
            $message .= "** ({$similarity}% match)\n";
            
            // Show a few key details
            $details = $this->getKeyDetails($data);
            foreach ($details as $key => $value) {
                $message .= "   • " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
            }
            $message .= "\n";
        }
        
        $message .= "**Would you like to:**\n";
        $message .= "• Use one of these existing records (reply with the number)\n";
        $message .= "• Create a new record anyway (reply 'new' or 'create new')\n";
        
        return $message;
    }
    
    /**
     * Get display fields for a record
     */
    protected function getDisplayFields(array $data): array
    {
        $displayFields = [];
        $priorityFields = ['name', 'title', 'email', 'sku', 'code', 'id'];
        
        foreach ($priorityFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $displayFields[] = $data[$field];
                if (count($displayFields) >= 2) {
                    break;
                }
            }
        }
        
        return $displayFields;
    }
    
    /**
     * Get key details to show for a record
     */
    protected function getKeyDetails(array $data): array
    {
        $details = [];
        $detailFields = ['email', 'phone', 'price', 'status', 'created_at'];
        $maxDetails = 3;
        
        foreach ($detailFields as $field) {
            if (isset($data[$field]) && !empty($data[$field]) && count($details) < $maxDetails) {
                $value = $data[$field];
                
                // Format dates nicely
                if ($field === 'created_at' && $value) {
                    try {
                        $date = new \DateTime($value);
                        $value = $date->format('M d, Y');
                    } catch (\Exception $e) {
                        // Keep original value
                    }
                }
                
                $details[$field] = $value;
            }
        }
        
        return $details;
    }
}
