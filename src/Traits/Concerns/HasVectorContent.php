<?php

declare(strict_types=1);

namespace LaravelAIEngine\Traits\Concerns;

trait HasVectorContent
{
    /**
     * Get the collection name for vector storage
     * Always includes the configured prefix (default: vec_)
     * Override this method for fully custom collection names
     *
     * @return string
     */
    public function getVectorCollectionName(): string
    {
        // Get base collection name with prefix
        $prefix = config('ai-engine.vector.collection_prefix', 'vec_');
        $baseCollection = $this->getBaseVectorCollectionName();
        
        // Ensure prefix is applied (avoid double prefix)
        if (!str_starts_with($baseCollection, $prefix)) {
            $baseCollection = $prefix . $baseCollection;
        }

        // Apply multi-db tenancy prefix if enabled
        if (config('vector-access-control.multi_db_tenancy', false)) {
            $tenantService = app(\LaravelAIEngine\Services\Tenant\MultiTenantVectorService::class);
            return $tenantService->getTenantCollectionName($baseCollection);
        }

        return $baseCollection;
    }

    /**
     * Get the base collection name (without vec_ prefix, but may include tenant prefix)
     * Override this method for custom collection names
     *
     * @return string
     */
    public function getBaseVectorCollectionName(): string
    {
        // Use table name as collection name by default
        if (property_exists($this, 'table') && !empty($this->table)) {
            return $this->table;
        }

        // Fallback to class name
        $className = class_basename($this);
        return strtolower(str_replace('\\', '_', $className));
    }

    /**
     * Get chunked content for vectorization
     * Returns array of chunks for 'split' strategy
     * Each chunk is suitable for separate embedding
     */
    public function getVectorContentChunks(): array
    {
        $strategy = config('ai-engine.vectorization.strategy', 'split');

        if ($strategy === 'truncate') {
            return [$this->getVectorContent()];
        }

        // Get full content without truncation
        $fullContent = $this->getFullVectorContent();

        // Split into chunks
        return $this->splitIntoChunks($fullContent);
    }

    /**
     * Get content to be vectorized
     * Returns full content - chunking is handled by VectorSearchService
     * Override this method for custom content generation
     */
    public function getVectorContent(): string
    {
        $fullContent = $this->getFullVectorContent();

        // Apply strategy
        $strategy = config('ai-engine.vectorization.strategy', 'split');

        if ($strategy === 'split') {
            // Return full content - VectorSearchService handles chunking for embedding
            // Large content will be intelligently chunked (beginning + middle + end)
            return $fullContent;
        }

        // Truncate strategy - only use if explicitly configured
        return $this->truncateContent($fullContent);
    }

    /**
     * Get full vector content without truncation
     * Used internally by both strategies
     */
    protected function getFullVectorContent(): string
    {
        $content = [];
        $usedFields = [];
        $source = '';

        $maxFieldSize = config('ai-engine.vectorization.max_field_size', 100000); // 100KB per field
        $chunkedFields = [];

        // Priority 1: If vectorizable is explicitly set, use it
        if (!empty($this->vectorizable)) {
            $source = 'explicit $vectorizable property';
            foreach ($this->vectorizable as $field) {
                if (isset($this->$field)) {
                    $fieldValue = $this->$field;

                    // Convert non-string values to strings
                    if (is_array($fieldValue) || is_object($fieldValue)) {
                        $fieldValue = json_encode($fieldValue);
                    }
                    elseif (!is_string($fieldValue)) {
                        $fieldValue = (string) $fieldValue;
                    }

                    $fieldSize = strlen($fieldValue);

                    // Chunk fields that are too large
                    if ($fieldSize > $maxFieldSize) {
                        $chunked = $this->chunkLargeField($fieldValue, $maxFieldSize);
                        $content[] = $chunked;
                        $chunkedFields[] = ['field' => $field, 'original_size' => $fieldSize, 'chunked_size' => strlen($chunked)];
                    } else {
                        $content[] = $fieldValue;
                    }

                    $usedFields[] = $field;
                }
            }
        }
        // Priority 2: Use $fillable if available (smart default)
        elseif (!empty($this->getFillable())) {
            $source = '$fillable property (smart default)';

            // Filter fillable to only text-based fields
            $fillableTextFields = $this->filterFillableToTextFields($this->getFillable());

            foreach ($fillableTextFields as $field) {
                if (isset($this->$field)) {
                    $fieldValue = $this->$field;

                    // Convert non-string values to strings
                    if (is_array($fieldValue) || is_object($fieldValue)) {
                        $fieldValue = json_encode($fieldValue);
                    } elseif ($fieldValue === null) {
                        continue; // Skip null values
                    } elseif (!is_string($fieldValue)) {
                        $fieldValue = (string) $fieldValue;
                    }

                    $fieldSize = strlen($fieldValue);

                    // Chunk fields that are too large
                    if ($fieldSize > $maxFieldSize) {
                        $chunked = $this->chunkLargeField($fieldValue, $maxFieldSize);
                        $content[] = $chunked;
                        $chunkedFields[] = ['field' => $field, 'original_size' => $fieldSize, 'chunked_size' => strlen($chunked)];
                    } else {
                        $content[] = $fieldValue;
                    }

                    $usedFields[] = $field;
                }
            }
        }
        // Priority 3: Auto-detect vectorizable fields
        else {
            $source = 'auto-detected fields';
            // Auto-detect vectorizable fields if not set
            $autoFields = $this->autoDetectVectorizableFields();

            if (!empty($autoFields)) {
                $source = 'auto-detected fields';
                foreach ($autoFields as $field) {
                    if (isset($this->$field)) {
                        $fieldValue = $this->$field;

                        // Convert non-string values to strings
                        if (is_array($fieldValue) || is_object($fieldValue)) {
                            $fieldValue = json_encode($fieldValue);
                        } elseif ($fieldValue === null) {
                            continue; // Skip null values
                        } elseif (!is_string($fieldValue)) {
                            $fieldValue = (string) $fieldValue;
                        }

                        $fieldSize = strlen($fieldValue);

                        // Chunk fields that are too large
                        if ($fieldSize > $maxFieldSize) {
                            $chunked = $this->chunkLargeField($fieldValue, $maxFieldSize);
                            $content[] = $chunked;
                            $chunkedFields[] = ['field' => $field, 'original_size' => $fieldSize, 'chunked_size' => strlen($chunked)];
                        } else {
                            $content[] = $fieldValue;
                        }

                        $usedFields[] = $field;
                    }
                }
            }
        }

        // If no content yet, fallback to common text fields
        if (empty($content)) {
            $source = 'fallback common fields';
            $commonFields = ['title', 'name', 'content', 'description', 'body', 'text'];
            foreach ($commonFields as $field) {
                if (isset($this->$field)) {
                    $fieldValue = $this->$field;
                    $fieldSize = strlen($fieldValue);

                    // Chunk fields that are too large
                    if ($fieldSize > $maxFieldSize) {
                        $chunked = $this->chunkLargeField($fieldValue, $maxFieldSize);
                        $content[] = $chunked;
                        $chunkedFields[] = ['field' => $field, 'original_size' => $fieldSize, 'chunked_size' => strlen($chunked)];
                    } else {
                        $content[] = $fieldValue;
                    }

                    $usedFields[] = $field;
                }
            }
        }

        // Add media content if HasMediaEmbeddings trait is used
        // This automatically integrates media without requiring explicit configuration
        $hasMedia = false;
        if (method_exists($this, 'getMediaVectorContent')) {
            try {
                $mediaContent = $this->getMediaVectorContent();
                if (!empty($mediaContent)) {
                    $content[] = $mediaContent;
                    $hasMedia = true;

                    if (config('ai-engine.debug')) {
                        \Log::channel('ai-engine')->debug('Media content integrated', [
                            'model' => get_class($this),
                            'id' => $this->id ?? 'new',
                            'media_content_length' => strlen($mediaContent),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Gracefully handle media processing errors
                \Log::channel('ai-engine')->warning('Media processing failed, continuing with text only', [
                    'model' => get_class($this),
                    'id' => $this->id ?? 'new',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $fullContent = implode(' ', $content);

        // Gate the vectorization summary log behind the debug flag so production
        // logs are not spammed on every model save.
        if (config('ai-engine.vectorization.debug', false) || config('ai-engine.debug', false)) {
            \Log::channel('ai-engine')->debug('📊 Vectorization Summary', [
                'model' => class_basename($this),
                'model_id' => $this->id ?? 'new',
                'source' => $source,
                'indexed_fields' => $usedFields,
                'field_count' => count($usedFields),
                'total_content_length' => strlen($fullContent),
                'has_media' => $hasMedia ?? false,
                'chunked_fields' => !empty($chunkedFields) ? $chunkedFields : null,
            ]);
        }

        if (config('ai-engine.debug')) {
            \Log::channel('ai-engine')->debug('Full vector content generated', [
                'model' => get_class($this),
                'id' => $this->id ?? 'new',
                'source' => $source,
                'fields_used' => $usedFields,
                'fields_chunked' => $chunkedFields,
                'has_media' => $hasMedia,
                'content_length' => strlen($fullContent),
            ]);
        }

        // Log info if fields were chunked
        if (!empty($chunkedFields)) {
            \Log::channel('ai-engine')->info('Large fields chunked during vectorization', [
                'model' => get_class($this),
                'id' => $this->id ?? 'new',
                'chunked_fields' => $chunkedFields,
            ]);
        }

        return $fullContent;
    }

    /**
     * Split content into chunks for multiple embeddings
     */
    protected function splitIntoChunks(string $content): array
    {
        if (empty($content)) {
            return [];
        }

        $embeddingModel = config('ai-engine.vector.embedding_model', 'text-embedding-3-small');
        $tokenLimit = $this->getModelTokenLimit($embeddingModel);

        // Calculate chunk size (leave 10% buffer for safety)
        $chunkSize = config('ai-engine.vectorization.chunk_size');
        if (!$chunkSize) {
            // Auto-calculate: 90% of token limit converted to chars
            $chunkSize = (int) ($tokenLimit * 0.9 * 1.3); // 1.3 chars per token average
        }

        $overlap = config('ai-engine.vectorization.chunk_overlap', 200);

        // If content fits in one chunk, return it
        if (strlen($content) <= $chunkSize) {
            return [$content];
        }

        $chunks = [];
        $position = 0;
        $contentLength = strlen($content);

        while ($position < $contentLength) {
            // Extract chunk
            $chunk = substr($content, $position, $chunkSize);

            // Try to break at sentence boundary
            if ($position + $chunkSize < $contentLength) {
                $lastPeriod = strrpos($chunk, '.');
                $lastNewline = strrpos($chunk, "\n");
                $breakPoint = max($lastPeriod, $lastNewline);

                if ($breakPoint !== false && $breakPoint > $chunkSize * 0.8) {
                    $chunk = substr($chunk, 0, $breakPoint + 1);
                    $position += $breakPoint + 1;
                } else {
                    $position += $chunkSize;
                }
            } else {
                $position = $contentLength;
            }

            $chunks[] = trim($chunk);

            // Move back by overlap amount for next chunk
            if ($position < $contentLength) {
                $position -= $overlap;
            }
        }

        if (config('ai-engine.debug')) {
            \Log::channel('ai-engine')->info('Content split into chunks', [
                'model' => get_class($this),
                'id' => $this->id ?? 'new',
                'total_length' => $contentLength,
                'chunk_count' => count($chunks),
                'chunk_size' => $chunkSize,
                'overlap' => $overlap,
                'chunk_lengths' => array_map('strlen', $chunks),
            ]);
        }

        return $chunks;
    }

    /**
     * Filter sensitive fields from fillable array
     * Includes ALL fields except passwords and tokens
     *
     * @param array $fillable
     * @return array
     */
    protected function filterFillableToTextFields(array $fillable): array
    {
        // Only skip truly sensitive fields - include everything else!
        $skipFields = [
            'password',
            'remember_token',
            'api_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'email_verification_token',
            'password_reset_token',
        ];

        // Include ALL other fields (id, timestamps, numbers, booleans, etc.)
        return array_values(array_diff($fillable, $skipFields));
    }

    /**
     * Chunk large field content intelligently
     * Takes beginning and end of content to preserve context
     * Uses semantic separator that won't interfere with embeddings
     *
     * @param string $content
     * @param int $maxSize
     * @return string
     */
    protected function chunkLargeField(string $content, int $maxSize): string
    {
        if (strlen($content) <= $maxSize) {
            return $content;
        }

        // Strategy: Take beginning and end, join with space
        // This preserves semantic meaning for embeddings

        // Calculate sizes (leave room for separator)
        $separatorSize = 50; // Space for separator
        $availableSize = $maxSize - $separatorSize;

        // Take 70% from beginning and 30% from end to preserve context
        $beginningSize = (int) ($availableSize * 0.7);
        $endSize = (int) ($availableSize * 0.3);

        $beginning = substr($content, 0, $beginningSize);
        $end = substr($content, -$endSize);

        // Try to cut at sentence boundaries for better context
        $lastPeriod = strrpos($beginning, '.');
        if ($lastPeriod !== false && $lastPeriod > $beginningSize * 0.8) {
            $beginning = substr($beginning, 0, $lastPeriod + 1);
        }

        $firstPeriod = strpos($end, '.');
        if ($firstPeriod !== false && $firstPeriod < $endSize * 0.2) {
            $end = substr($end, $firstPeriod + 1);
        }

        // Use simple space separator instead of marker
        // This maintains semantic flow for embeddings
        // The embedding model will naturally understand the content
        return trim($beginning) . ' ' . trim($end);
    }

    /**
     * Truncate content to safe size for vector embedding
     * Dynamically adjusts based on the embedding model being used
     *
     * @param string $content
     * @return string
     */
    protected function truncateContent(string $content): string
    {
        // Get the embedding model from config
        $embeddingModel = config('ai-engine.vector.embedding_model', 'text-embedding-3-small');

        // Get token limit for the model
        $tokenLimit = $this->getModelTokenLimit($embeddingModel);

        // Convert tokens to characters (conservative estimate)
        // Use 1.3 chars per token to stay safe
        $maxChars = (int) ($tokenLimit * 1.3);

        // Allow config override
        $maxChars = config('ai-engine.vectorization.max_content_length', $maxChars);

        if (strlen($content) <= $maxChars) {
            return $content;
        }

        // Truncate and add indicator
        $truncated = substr($content, 0, $maxChars);

        // Try to cut at last complete sentence
        $lastPeriod = strrpos($truncated, '.');
        $lastNewline = strrpos($truncated, "\n");
        $cutPoint = max($lastPeriod, $lastNewline);

        if ($cutPoint !== false && $cutPoint > $maxChars * 0.8) {
            $truncated = substr($truncated, 0, $cutPoint + 1);
        }

        if (config('ai-engine.vectorization.debug', false) || config('ai-engine.debug', false)) {
            \Log::debug('Truncated vector content', [
                'model' => get_class($this),
                'original_length' => strlen($content),
                'truncated_length' => strlen($truncated),
                'max_chars' => $maxChars
            ]);
        }

        return $truncated;
    }

    /**
     * Get token limit for embedding model from database
     * Falls back to hardcoded limits if model not found in DB
     *
     * @param string $model
     * @return int
     */
    protected function getModelTokenLimit(string $model): int
    {
        // Try to get from database first (with caching)
        try {
            $aiModel = \LaravelAIEngine\Models\AIModel::findByModelId($model);

            if ($aiModel && isset($aiModel->context_window['input'])) {
                return (int) $aiModel->context_window['input'];
            }

            if ($aiModel && isset($aiModel->max_tokens)) {
                return (int) $aiModel->max_tokens;
            }
        } catch (\Exception $e) {
            // Database might not be set up yet, fall through to hardcoded limits
            \Log::channel('ai-engine')->debug('Could not fetch model from database, using fallback', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to hardcoded limits for common embedding models
        $limits = [
            // OpenAI Models
            'text-embedding-3-small' => 8191,
            'text-embedding-3-large' => 8191,
            'text-embedding-ada-002' => 8191,

            // Cohere Models
            'embed-english-v3.0' => 512,
            'embed-multilingual-v3.0' => 512,

            // Voyage AI Models
            'voyage-large-2' => 16000,
            'voyage-code-2' => 16000,
            'voyage-2' => 4000,

            // Mistral Models
            'mistral-embed' => 8192,

            // Jina AI Models
            'jina-embeddings-v2-base-en' => 8192,
            'jina-embeddings-v2-small-en' => 8192,
        ];

        // Check if model has known limit
        if (isset($limits[$model])) {
            return $limits[$model];
        }

        // Check for model family patterns
        if (str_contains($model, 'text-embedding-3') || str_contains($model, 'text-embedding-ada')) {
            return 8191;
        }

        if (str_contains($model, 'voyage')) {
            return 4000;
        }

        if (str_contains($model, 'cohere') || str_contains($model, 'embed-')) {
            return 512;
        }

        if (str_contains($model, 'jina')) {
            return 8192;
        }

        // Default to conservative limit
        \Log::channel('ai-engine')->warning('Unknown embedding model, using default token limit', [
            'model' => $model,
            'default_limit' => 4000,
        ]);

        return 4000; // Safe default
    }

    /**
     * Get model schema for AI to understand available fields
     * 
     * Returns column names with their types so AI can intelligently
     * decide which fields to use for filtering.
     * 
     * @return array<string, string> Column name => type
     */
    public function getModelSchema(): array
    {
        $table = $this->getTable();
        $columns = \Schema::getColumnListing($table);
        
        $schema = [];
        foreach ($columns as $column) {
            try {
                $type = \Schema::getColumnType($table, $column);
                $schema[$column] = $type;
            } catch (\Exception $e) {
                $schema[$column] = 'unknown';
            }
        }
        
        return $schema;
    }

    /**
     * Auto-detect which fields should be vectorized using AI
     *
     * @return array
     */
    protected function autoDetectVectorizableFields(): array
    {
        $modelClass = get_class($this);
        $tableName = $this->getTable();

        // Check cache first
        $cacheKey = 'vectorizable_fields_' . $tableName;

        if (\Cache::has($cacheKey)) {
            $cachedFields = \Cache::get($cacheKey);

            if (config('ai-engine.debug')) {
                \Log::channel('ai-engine')->debug('Auto-detect vectorizable fields (from cache)', [
                    'model' => $modelClass,
                    'table' => $tableName,
                    'detected_fields' => $cachedFields,
                    'source' => 'cache'
                ]);
            }

            return $cachedFields;
        }

        try {
            // Get table columns
            $columns = \Schema::getColumnListing($tableName);

            if (empty($columns)) {
                \Log::channel('ai-engine')->warning('No columns found for auto-detection', [
                    'model' => $modelClass,
                    'table' => $tableName,
                ]);
                return [];
            }

            // Get column types
            $columnInfo = [];
            foreach ($columns as $column) {
                try {
                    $type = \Schema::getColumnType($tableName, $column);
                    $columnInfo[$column] = $type;
                } catch (\Exception $e) {
                    // Skip columns that cause errors
                    continue;
                }
            }

            // Filter to text-based columns only
            $textColumns = array_filter($columnInfo, function($type, $column) {
                // Skip common non-vectorizable columns
                $skipColumns = [
                    'id', 'created_at', 'updated_at', 'deleted_at',
                    'password', 'remember_token', 'email_verified_at',
                    'raw_body', 'raw_data', 'raw_email', // Skip raw fields (contain attachments)
                    'binary_data', 'file_data', 'attachment_data', // Skip binary data
                ];
                if (in_array($column, $skipColumns)) {
                    return false;
                }

                // Include text-based types
                $textTypes = ['string', 'text', 'longtext', 'mediumtext', 'varchar', 'char'];
                return in_array(strtolower($type), $textTypes);
            }, ARRAY_FILTER_USE_BOTH);

            $textColumnNames = array_keys($textColumns);

            // Always include certain important text fields if they exist
            $alwaysIncludeText = ['email', 'username', 'phone', 'address', 'bio', 'description'];
            $forceIncludedText = array_intersect($alwaysIncludeText, $textColumnNames);

            // Always include foreign key fields (for linking/relationships)
            $foreignKeyFields = [];
            foreach ($columns as $column) {
                // Include fields ending with _id (user_id, post_id, etc.)
                if (preg_match('/_id$/', $column) && $column !== 'id') {
                    $foreignKeyFields[] = $column;
                }
            }

            $forceIncluded = array_merge($forceIncludedText, $foreignKeyFields);

            if (!empty($forceIncluded)) {
                \Log::channel('ai-engine')->info('Auto-detect: Force-including important fields', [
                    'model' => $modelClass,
                    'force_included_text' => $forceIncludedText,
                    'force_included_foreign_keys' => $foreignKeyFields,
                    'total_force_included' => $forceIncluded,
                ]);
            }

            $textColumnNames = array_keys($textColumns);

            if (config('ai-engine.debug', false)) {
                \Log::channel('ai-engine')->debug('Auto-detect: Found text columns', [
                    'model' => $modelClass,
                    'table' => $tableName,
                    'all_columns' => count($columns),
                    'text_columns' => $textColumnNames,
                    'column_types' => $textColumns,
                ]);
            }

            // If no text columns found, return empty
            if (empty($textColumnNames)) {
                \Log::channel('ai-engine')->warning('Auto-detect: No text columns found', [
                    'model' => $modelClass,
                    'table' => $tableName,
                ]);
                return [];
            }

            // Use AI to decide which fields to vectorize — but ONLY when running inside
            // an explicit artisan indexing flow (setIndexingContext(true)).  During normal
            // model saves in production we fall back to heuristic selection so that no
            // unexpected AI API call is ever made implicitly.
            if (static::$indexingContext) {
                $selectedFields = $this->useAIToSelectFields($textColumnNames, $columnInfo);
            } else {
                if (config('ai-engine.debug', false)) {
                    \Log::channel('ai-engine')->debug('Auto-detect: Skipping AI field selection (not in indexing context), using heuristic', [
                        'model' => $modelClass,
                        'table' => $tableName,
                    ]);
                }
                $selectedFields = $this->heuristicFieldSelection($textColumnNames);
            }

            if (config('ai-engine.debug', false)) {
                \Log::channel('ai-engine')->debug('Auto-detect: Selected fields for vectorization', [
                    'model' => $modelClass,
                    'table' => $tableName,
                    'selected_fields' => $selectedFields,
                    'total_selected' => count($selectedFields),
                    'source' => static::$indexingContext ? 'AI analysis' : 'heuristic (no indexing context)',
                ]);
            }

            // Cache for 24 hours
            \Cache::put($cacheKey, $selectedFields, now()->addDay());

            return $selectedFields;

        } catch (\Exception $e) {
            \Log::warning('Failed to auto-detect vectorizable fields', [
                'model' => get_class($this),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Use AI to intelligently select which fields should be vectorized
     *
     * @param array $textColumns
     * @param array $columnInfo
     * @return array
     */
    protected function useAIToSelectFields(array $textColumns, array $columnInfo): array
    {
        try {
            $modelClass = get_class($this);
            $tableName = $this->getTable();

            // Build column description (limit to prevent token overflow)
            $columnDescriptions = [];
            $maxColumns = 50; // Limit columns in prompt
            $columnsToAnalyze = array_slice($textColumns, 0, $maxColumns);

            foreach ($columnsToAnalyze as $column) {
                $type = $columnInfo[$column] ?? 'unknown';
                $columnDescriptions[] = "- {$column} ({$type})";
            }

            if (count($textColumns) > $maxColumns) {
                $columnDescriptions[] = "... and " . (count($textColumns) - $maxColumns) . " more columns";
            }

            $columnsList = implode("\n", $columnDescriptions);
            
            $prompt = <<<PROMPT
You are analyzing a database table to determine which fields should be included in vector search indexing.

Model: {$modelClass}
Table: {$tableName}

Available text columns:
{$columnsList}

Task: Select which columns should be vectorized for semantic search. Consider:
1. Fields containing meaningful text content (descriptions, messages, titles, names, etc.)
2. Fields users would want to search by semantic meaning
3. Exclude: IDs, tokens, hashes, technical codes, URLs (unless they're the main content)
4. Include: Subject lines, body text, names, descriptions, comments, messages, titles
5. Select maximum 5-7 most important fields to avoid content overload

Respond with ONLY a JSON array of column names, nothing else.
Example: ["subject", "body", "description"]

Selected columns:
PROMPT;

            // Use AI to analyze
            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from(config('ai-engine.default', 'openai')),
                model: new \LaravelAIEngine\Enums\EntityEnum('gpt-4o-mini')
            );

            $response = app(\LaravelAIEngine\Services\AIEngineService::class)->generate($aiRequest);
            $content = trim($response->getContent());

            // Extract JSON from response
            if (preg_match('/\[.*\]/s', $content, $matches)) {
                $selectedFields = json_decode($matches[0], true);

                if (is_array($selectedFields)) {
                    // Validate that selected fields exist in our text columns
                    $validFields = array_intersect($selectedFields, $textColumns);

                    if (!empty($validFields)) {
                        \Log::info('AI selected vectorizable fields', [
                            'model' => $modelClass,
                            'fields' => $validFields
                        ]);
                        return array_values($validFields);
                    }
                }
            }

            // Fallback: use heuristic selection
            return $this->heuristicFieldSelection($textColumns);

        } catch (\Exception $e) {
            \Log::warning('AI field selection failed, using heuristic', [
                'model' => get_class($this),
                'error' => $e->getMessage()
            ]);

            return $this->heuristicFieldSelection($textColumns);
        }
    }

    /**
     * Heuristic-based field selection as fallback
     *
     * @param array $textColumns
     * @return array
     */
    protected function heuristicFieldSelection(array $textColumns): array
    {
        $priorityPatterns = [
            '/^(subject|title|name|heading)$/i' => 10,
            '/^(body|content|text|message|description|summary)$/i' => 9,
            '/^(comment|note|remark|caption)$/i' => 8,
            '/_?(text|content|body|description)$/i' => 7,
            '/^(from|to)_?(name|address)$/i' => 6,
        ];

        $scoredFields = [];

        foreach ($textColumns as $column) {
            $score = 0;

            foreach ($priorityPatterns as $pattern => $points) {
                if (preg_match($pattern, $column)) {
                    $score = max($score, $points);
                }
            }

            if ($score > 0) {
                $scoredFields[$column] = $score;
            }
        }

        // Sort by score descending
        arsort($scoredFields);

        // Take top fields (max 5 to avoid too much content)
        $selectedFields = array_slice(array_keys($scoredFields), 0, 5);

        if (!empty($selectedFields)) {
            \Log::info('Heuristic selected vectorizable fields', [
                'model' => get_class($this),
                'fields' => $selectedFields
            ]);
        }

        return $selectedFields;
    }

    /**
     * Get metadata for vector storage
     * Override this method for custom metadata
     */
    public function getVectorMetadata(): array
    {
        $metadata = [];

        // SECURITY: Add user ownership metadata
        // Use integer for numeric IDs (better Qdrant filtering), string for UUIDs
        if (isset($this->user_id)) {
            $metadata['user_id'] = is_numeric($this->user_id) ? (int) $this->user_id : (string) $this->user_id;
        }

        // MULTI-TENANT: Add tenant/organization metadata
        if (isset($this->tenant_id)) {
            $metadata['tenant_id'] = is_numeric($this->tenant_id) ? (int) $this->tenant_id : (string) $this->tenant_id;
        } elseif (isset($this->organization_id)) {
            $metadata['tenant_id'] = is_numeric($this->organization_id) ? (int) $this->organization_id : (string) $this->organization_id;
        } elseif (isset($this->company_id)) {
            $metadata['tenant_id'] = is_numeric($this->company_id) ? (int) $this->company_id : (string) $this->company_id;
        } elseif (isset($this->team_id)) {
            $metadata['tenant_id'] = is_numeric($this->team_id) ? (int) $this->team_id : (string) $this->team_id;
        }

        // WORKSPACE: Add workspace metadata for workspace-scoped access
        if (isset($this->workspace_id)) {
            $metadata['workspace_id'] = is_numeric($this->workspace_id) ? (int) $this->workspace_id : (string) $this->workspace_id;
        }
        // Also index workspace relationship if it exists
        if (isset($this->workspace) && is_object($this->workspace)) {
            if (isset($this->workspace->id)) {
                $metadata['workspace_id'] = is_numeric($this->workspace->id) ? (int) $this->workspace->id : (string) $this->workspace->id;
            }
            if (isset($this->workspace->name)) {
                $metadata['workspace_name'] = $this->workspace->name;
            }
        }

        // Add common metadata
        if (isset($this->status)) {
            $metadata['status'] = $this->status;
        }

        if (isset($this->category_id)) {
            $metadata['category_id'] = $this->category_id;
        }

        if (isset($this->type)) {
            $metadata['type'] = $this->type;
        }

        // Add visibility/access control metadata
        if (isset($this->is_public)) {
            $metadata['is_public'] = $this->is_public;
        }

        if (isset($this->visibility)) {
            $metadata['visibility'] = $this->visibility;
        }

        // MULTI-DB TENANCY: Add tenant metadata if enabled
        if (config('vector-access-control.multi_db_tenancy', false)) {
            try {
                $tenantService = app(\LaravelAIEngine\Services\Tenant\MultiTenantVectorService::class);
                $tenantMetadata = $tenantService->buildTenantMetadata();
                $metadata = array_merge($metadata, $tenantMetadata);
            } catch (\Exception $e) {
                // Silently ignore if service not available
            }
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
