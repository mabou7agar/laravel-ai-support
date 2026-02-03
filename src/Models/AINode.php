<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class AINode extends Model
{
    use SoftDeletes;

    protected $table = 'ai_nodes';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'url',
        'description',
        'api_key',
        'refresh_token',
        'refresh_token_expires_at',
        'capabilities',
        'domains',
        'data_types',
        'keywords',
        'collections',
        'workflows',
        'autonomous_collectors',
        'metadata',
        'version',
        'status',
        'last_ping_at',
        'ping_failures',
        'avg_response_time',
        'weight',
        'active_connections',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'domains' => 'array',
        'data_types' => 'array',
        'keywords' => 'array',
        'collections' => 'array',
        'workflows' => 'array',
        'autonomous_collectors' => 'array',
        'metadata' => 'array',
        'last_ping_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'ping_failures' => 'integer',
        'avg_response_time' => 'integer',
        'weight' => 'integer',
        'active_connections' => 'integer',
    ];

    protected $hidden = [
        'api_key',
        'refresh_token',
    ];

    protected $appends = [
        'is_healthy',
        'status_color',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($node) {
            // Auto-generate slug if not provided
            if (empty($node->slug)) {
                $node->slug = Str::slug($node->name);
            }

            // Auto-generate API key if not provided
            if (empty($node->api_key)) {
                $node->api_key = Str::random(64);
            }
        });
    }

    // ==================== Scopes ====================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeChild($query)
    {
        return $query->where('type', 'child');
    }

    public function scopeMaster($query)
    {
        return $query->where('type', 'master');
    }

    public function scopeHealthy($query)
    {
        //return $query->where('ping_failures', '<', 3)
        //             ->where('last_ping_at', '>=', now()->subMinutes(10));
    }

    public function scopeWithCapability($query, string $capability)
    {
        return $query->whereJsonContains('capabilities', $capability);
    }

    public function scopeFastestFirst($query)
    {
        return $query->orderBy('avg_response_time', 'asc');
    }

    public function scopeByWeight($query)
    {
        return $query->orderBy('weight', 'desc');
    }

    // ==================== Relationships ====================

    public function requests(): HasMany
    {
        return $this->hasMany(AINodeRequest::class, 'node_id');
    }

    public function circuitBreaker(): HasOne
    {
        return $this->hasOne(AINodeCircuitBreaker::class, 'node_id');
    }

    // ==================== Helper Methods ====================

    /**
     * Check if node is healthy
     */
    public function isHealthy(): bool
    {
        return $this->status === 'active'
            && $this->ping_failures < 3
            && $this->last_ping_at?->gt(now()->subMinutes(10));
    }

    /**
     * Check if node has a specific capability
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? []);
    }

    /**
     * Get full API URL for an endpoint
     */
    public function getApiUrl(string $endpoint = ''): string
    {
        // Map endpoint aliases to actual package routes
        $endpointMap = [
            'actions' => '/api/v1/actions/execute',
            'actions/execute' => '/api/v1/actions/execute',
            'model-actions' => '/api/v1/modules/discover',
            'chat' => '/api/ai-engine/chat',
            'ping' => '/api/ai-engine/health',
            'search' => '/api/ai-engine/search',
            'execute' => '/api/ai-engine/execute',
        ];

        // Use mapped endpoint if available, otherwise construct URL
        $path = $endpointMap[$endpoint] ?? '/api/ai-engine/' . ltrim($endpoint, '/');

        return rtrim($this->url, '/') . $path;
    }

    /**
     * Record ping result
     */
    public function recordPing(bool $success, ?int $responseTime = null): void
    {
        if ($success) {
            $this->update([
                'last_ping_at' => now(),
                'ping_failures' => 0,
                'status' => 'active',
                'avg_response_time' => $responseTime ? $this->calculateAvgResponseTime($responseTime) : $this->avg_response_time,
            ]);
        } else {
            $this->increment('ping_failures');

            if ($this->ping_failures >= 3) {
                $this->update(['status' => 'error']);
            }
        }
    }

    /**
     * Calculate average response time (exponential moving average)
     */
    protected function calculateAvgResponseTime(int $newResponseTime): int
    {
        if (!$this->avg_response_time) {
            return $newResponseTime;
        }

        // EMA with alpha = 0.3 (30% weight to new value)
        return (int) (0.3 * $newResponseTime + 0.7 * $this->avg_response_time);
    }

    /**
     * Increment active connections
     */
    public function incrementConnections(): void
    {
        $this->increment('active_connections');
    }

    /**
     * Decrement active connections
     */
    public function decrementConnections(): void
    {
        $this->decrement('active_connections');
    }

    /**
     * Get recent success rate (last hour)
     */
    public function getSuccessRate(): float
    {
        $total = $this->requests()
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($total === 0) {
            return 100.0;
        }

        $successful = $this->requests()
            ->where('created_at', '>=', now()->subHour())
            ->where('status', 'success')
            ->count();

        return round(($successful / $total) * 100, 2);
    }

    /**
     * Get load score for load balancing
     */
    public function getLoadScore(): float
    {
        $responseTime = $this->avg_response_time ?? 100;
        $connections = $this->active_connections ?? 0;
        $weight = $this->weight ?? 1;

        // Lower score = better (less loaded)
        // Formula: (response_time * connections) / weight
        return ($responseTime * ($connections + 1)) / $weight;
    }

    /**
     * Get AI-friendly node information for context-aware selection
     */
    public function getAIContext(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'capabilities' => $this->capabilities ?? [],
            'domains' => $this->domains ?? [],
            'data_types' => $this->data_types ?? [],
            'keywords' => $this->keywords ?? [],
            'status' => $this->status,
            'is_healthy' => $this->isHealthy(),
            'avg_response_time' => $this->avg_response_time,
        ];
    }

    /**
     * Check if node matches query context
     */
    public function matchesContext(array $keywords): bool
    {
        $nodeKeywords = array_merge(
            $this->keywords ?? [],
            $this->domains ?? [],
            $this->data_types ?? [],
            [$this->name, $this->slug]
        );

        $nodeKeywords = array_map('strtolower', $nodeKeywords);
        $searchKeywords = array_map('strtolower', $keywords);

        foreach ($searchKeywords as $keyword) {
            foreach ($nodeKeywords as $nodeKeyword) {
                if (str_contains($nodeKeyword, $keyword) || str_contains($keyword, $nodeKeyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ==================== Accessors ====================

    public function getIsHealthyAttribute(): bool
    {
        return $this->isHealthy();
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'active' => 'green',
            'inactive' => 'gray',
            'maintenance' => 'yellow',
            'error' => 'red',
            default => 'gray',
        };
    }

    /**
     * Get domains from metadata
     */
    public function getDomains(): array
    {
        return $this->metadata['domains'] ?? [];
    }

    /**
     * Get data types from metadata
     */
    public function getDataTypes(): array
    {
        return $this->metadata['data_types'] ?? [];
    }

    /**
     * Get keywords from metadata
     */
    public function getKeywords(): array
    {
        return $this->metadata['keywords'] ?? [];
    }

    /**
     * Get topics from metadata
     */
    public function getTopics(): array
    {
        return $this->metadata['topics'] ?? [];
    }

    /**
     * Check if node matches domain
     */
    public function matchesDomain(string $domain): bool
    {
        $domains = $this->getDomains();
        return in_array(strtolower($domain), array_map('strtolower', $domains));
    }

    /**
     * Check if node matches keyword
     */
    public function matchesKeyword(string $keyword): bool
    {
        $keywords = $this->getKeywords();
        return in_array(strtolower($keyword), array_map('strtolower', $keywords));
    }

    // ==================== Rate Limiting ====================

    /**
     * Check if node is rate limited
     */
    public function isRateLimited(): bool
    {
        if (!config('ai-engine.nodes.rate_limit.enabled', true)) {
            return false;
        }

        if (!config('ai-engine.nodes.rate_limit.per_node', true)) {
            return false; // Global rate limiting handled elsewhere
        }

        $key = "node_rate_limit:{$this->id}";
        $limit = config('ai-engine.nodes.rate_limit.max_attempts', 60);

        return \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, $limit);
    }

    /**
     * Increment rate limit counter
     */
    public function hitRateLimit(): void
    {
        if (!config('ai-engine.nodes.rate_limit.per_node', true)) {
            return;
        }

        $key = "node_rate_limit:{$this->id}";
        $decay = config('ai-engine.nodes.rate_limit.decay_minutes', 1);

        \Illuminate\Support\Facades\RateLimiter::hit($key, $decay * 60);
    }

    /**
     * Get remaining rate limit attempts
     */
    public function remainingRateLimitAttempts(): int
    {
        if (!config('ai-engine.nodes.rate_limit.per_node', true)) {
            return PHP_INT_MAX;
        }

        $key = "node_rate_limit:{$this->id}";
        $limit = config('ai-engine.nodes.rate_limit.max_attempts', 60);

        return \Illuminate\Support\Facades\RateLimiter::remaining($key, $limit);
    }

    /**
     * Clear rate limit for node
     */
    public function clearRateLimit(): void
    {
        $key = "node_rate_limit:{$this->id}";
        \Illuminate\Support\Facades\RateLimiter::clear($key);
    }
}
