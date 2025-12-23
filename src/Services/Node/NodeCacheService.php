<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NodeCacheService
{
    protected int $defaultTtl;
    protected bool $cacheEnabled;
    protected ?string $cacheDriver;
    protected ?string $cacheStore;
    protected string $cachePrefix;
    protected bool $useDatabase;
    protected bool $useTags;
    protected ?CacheRepository $cache = null;
    
    public function __construct()
    {
        $this->defaultTtl = config('ai-engine.nodes.cache_ttl', 900); // 15 minutes
        $this->cacheEnabled = config('ai-engine.nodes.cache.enabled', true);
        $this->cacheDriver = config('ai-engine.nodes.cache.driver');
        $this->cacheStore = config('ai-engine.nodes.cache.store');
        $this->cachePrefix = config('ai-engine.nodes.cache.prefix', 'ai_engine');
        $this->useDatabase = config('ai-engine.nodes.cache.use_database', false);
        $this->useTags = config('ai-engine.nodes.cache.use_tags', false);
        
        $this->initializeCache();
    }
    
    /**
     * Initialize the cache repository based on configuration
     */
    protected function initializeCache(): void
    {
        try {
            if ($this->cacheStore) {
                // Use specific cache store from config/cache.php
                $this->cache = Cache::store($this->cacheStore);
            } elseif ($this->cacheDriver) {
                // Use specific driver
                $this->cache = Cache::store($this->cacheDriver);
            } else {
                // Use default cache
                $this->cache = Cache::store();
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to initialize cache, using default', [
                'error' => $e->getMessage(),
                'store' => $this->cacheStore,
                'driver' => $this->cacheDriver,
            ]);
            $this->cache = Cache::store();
        }
    }
    
    /**
     * Check if cache tags are supported by the current driver
     */
    protected function supportsTagging(): bool
    {
        if (!$this->useTags) {
            return false;
        }
        
        try {
            // Only Redis and Memcached support tagging
            $driver = $this->cache->getStore();
            return $driver instanceof \Illuminate\Cache\RedisStore 
                || $driver instanceof \Illuminate\Cache\MemcachedStore;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if database table exists
     */
    protected function databaseTableExists(): bool
    {
        static $exists = null;
        
        if ($exists === null) {
            try {
                $exists = Schema::hasTable('ai_node_search_cache');
            } catch (\Exception $e) {
                $exists = false;
            }
        }
        
        return $exists;
    }
    
    /**
     * Get cached search results
     */
    public function getCachedSearch(string $query, array $nodeIds, array $options = []): ?array
    {
        if (!$this->cacheEnabled) {
            return null;
        }
        
        $key = $this->generateSearchKey($query, $nodeIds, $options);
        
        // Try cache first
        try {
            $cached = $this->cache->get($key);
            
            if ($cached) {
                // Increment hit count in database if enabled
                if ($this->useDatabase && $this->databaseTableExists()) {
                    $this->incrementHitCount($key);
                }
                
                Log::channel('ai-engine')->debug('Search cache hit', [
                    'query_hash' => $key,
                    'query' => substr($query, 0, 100),
                ]);
                
                return $cached;
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Cache get failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Try database cache if enabled
        if ($this->useDatabase && $this->databaseTableExists()) {
            try {
                $dbCache = DB::table('ai_node_search_cache')
                    ->where('query_hash', $key)
                    ->where('expires_at', '>', now())
                    ->first();
                
                if ($dbCache) {
                    $results = json_decode($dbCache->results, true);
                    
                    // Store in memory cache
                    $this->cache->put($key, $results, $this->defaultTtl);
                    
                    // Increment hit count
                    $this->incrementHitCount($key);
                    
                    Log::channel('ai-engine')->debug('Search cache hit (database)', [
                        'query_hash' => $key,
                    ]);
                    
                    return $results;
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Database cache get failed', [
                    'error' => $e->getMessage(),
                ]);
            }
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
        if (!$this->cacheEnabled) {
            return;
        }
        
        $key = $this->generateSearchKey($query, $nodeIds, $options);
        $ttl = $options['cache_ttl'] ?? $this->defaultTtl;
        
        // Store in cache
        try {
            $this->cache->put($key, $results, $ttl);
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Cache put failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Store in database cache if enabled
        if ($this->useDatabase && $this->databaseTableExists()) {
            try {
                $existing = DB::table('ai_node_search_cache')
                    ->where('query_hash', $key)
                    ->first();

                $data = [
                    'query' => $query,
                    'node_ids' => json_encode($nodeIds),
                    'results' => json_encode($results),
                    'result_count' => count($results['results'] ?? []),
                    'duration_ms' => $durationMs,
                    'expires_at' => now()->addSeconds($ttl),
                    'hit_count' => 0,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('ai_node_search_cache')
                        ->where('query_hash', $key)
                        ->update($data);
                } else {
                    $data['query_hash'] = $key;
                    $data['created_at'] = now();
                    DB::table('ai_node_search_cache')->insert($data);
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Database cache put failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::channel('ai-engine')->debug('Search results cached', [
            'query_hash' => $key,
            'result_count' => count($results['results'] ?? []),
            'ttl' => $ttl,
            'driver' => $this->cacheDriver ?? 'default',
        ]);
    }
    
    /**
     * Invalidate cache for a node
     */
    public function invalidateNode(AINode $node): void
    {
        // Clear cache with tag if supported
        if ($this->supportsTagging()) {
            try {
                Cache::tags(["node:{$node->id}"])->flush();
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Tag-based cache flush failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Clear database cache entries that include this node
        if ($this->useDatabase && $this->databaseTableExists()) {
            try {
                DB::table('ai_node_search_cache')
                    ->whereRaw("JSON_CONTAINS(node_ids, ?)", [json_encode($node->id)])
                    ->delete();
            } catch (\Exception $e) {
                // Try alternative approach for databases that don't support JSON_CONTAINS
                try {
                    DB::table('ai_node_search_cache')
                        ->where('node_ids', 'like', '%' . $node->id . '%')
                        ->delete();
                } catch (\Exception $e2) {
                    Log::channel('ai-engine')->warning('Database cache invalidation failed', [
                        'error' => $e2->getMessage(),
                    ]);
                }
            }
        }
        
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
        // Clear cache (only AI engine keys if using prefix)
        try {
            if ($this->supportsTagging()) {
                Cache::tags(['ai_engine'])->flush();
            } else {
                // For non-tagging drivers, we can't selectively flush
                // Only flush if explicitly configured
                if (config('ai-engine.nodes.cache.flush_all_on_invalidate', false)) {
                    $this->cache->flush();
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Cache flush failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Clear all database cache
        if ($this->useDatabase && $this->databaseTableExists()) {
            try {
                DB::table('ai_node_search_cache')->truncate();
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Database cache truncate failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::channel('ai-engine')->info('All node cache invalidated');
    }
    
    /**
     * Clean expired cache entries
     */
    public function cleanExpired(): int
    {
        if (!$this->useDatabase || !$this->databaseTableExists()) {
            return 0;
        }
        
        try {
            $deleted = DB::table('ai_node_search_cache')
                ->where('expires_at', '<', now())
                ->delete();
            
            Log::channel('ai-engine')->info('Expired cache entries cleaned', [
                'deleted_count' => $deleted,
            ]);
            
            return $deleted;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to clean expired cache entries', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
    
    /**
     * Warm up cache for common queries
     */
    public function warmUp(array $commonQueries, callable $searchCallback, ?array $nodeIds = null): void
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
        $stats = [
            'cache_enabled' => $this->cacheEnabled,
            'cache_driver' => $this->cacheDriver ?? config('cache.default'),
            'cache_store' => $this->cacheStore ?? 'default',
            'cache_prefix' => $this->cachePrefix,
            'use_database' => $this->useDatabase,
            'use_tags' => $this->useTags,
            'supports_tagging' => $this->supportsTagging(),
            'default_ttl' => $this->defaultTtl,
        ];
        
        // Add database stats if enabled
        if ($this->useDatabase && $this->databaseTableExists()) {
            try {
                $now = now()->toDateTimeString();
                
                $dbStats = DB::table('ai_node_search_cache')
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
                
                $stats['database'] = [
                    'total_entries' => $dbStats->total_entries ?? 0,
                    'active_entries' => $dbStats->active_entries ?? 0,
                    'expired_entries' => $dbStats->expired_entries ?? 0,
                    'total_hits' => $dbStats->total_hits ?? 0,
                    'avg_hits_per_entry' => round($dbStats->avg_hits_per_entry ?? 0, 2),
                    'total_results' => $dbStats->total_results ?? 0,
                    'avg_duration_ms' => round($dbStats->avg_duration_ms ?? 0, 2),
                ];
            } catch (\Exception $e) {
                $stats['database'] = ['error' => $e->getMessage()];
            }
        }
        
        return $stats;
    }
    
    /**
     * Get most popular cached queries
     */
    public function getPopularQueries(int $limit = 10): array
    {
        if (!$this->useDatabase || !$this->databaseTableExists()) {
            return [];
        }
        
        try {
            return DB::table('ai_node_search_cache')
                ->select('query', 'hit_count', 'result_count', 'created_at')
                ->where('expires_at', '>', now())
                ->orderBy('hit_count', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to get popular queries', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
    
    /**
     * Generate cache key with prefix
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
        
        return $this->cachePrefix . ':search:' . md5(json_encode($data));
    }
    
    /**
     * Increment hit count in database
     */
    protected function incrementHitCount(string $key): void
    {
        if (!$this->useDatabase || !$this->databaseTableExists()) {
            return;
        }
        
        try {
            DB::table('ai_node_search_cache')
                ->where('query_hash', $key)
                ->increment('hit_count');
        } catch (\Exception $e) {
            // Silently fail - hit count is not critical
        }
    }
    
    /**
     * Get cache configuration info
     */
    public function getCacheInfo(): array
    {
        return [
            'enabled' => $this->cacheEnabled,
            'driver' => $this->cacheDriver ?? config('cache.default'),
            'store' => $this->cacheStore ?? 'default',
            'prefix' => $this->cachePrefix,
            'ttl_seconds' => $this->defaultTtl,
            'use_database' => $this->useDatabase,
            'database_table_exists' => $this->databaseTableExists(),
            'use_tags' => $this->useTags,
            'supports_tagging' => $this->supportsTagging(),
        ];
    }
    
    /**
     * Check if caching is enabled
     */
    public function isEnabled(): bool
    {
        return $this->cacheEnabled;
    }
    
    /**
     * Manually set a cache value (for testing or custom caching)
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->cacheEnabled) {
            return false;
        }
        
        try {
            $this->cache->put(
                $this->cachePrefix . ':' . $key,
                $value,
                $ttl ?? $this->defaultTtl
            );
            return true;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Cache set failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Manually get a cache value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->cacheEnabled) {
            return $default;
        }
        
        try {
            return $this->cache->get($this->cachePrefix . ':' . $key, $default);
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Cache get failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $default;
        }
    }
    
    /**
     * Manually forget a cache value
     */
    public function forget(string $key): bool
    {
        if (!$this->cacheEnabled) {
            return false;
        }
        
        try {
            return $this->cache->forget($this->cachePrefix . ':' . $key);
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Cache forget failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
