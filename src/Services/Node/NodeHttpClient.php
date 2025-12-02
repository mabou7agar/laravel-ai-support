<?php

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

/**
 * Node HTTP Client Helper
 * 
 * Provides consistent HTTP client configuration for node communications
 */
class NodeHttpClient
{
    /**
     * Create HTTP client with node-specific configuration
     */
    public static function make(bool $async = false): PendingRequest
    {
        $http = $async ? Http::async() : Http::withOptions([]);
        
        // Apply timeout
        $timeout = config('ai-engine.nodes.request_timeout', 30);
        $http = $http->timeout($timeout);
        
        // Disable SSL verification if configured
        if (!config('ai-engine.nodes.verify_ssl', true)) {
            $http = $http->withOptions(['verify' => false]);
        }
        
        return $http;
    }
    
    /**
     * Create HTTP client with authentication for a specific node
     */
    public static function makeAuthenticated(
        \LaravelAIEngine\Models\AINode $node,
        bool $async = false,
        int $tokenTtl = 300
    ): PendingRequest {
        $http = static::make($async);
        
        // Add authentication
        $authService = app(NodeAuthService::class);
        $token = $authService->generateToken($node, $tokenTtl);
        
        return $http->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ]);
    }
    
    /**
     * Create HTTP client for health check
     */
    public static function makeForHealthCheck(\LaravelAIEngine\Models\AINode $node): PendingRequest
    {
        return static::makeAuthenticated($node, false, 300)
            ->timeout(5); // Shorter timeout for health checks
    }
    
    /**
     * Create HTTP client for search requests
     */
    public static function makeForSearch(\LaravelAIEngine\Models\AINode $node, string $traceId = null): PendingRequest
    {
        $http = static::makeAuthenticated($node, true);
        
        if ($traceId) {
            $http = $http->withHeaders(['X-Trace-Id' => $traceId]);
        }
        
        return $http;
    }
    
    /**
     * Create HTTP client for action requests
     */
    public static function makeForAction(\LaravelAIEngine\Models\AINode $node, string $traceId = null): PendingRequest
    {
        $http = static::makeAuthenticated($node, true);
        
        if ($traceId) {
            $http = $http->withHeaders(['X-Trace-Id' => $traceId]);
        }
        
        return $http;
    }
}
