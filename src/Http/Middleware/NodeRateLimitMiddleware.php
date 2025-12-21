<?php

namespace LaravelAIEngine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;

class NodeRateLimitMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int  $maxAttempts
     * @param  int  $decayMinutes
     * @return mixed
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decayMinutes = 1)
    {
        $node = $request->attributes->get('node');
        
        if (!$node) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Rate limit key based on node ID or slug (for virtual nodes from JWT)
        $nodeIdentifier = $node->id ?? $node->slug ?? 'unknown';
        $key = $this->resolveRequestSignature($nodeIdentifier, $request);
        
        // Check if too many attempts
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            
            Log::channel('ai-engine')->warning('Node rate limit exceeded', [
                'node_id' => $node->id,
                'node_slug' => $node->slug,
                'endpoint' => $request->path(),
                'retry_after' => $seconds,
            ]);
            
            return $this->buildRateLimitResponse($maxAttempts, 0, $seconds);
        }
        
        // Increment attempts
        RateLimiter::hit($key, $decayMinutes * 60);
        
        $response = $next($request);
        
        // Add rate limit headers
        return $this->addHeaders(
            $response,
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts)
        );
    }
    
    /**
     * Resolve request signature
     */
    protected function resolveRequestSignature(int|string $nodeId, Request $request): string
    {
        return 'node_rate_limit:' . $nodeId . ':' . $request->path();
    }
    
    /**
     * Calculate remaining attempts
     */
    protected function calculateRemainingAttempts(string $key, int $maxAttempts): int
    {
        return RateLimiter::remaining($key, $maxAttempts);
    }
    
    /**
     * Add rate limit headers to response
     */
    protected function addHeaders($response, int $maxAttempts, int $remainingAttempts)
    {
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $remainingAttempts));
        
        return $response;
    }
    
    /**
     * Build rate limit exceeded response
     */
    protected function buildRateLimitResponse(int $maxAttempts, int $remainingAttempts, int $retryAfter): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded. Please try again later.',
            'retry_after' => $retryAfter,
        ], 429)->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
            'Retry-After' => $retryAfter,
        ]);
    }
}
