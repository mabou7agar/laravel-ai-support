<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NodeCacheService
{
    protected int $defaultTtl;
    
    public function __construct()
    {
        $this->defaultTtl = config('ai-engine.nodes.cache_ttl', 900); // 15 minutes
    }
    
    /**
     * Get cached search results
     */
    public function getCachedSearch(string $query, array $nodeIds, array $options = []): ?array
    {
        $key = $this->generateSearchKey($query, $nodeIds, $options);
        
        // Try memory cache first
        $cached = Cache::get($key);
        
        if ($cached) {
            // Increment hit count in database
            $this->incrementHitCount($key);
            
            Log::channel('ai-engine')->debug('Search cache hit', [
                'query_hash' => $key,
                'query' => substr($query, 0, 100),
            ]);
            
            return $cached;
        }
        
        // Try database cache
        $dbCache = DB::table('ai_node_search_cache')
            ->where('query_hash', $key)
            ->where('expires_at', '>', now())
            ->first();
        
        if ($dbCache) {
            $results = json_decode($dbCache->results, true);
            
            // Store in memory cache
            Cache::put($key, $results, $this->defaultTtl);
            
            // Increment hit count
            $this->incrementHitCount($key);
            
            Log::channel('ai-engine')->debug('Search cache hit (database)', [
                'query_hash' => $key,
            ]);
            
            return $results;
        }
        
        return null;
    }
    
    /**
     * Cache search results
     */
    public function cacheSearch(
        string $query,
        array $nodeIds,
        array $results,
        int $durationMs = null,
        array $options = []
    ): void {
        $key = $this->generateSearchKey($query, $nodeIds, $options);
        $ttl = $options['cache_ttl'] ?? $this->defaultTtl;
        
        // Store in memory cache
        Cache::put($key, $results, $ttl);
        
        // Store in database cache
        DB::table('ai_node_search_cache')->updateOrInsert(
            ['query_hash' => $key],
            [
                'query' => $query,
                'node_ids' => json_encode($nodeIds),
                'results' => json_encode($results),
                'result_count' => count($results['results'] ?? []),
                'duration_ms' => $durationMs,
                'expires_at' => now()->addSeconds($ttl),
                'hit_count' => 0,
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]
        );
        
        Log::channel('ai-engine')->debug('Search results cached', [
            'query_hash' => $key,
            'result_count' => count($results['results'] ?? []),
            'ttl' => $ttl,
        ]);
    }
    
    /**
     * Invalidate cache for a node
     */
    public function invalidateNode(AINode $node): void
    {
        // Clear memory cache with tag
        Cache::tags(["node:{$node->id}"])->flush();
        
        // Clear database cache entries that include this node
        DB::table('ai_node_search_cache')
            ->whereRaw("JSON_CONTAINS(node_ids, ?)", [json_encode($node->id)])
            ->delete();
        
        Log::channel('ai-engine')->info('Cache invalidated for node', [
            'node_id' => $node->id,
            'node_slug' => $node->slug,
        ]);
    }
    
    /**
     * Invalidate all cache
     */
    public function invalidateAll(): void
    {
        // Clear all memory cache
        Cache::flush();
        
        // Clear all database cache
        DB::table('ai_node_search_cache')->truncate();
        
        Log::channel('ai-engine')->info('All node cache invalidated');
    }
    
    /**
     * Clean expired cache entries
     */
    public function cleanExpired(): int
    {
        $deleted = DB::table('ai_node_search_cache')
            ->where('expires_at', '<', now())
            ->delete();
        
        Log::channel('ai-engine')->info('Expired cache entries cleaned', [
            'deleted_count' => $deleted,
        ]);
        
        return $deleted;
    }
    
    /**
     * Warm up cache for common queries
     */
    public function warmUp(array $commonQueries, ?array $nodeIds = null, callable $searchCallback): void
    {
        Log::channel('ai-engine')->info('Starting cache warm-up', [
            'query_count' => count($commonQueries),
        ]);
        
        foreach ($commonQueries as $query) {
            try {
                // Execute search and cache results
                $results = $searchCallback($query, $nodeIds);
                
                if ($results) {
                    $this->cacheSearch($query, $nodeIds ?? [], $results);
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Cache warm-up failed for query', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::channel('ai-engine')->info('Cache warm-up completed');
    }
    
    /**
     * Get cache statistics
     */
    public function getStatistics(): array
    {
        $now = now()->toDateTimeString();
        
        $stats = DB::table('ai_node_search_cache')
            ->selectRaw('
                COUNT(*) as total_entries,
                SUM(hit_count) as total_hits,
                AVG(hit_count) as avg_hits_per_entry,
                SUM(result_count) as total_results,
                AVG(duration_ms) as avg_duration_ms,
                COUNT(CASE WHEN expires_at > ? THEN 1 END) as active_entries,
                COUNT(CASE WHEN expires_at <= ? THEN 1 END) as expired_entries
            ', [$now, $now])
            ->first();
        
        return [
            'total_entries' => $stats->total_entries ?? 0,
            'active_entries' => $stats->active_entries ?? 0,
            'expired_entries' => $stats->expired_entries ?? 0,
            'total_hits' => $stats->total_hits ?? 0,
            'avg_hits_per_entry' => round($stats->avg_hits_per_entry ?? 0, 2),
            'total_results' => $stats->total_results ?? 0,
            'avg_duration_ms' => round($stats->avg_duration_ms ?? 0, 2),
            'hit_rate' => $this->calculateHitRate(),
        ];
    }
    
    /**
     * Get most popular cached queries
     */
    public function getPopularQueries(int $limit = 10): array
    {
        return DB::table('ai_node_search_cache')
            ->select('query', 'hit_count', 'result_count', 'created_at')
            ->where('expires_at', '>', now())
            ->orderBy('hit_count', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
    
    /**
     * Generate cache key
     */
    protected function generateSearchKey(string $query, array $nodeIds, array $options): string
    {
        sort($nodeIds);
        ksort($options);
        
        $data = [
            'query' => $query,
            'nodes' => $nodeIds,
            'options' => $options,
        ];
        
        return 'node_search:' . md5(json_encode($data));
    }
    
    /**
     * Increment hit count
     */
    protected function incrementHitCount(string $key): void
    {
        DB::table('ai_node_search_cache')
            ->where('query_hash', $key)
            ->increment('hit_count');
    }
    
    /**
     * Calculate cache hit rate
     */
    protected function calculateHitRate(): float
    {
        // This would need request tracking to calculate accurately
        // For now, return a placeholder
        return 0.0;
    }
}
