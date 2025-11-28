<?php

namespace LaravelAIEngine\Services;

/**
 * Model Analyzer Service
 * 
 * Comprehensive model analysis combining schema and relationship analysis
 */
class ModelAnalyzer
{
    protected SchemaAnalyzer $schemaAnalyzer;
    protected RelationshipAnalyzer $relationshipAnalyzer;
    
    public function __construct(
        SchemaAnalyzer $schemaAnalyzer,
        RelationshipAnalyzer $relationshipAnalyzer
    ) {
        $this->schemaAnalyzer = $schemaAnalyzer;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
    }
    
    /**
     * Perform comprehensive model analysis
     * 
     * @param string $modelClass
     * @return array
     */
    public function analyze(string $modelClass): array
    {
        // Get schema analysis
        $schemaAnalysis = $this->schemaAnalyzer->analyzeModel($modelClass);
        
        // Get relationship analysis
        $relationshipAnalysis = $this->relationshipAnalyzer->analyzeRelationships($modelClass);
        
        // Combine and enhance
        return [
            'model' => $modelClass,
            'schema' => $schemaAnalysis,
            'relationships' => $relationshipAnalysis,
            'recommendations' => $this->generateRecommendations($schemaAnalysis, $relationshipAnalysis),
            'indexing_plan' => $this->createIndexingPlan($schemaAnalysis, $relationshipAnalysis),
        ];
    }
    
    /**
     * Generate comprehensive recommendations
     * 
     * @param array $schemaAnalysis
     * @param array $relationshipAnalysis
     * @return array
     */
    protected function generateRecommendations(array $schemaAnalysis, array $relationshipAnalysis): array
    {
        $recommendations = [];
        
        // Field recommendations
        $textFields = $schemaAnalysis['text_fields'] ?? [];
        $recommendedFields = array_filter($textFields, fn($f) => $f['recommended']);
        
        if (empty($recommendedFields)) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'No recommended text fields found. Consider adding title, content, or description fields.',
            ];
        } else {
            $recommendations[] = [
                'type' => 'success',
                'message' => count($recommendedFields) . ' text fields recommended for indexing.',
            ];
        }
        
        // Relationship recommendations
        $recommended = $relationshipAnalysis['recommended'] ?? [];
        
        if (!empty($recommended)) {
            $recommendations[] = [
                'type' => 'success',
                'message' => count($recommended) . ' relationships recommended for indexing.',
            ];
        }
        
        // Warnings
        $warnings = $relationshipAnalysis['warnings'] ?? [];
        foreach ($warnings as $warning) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => $warning,
            ];
        }
        
        // Size recommendations
        $size = $schemaAnalysis['estimated_size'] ?? [];
        if (isset($size['record_count'])) {
            if ($size['record_count'] > 100000) {
                $recommendations[] = [
                    'type' => 'info',
                    'message' => 'Large dataset detected. Consider using --queue flag for indexing.',
                ];
            }
            
            if ($size['record_count'] > 1000000) {
                $recommendations[] = [
                    'type' => 'warning',
                    'message' => 'Very large dataset. Indexing may take significant time and resources.',
                ];
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Create indexing plan
     * 
     * @param array $schemaAnalysis
     * @param array $relationshipAnalysis
     * @return array
     */
    protected function createIndexingPlan(array $schemaAnalysis, array $relationshipAnalysis): array
    {
        $config = $schemaAnalysis['recommended_config'] ?? [];
        $size = $schemaAnalysis['estimated_size'] ?? [];
        $recordCount = $size['record_count'] ?? 0;
        
        // Determine batch size
        $batchSize = 100;
        if ($recordCount > 10000) {
            $batchSize = 500;
        }
        if ($recordCount > 100000) {
            $batchSize = 1000;
        }
        
        // Determine if queue should be used
        $useQueue = $recordCount > 1000;
        
        // Estimate time
        $estimatedTime = $this->estimateIndexingTime($recordCount, $batchSize);
        
        return [
            'batch_size' => $batchSize,
            'use_queue' => $useQueue,
            'estimated_time' => $estimatedTime,
            'estimated_cost' => $this->estimateCost($recordCount),
            'command' => $this->generateCommand($schemaAnalysis['model'], $config, $batchSize, $useQueue),
        ];
    }
    
    /**
     * Estimate indexing time
     * 
     * @param int $recordCount
     * @param int $batchSize
     * @return array
     */
    protected function estimateIndexingTime(int $recordCount, int $batchSize): array
    {
        // Rough estimation: ~0.5 seconds per record (including API calls)
        $totalSeconds = $recordCount * 0.5;
        
        // Batching speeds things up
        $totalSeconds = $totalSeconds / ($batchSize / 10);
        
        $hours = floor($totalSeconds / 3600);
        $minutes = floor(($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;
        
        return [
            'total_seconds' => round($totalSeconds),
            'formatted' => sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds),
            'human' => $this->formatDuration($totalSeconds),
        ];
    }
    
    /**
     * Format duration in human-readable format
     * 
     * @param float $seconds
     * @return string
     */
    protected function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds) . ' seconds';
        }
        
        if ($seconds < 3600) {
            return round($seconds / 60) . ' minutes';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = round(($seconds % 3600) / 60);
        
        return "{$hours} hours, {$minutes} minutes";
    }
    
    /**
     * Estimate API cost
     * 
     * @param int $recordCount
     * @return array
     */
    protected function estimateCost(int $recordCount): array
    {
        // Rough estimation: $0.0001 per embedding (text-embedding-3-small)
        $costPerEmbedding = 0.0001;
        $totalCost = $recordCount * $costPerEmbedding;
        
        return [
            'per_record' => $costPerEmbedding,
            'total' => round($totalCost, 4),
            'currency' => 'USD',
            'note' => 'Estimated cost using text-embedding-3-small model',
        ];
    }
    
    /**
     * Generate indexing command
     * 
     * @param string $modelClass
     * @param array $config
     * @param int $batchSize
     * @param bool $useQueue
     * @return string
     */
    protected function generateCommand(
        string $modelClass,
        array $config,
        int $batchSize,
        bool $useQueue
    ): string {
        $command = "php artisan ai-engine:vector-index \"{$modelClass}\"";
        
        if ($batchSize !== 100) {
            $command .= " --batch={$batchSize}";
        }
        
        if ($useQueue) {
            $command .= " --queue";
        }
        
        if (!empty($config['vectorRelationships'])) {
            $command .= " --with-relationships";
            
            if (isset($config['maxRelationshipDepth']) && $config['maxRelationshipDepth'] !== 1) {
                $command .= " --relationship-depth={$config['maxRelationshipDepth']}";
            }
        }
        
        return $command;
    }
    
    /**
     * Analyze multiple models
     * 
     * @param array $modelClasses
     * @return array
     */
    public function analyzeMultiple(array $modelClasses): array
    {
        $results = [];
        
        foreach ($modelClasses as $modelClass) {
            try {
                $results[$modelClass] = $this->analyze($modelClass);
            } catch (\Exception $e) {
                $results[$modelClass] = [
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }
}
