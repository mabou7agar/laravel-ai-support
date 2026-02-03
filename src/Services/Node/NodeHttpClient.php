<?php

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

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
        int $tokenTtl = 300,
        array $forwardHeaders = []
    ): PendingRequest {
        $http = static::make($async);

        // Add authentication
        $authService = app(NodeAuthService::class);
        $token = $authService->generateToken($node, $tokenTtl);

        $headers = [
            'X-Node-Token' => $token,
            'Accept' => 'application/json',
        ];

        // Merge forwarded headers (but don't override node authentication)
        if (!empty($forwardHeaders)) {
            $headers = array_merge($forwardHeaders, $headers);
        }

        return $http->withHeaders($headers);
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
    public static function makeForSearch(\LaravelAIEngine\Models\AINode $node, string $traceId = null, bool $forwardHeaders = true): PendingRequest
    {
        // Extract forwardable headers from current request if enabled
        $headers = $forwardHeaders ? static::extractForwardableHeaders() : [];

        // Use synchronous requests for reliability (async can have issues with promise resolution)
        $http = static::makeAuthenticated($node, false, 300, $headers);

        if ($traceId) {
            $http = $http->withHeaders(['X-Trace-Id' => $traceId]);
        }

        return $http;
    }

    /**
     * Create HTTP client for action requests
     */
    public static function makeForAction(\LaravelAIEngine\Models\AINode $node, string $traceId = null, bool $forwardHeaders = true): PendingRequest
    {
        // Extract forwardable headers from current request if enabled
        $headers = $forwardHeaders ? static::extractForwardableHeaders() : [];

        // Use synchronous requests for reliability (async returns promises that need resolution)
        $http = static::makeAuthenticated($node, false, 300, $headers);

        if ($traceId) {
            $http = $http->withHeaders(['X-Trace-Id' => $traceId]);
        }

        return $http;
    }

    /**
     * Get headers for search requests (used by HTTP Pool)
     */
    public static function getSearchHeaders(\LaravelAIEngine\Models\AINode $node, string $traceId = null, array $forwardHeaders = []): array
    {
        $authService = app(NodeAuthService::class);
        $token = $authService->generateToken($node, 300);

        $headers = [
            'X-Node-Token' => $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($traceId) {
            $headers['X-Trace-Id'] = $traceId;
        }

        // Merge forwarded headers (but don't override node authentication)
        if (!empty($forwardHeaders)) {
            $headers = array_merge($forwardHeaders, $headers);
        }

        return $headers;
    }

    /**
     * Extract headers from current request to forward to nodes
     * Filters out sensitive headers and keeps only relevant ones
     */
    public static function extractForwardableHeaders(?Request $request = null): array
    {
        if (!$request) {
            $request = request();
        }

        if (!$request) {
            return [];
        }

        $forwardableHeaders = [
            'X-Request-Id',
            'X-Trace-Id',
            'X-Correlation-Id',
            'X-User-Id',
            'X-Tenant-Id',
            'X-Workspace-Id',
            'Active-Workspace',
            'Accept-Language',
            'User-Agent',
            'Referer',
        ];

        $headers = [];
        foreach ($forwardableHeaders as $header) {
            $value = $request->header($header);
            if ($value) {
                $headers[$header] = $value;
            }
        }

        // Forward Authorization header if present (for user authentication, not node auth)
        $authHeader = $request->header('Authorization');

        // Handle array values (Laravel sometimes returns arrays for headers)
        if (is_array($authHeader)) {
            $authHeader = $authHeader[0] ?? null;
        }

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            // Forward Bearer token as X-User-Authorization to avoid conflict with node auth
            $headers['X-User-Authorization'] = $authHeader;
        } elseif ($authHeader) {
            // Forward non-Bearer tokens as-is
            $headers['Authorization'] = $authHeader;
        }

        return $headers;
    }
}
