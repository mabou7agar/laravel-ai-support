<?php

namespace LaravelAIEngine\Services\Vectorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Detects which fields should be vectorized
 */
class VectorizableFieldDetector
{
    /**
     * Auto-detect vectorizable fields from model
     */
    public function detect(Model $model): array
    {
        // Check if explicitly set
        if (!empty($model->vectorizable)) {
            return [
                'fields' => $model->vectorizable,
                'source' => 'explicit',
            ];
        }

        // Try cache first
        $cacheKey = 'vectorizable_fields_' . $model->getTable();
        
        if (Cache::has($cacheKey)) {
            if (config('ai-engine.debug')) {
                Log::channel('ai-engine')->debug('Auto-detect vectorizable fields (from cache)', [
                    'model' => get_class($model),
                    'table' => $model->getTable(),
                    'detected_fields' => Cache::get($cacheKey),
                    'source' => 'cache',
                ]);
            }
            
            return [
                'fields' => Cache::get($cacheKey),
                'source' => 'cache',
            ];
        }

        // Auto-detect from schema
        $detected = $this->detectFromSchema($model);
        
        // Cache for 24 hours
        Cache::put($cacheKey, $detected, now()->addHours(24));
        
        return [
            'fields' => $detected,
            'source' => 'auto-detected',
        ];
    }

    /**
     * Detect fields from database schema
     */
    protected function detectFromSchema(Model $model): array
    {
        try {
            $table = $model->getTable();
            $columns = Schema::getColumnListing($table);
            $columnTypes = [];

            // Get column types
            foreach ($columns as $column) {
                $type = Schema::getColumnType($table, $column);
                $columnTypes[$column] = $type;
            }

            // Filter text columns
            $textColumns = $this->filterTextColumns($columns, $columnTypes);
            
            if (config('ai-engine.debug')) {
                Log::channel('ai-engine')->info('Auto-detect: Found text columns', [
                    'model' => get_class($model),
                    'table' => $table,
                    'all_columns' => count($columns),
                    'text_columns' => $textColumns,
                    'column_types' => $columnTypes,
                ]);
            }

            // Use AI to select best fields
            $selected = $this->selectBestFields($textColumns, $columnTypes);
            
            if (config('ai-engine.debug')) {
                Log::channel('ai-engine')->info('Auto-detect: Selected fields for vectorization', [
                    'model' => get_class($model),
                    'table' => $table,
                    'selected_fields' => $selected,
                    'total_selected' => count($selected),
                    'source' => 'AI analysis',
                ]);
            }

            return $selected;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to auto-detect fields', [
                'model' => get_class($model),
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }
    }

    /**
     * Filter text columns from all columns
     */
    protected function filterTextColumns(array $columns, array $columnTypes): array
    {
        $textTypes = ['string', 'text', 'varchar', 'char', 'longtext', 'mediumtext', 'tinytext'];
        $textColumns = [];

        // Skip raw/binary fields
        $skipPatterns = ['raw_body', 'raw_content', 'raw_data', 'raw_email', 
                        'binary_data', 'file_data', 'attachment_data'];

        foreach ($columns as $column) {
            // Skip if matches skip pattern
            if (in_array($column, $skipPatterns)) {
                continue;
            }

            // Check if text type
            $type = $columnTypes[$column] ?? '';
            if (in_array($type, $textTypes)) {
                $textColumns[] = $column;
            }
        }

        return $textColumns;
    }

    /**
     * Select best fields for vectorization
     */
    protected function selectBestFields(array $textColumns, array $columnTypes): array
    {
        if (empty($textColumns)) {
            return [];
        }

        // Priority fields (most likely to contain meaningful content)
        $priorityFields = [
            'title', 'name', 'subject', 'headline',
            'content', 'body', 'description', 'text', 'message',
            'summary', 'excerpt', 'abstract',
        ];

        $selected = [];

        // Add priority fields that exist
        foreach ($priorityFields as $field) {
            if (in_array($field, $textColumns)) {
                $selected[] = $field;
            }
        }

        // If no priority fields found, use all text columns (up to 5)
        if (empty($selected)) {
            $selected = array_slice($textColumns, 0, 5);
        }

        return $selected;
    }

    /**
     * Clear cache for model
     */
    public function clearCache(Model $model): void
    {
        $cacheKey = 'vectorizable_fields_' . $model->getTable();
        Cache::forget($cacheKey);
    }
}
