<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Vector\VectorSearchService;

/**
 * Context Limitation Service
 * 
 * Dynamically generates and manages context limitations based on:
 * - Vector database content volume
 * - User permissions and access levels
 * - Model-specific constraints
 * - Token limits and performance
 */
class ContextLimitationService
{
    protected VectorSearchService $vectorSearch;
    
    public function __construct(VectorSearchService $vectorSearch)
    {
        $this->vectorSearch = $vectorSearch;
    }

    /**
     * Get context limitations for a user and model
     *
     * @param string|null $userId
     * @param string|null $modelClass
     * @return array
     */
    public function getContextLimitations(?string $userId = null, ?string $modelClass = null): array
    {
        $cacheKey = $this->getCacheKey($userId, $modelClass);
        
        return Cache::remember($cacheKey, 300, function () use ($userId, $modelClass) {
            return $this->generateContextLimitations($userId, $modelClass);
        });
    }

    /**
     * Generate context limitations dynamically
     *
     * @param string|null $userId
     * @param string|null $modelClass
     * @return array
     */
    protected function generateContextLimitations(?string $userId, ?string $modelClass): array
    {
        $limitations = [
            'max_results' => 10,
            'max_tokens' => 4000,
            'max_content_length' => 32000,
            'filters' => [],
            'excluded_fields' => [],
            'priority_fields' => [],
            'time_range' => null,
            'access_level' => 'default',
        ];

        // Analyze vector database
        $dbStats = $this->analyzeVectorDatabase($modelClass);
        
        // Adjust based on data volume
        $limitations = $this->adjustForDataVolume($limitations, $dbStats);
        
        // Apply user-specific limitations
        if ($userId) {
            $limitations = $this->applyUserLimitations($limitations, $userId, $modelClass);
        }
        
        // Apply model-specific constraints
        if ($modelClass) {
            $limitations = $this->applyModelConstraints($limitations, $modelClass);
        }
        
        // Optimize for performance
        $limitations = $this->optimizeForPerformance($limitations, $dbStats);
        
        Log::info('Generated context limitations', [
            'user_id' => $userId,
            'model_class' => $modelClass,
            'limitations' => $limitations,
        ]);
        
        return $limitations;
    }

    /**
     * Analyze vector database statistics
     *
     * @param string|null $modelClass
     * @return array
     */
    protected function analyzeVectorDatabase(?string $modelClass): array
    {
        $stats = [
            'total_records' => 0,
            'indexed_records' => 0,
            'avg_content_length' => 0,
            'total_collections' => 0,
            'data_density' => 'low',
        ];

        try {
            if ($modelClass && class_exists($modelClass)) {
                $stats['total_records'] = $modelClass::count();
                $stats['indexed_records'] = $this->vectorSearch->getIndexedCount($modelClass);
                $stats['avg_content_length'] = $this->calculateAverageContentLength($modelClass);
            } else {
                // Get stats for all vectorizable models
                $stats = $this->getGlobalVectorStats();
            }
            
            // Calculate data density
            $stats['data_density'] = $this->calculateDataDensity($stats);
            
        } catch (\Exception $e) {
            Log::warning('Failed to analyze vector database', [
                'error' => $e->getMessage()
            ]);
        }

        return $stats;
    }

    /**
     * Calculate average content length
     *
     * @param string $modelClass
     * @return int
     */
    protected function calculateAverageContentLength(string $modelClass): int
    {
        try {
            $sample = $modelClass::take(100)->get();
            
            if ($sample->isEmpty()) {
                return 0;
            }
            
            $totalLength = 0;
            $count = 0;
            
            foreach ($sample as $model) {
                if (method_exists($model, 'getVectorContent')) {
                    $content = $model->getVectorContent();
                    $totalLength += strlen($content);
                    $count++;
                }
            }
            
            return $count > 0 ? (int) ($totalLength / $count) : 0;
            
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get global vector statistics
     *
     * @return array
     */
    protected function getGlobalVectorStats(): array
    {
        $stats = [
            'total_records' => 0,
            'indexed_records' => 0,
            'avg_content_length' => 0,
            'total_collections' => 0,
        ];

        try {
            $discovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
            $collections = $discovery->discover();
            
            $stats['total_collections'] = count($collections);
            
            foreach ($collections as $modelClass) {
                if (class_exists($modelClass)) {
                    $stats['total_records'] += $modelClass::count();
                    $stats['indexed_records'] += $this->vectorSearch->getIndexedCount($modelClass);
                }
            }
            
        } catch (\Exception $e) {
            Log::debug('Failed to get global vector stats', ['error' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * Calculate data density
     *
     * @param array $stats
     * @return string
     */
    protected function calculateDataDensity(array $stats): string
    {
        $totalRecords = $stats['total_records'] ?? 0;
        
        if ($totalRecords < 100) {
            return 'low';
        } elseif ($totalRecords < 1000) {
            return 'medium';
        } elseif ($totalRecords < 10000) {
            return 'high';
        } else {
            return 'very_high';
        }
    }

    /**
     * Adjust limitations based on data volume
     *
     * @param array $limitations
     * @param array $stats
     * @return array
     */
    protected function adjustForDataVolume(array $limitations, array $stats): array
    {
        $density = $stats['data_density'];
        
        switch ($density) {
            case 'low':
                // Small dataset: return more results
                $limitations['max_results'] = 15;
                $limitations['max_tokens'] = 6000;
                break;
                
            case 'medium':
                // Medium dataset: balanced
                $limitations['max_results'] = 10;
                $limitations['max_tokens'] = 4000;
                break;
                
            case 'high':
                // Large dataset: focus on relevance
                $limitations['max_results'] = 7;
                $limitations['max_tokens'] = 3000;
                break;
                
            case 'very_high':
                // Very large dataset: strict limits
                $limitations['max_results'] = 5;
                $limitations['max_tokens'] = 2000;
                break;
        }
        
        // Adjust based on average content length
        $avgLength = $stats['avg_content_length'] ?? 0;
        if ($avgLength > 5000) {
            // Long content: reduce result count
            $limitations['max_results'] = max(3, $limitations['max_results'] - 2);
        }
        
        return $limitations;
    }

    /**
     * Apply user-specific limitations
     *
     * @param array $limitations
     * @param string $userId
     * @param string|null $modelClass
     * @return array
     */
    protected function applyUserLimitations(array $limitations, string $userId, ?string $modelClass): array
    {
        // Get user access level
        $accessLevel = $this->getUserAccessLevel($userId);
        $limitations['access_level'] = $accessLevel;
        
        // Apply access level constraints
        switch ($accessLevel) {
            case 'admin':
                // Admin: no restrictions
                $limitations['max_results'] = 20;
                $limitations['max_tokens'] = 8000;
                break;
                
            case 'premium':
                // Premium: higher limits
                $limitations['max_results'] = 15;
                $limitations['max_tokens'] = 6000;
                break;
                
            case 'basic':
                // Basic: standard limits
                $limitations['max_results'] = 10;
                $limitations['max_tokens'] = 4000;
                break;
                
            case 'guest':
                // Guest: restricted
                $limitations['max_results'] = 5;
                $limitations['max_tokens'] = 2000;
                break;
        }
        
        // Apply user-specific filters
        $limitations['filters'] = $this->getUserFilters($userId, $modelClass);
        
        // Apply time-based restrictions
        $limitations['time_range'] = $this->getUserTimeRange($userId);
        
        return $limitations;
    }

    /**
     * Get user access level
     *
     * @param string $userId
     * @return string
     */
    protected function getUserAccessLevel(string $userId): string
    {
        // Check if user model exists
        if (class_exists(\App\Models\User::class)) {
            try {
                $user = \App\Models\User::find($userId);
                
                if ($user) {
                    // Check for role or subscription
                    if (isset($user->role)) {
                        return $user->role;
                    }
                    
                    if (isset($user->subscription_level)) {
                        return $user->subscription_level;
                    }
                    
                    if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
                        return 'admin';
                    }
                }
            } catch (\Exception $e) {
                Log::debug('Failed to get user access level', ['error' => $e->getMessage()]);
            }
        }
        
        return 'basic';
    }

    /**
     * Get user-specific filters
     *
     * @param string $userId
     * @param string|null $modelClass
     * @return array
     */
    protected function getUserFilters(string $userId, ?string $modelClass): array
    {
        $filters = ['user_id' => $userId];
        
        // Apply model-specific user filters
        if ($modelClass && class_exists($modelClass)) {
            $model = new $modelClass;
            
            if (method_exists($model, 'applyUserFilters')) {
                $filters = array_merge($filters, $model->applyUserFilters($userId));
            }
        }
        
        return $filters;
    }

    /**
     * Get user time range restrictions
     *
     * @param string $userId
     * @return array|null
     */
    protected function getUserTimeRange(string $userId): ?array
    {
        // Example: restrict to last 30 days for basic users
        $accessLevel = $this->getUserAccessLevel($userId);
        
        if ($accessLevel === 'guest') {
            return [
                'from' => now()->subDays(7)->toDateTimeString(),
                'to' => now()->toDateTimeString(),
            ];
        } elseif ($accessLevel === 'basic') {
            return [
                'from' => now()->subDays(30)->toDateTimeString(),
                'to' => now()->toDateTimeString(),
            ];
        }
        
        // No time restrictions for premium/admin
        return null;
    }

    /**
     * Apply model-specific constraints
     *
     * @param array $limitations
     * @param string $modelClass
     * @return array
     */
    protected function applyModelConstraints(array $limitations, string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            return $limitations;
        }
        
        $model = new $modelClass;
        
        // Check for custom context limits
        if (property_exists($model, 'contextLimits')) {
            $limitations = array_merge($limitations, $model->contextLimits);
        }
        
        // Check for excluded fields
        if (property_exists($model, 'excludedFromContext')) {
            $limitations['excluded_fields'] = $model->excludedFromContext;
        }
        
        // Check for priority fields
        if (property_exists($model, 'priorityFields')) {
            $limitations['priority_fields'] = $model->priorityFields;
        }
        
        return $limitations;
    }

    /**
     * Optimize limitations for performance
     *
     * @param array $limitations
     * @param array $stats
     * @return array
     */
    protected function optimizeForPerformance(array $limitations, array $stats): array
    {
        // Ensure token limit doesn't exceed model capacity
        $maxModelTokens = config('ai-engine.chat.max_tokens', 4000);
        $limitations['max_tokens'] = min($limitations['max_tokens'], $maxModelTokens);
        
        // Ensure result count is reasonable for performance
        if ($stats['total_records'] > 100000) {
            $limitations['max_results'] = min($limitations['max_results'], 5);
        }
        
        // Adjust content length based on result count
        $estimatedTotalLength = $limitations['max_results'] * ($stats['avg_content_length'] ?? 1000);
        if ($estimatedTotalLength > $limitations['max_content_length']) {
            $limitations['max_results'] = max(3, (int) ($limitations['max_content_length'] / ($stats['avg_content_length'] ?? 1000)));
        }
        
        return $limitations;
    }

    /**
     * Invalidate cache for user/model
     *
     * @param string|null $userId
     * @param string|null $modelClass
     * @return void
     */
    public function invalidateCache(?string $userId = null, ?string $modelClass = null): void
    {
        $cacheKey = $this->getCacheKey($userId, $modelClass);
        Cache::forget($cacheKey);
        
        Log::info('Invalidated context limitations cache', [
            'user_id' => $userId,
            'model_class' => $modelClass,
        ]);
    }

    /**
     * Get cache key
     *
     * @param string|null $userId
     * @param string|null $modelClass
     * @return string
     */
    protected function getCacheKey(?string $userId, ?string $modelClass): string
    {
        $parts = ['context_limitations'];
        
        if ($userId) {
            $parts[] = 'user_' . $userId;
        }
        
        if ($modelClass) {
            $parts[] = 'model_' . md5($modelClass);
        }
        
        return implode(':', $parts);
    }

    /**
     * Get limitations summary for display
     *
     * @param string|null $userId
     * @param string|null $modelClass
     * @return array
     */
    public function getLimitationsSummary(?string $userId = null, ?string $modelClass = null): array
    {
        $limitations = $this->getContextLimitations($userId, $modelClass);
        
        return [
            'max_results' => $limitations['max_results'],
            'max_tokens' => $limitations['max_tokens'],
            'access_level' => $limitations['access_level'],
            'has_time_range' => !empty($limitations['time_range']),
            'has_filters' => !empty($limitations['filters']),
            'estimated_response_time' => $this->estimateResponseTime($limitations),
        ];
    }

    /**
     * Estimate response time based on limitations
     *
     * @param array $limitations
     * @return string
     */
    protected function estimateResponseTime(array $limitations): string
    {
        $results = $limitations['max_results'];
        $tokens = $limitations['max_tokens'];
        
        $estimatedMs = ($results * 50) + ($tokens / 10);
        
        if ($estimatedMs < 500) {
            return 'fast';
        } elseif ($estimatedMs < 2000) {
            return 'medium';
        } else {
            return 'slow';
        }
    }
}
