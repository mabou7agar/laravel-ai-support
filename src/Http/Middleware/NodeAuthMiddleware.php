<?php

namespace LaravelAIEngine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeAuthService;
use Illuminate\Support\Facades\Log;

class NodeAuthMiddleware
{
    public function __construct(
        protected NodeAuthService $authService
    ) {}
    
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Extract token from Authorization header
        $token = $this->extractToken($request);
        
        if (!$token) {
            return $this->unauthorized('Missing authentication token');
        }
        
        // Try JWT validation first
        $nodeData = $this->authService->validateToken($token);
        
        if ($nodeData) {
            // JWT token is valid, load the node
            $node = AINode::find($nodeData['sub'] ?? null);
            
            if (!$node) {
                return $this->unauthorized('Node not found');
            }
            
            if ($node->status !== 'active') {
                return $this->forbidden('Node is not active', $node->status);
            }
            
            // Attach node to request
            $request->attributes->set('node', $node);
            $request->attributes->set('auth_type', 'jwt');
            
            Log::channel('ai-engine')->debug('Node authenticated via JWT', [
                'node_id' => $node->id,
                'node_slug' => $node->slug,
            ]);
            
            return $next($request);
        }
        
        // Fallback to API key authentication
        $node = $this->authService->validateApiKey($token);
        
        if (!$node) {
            return $this->unauthorized('Invalid authentication credentials');
        }
        
        if ($node->status !== 'active') {
            return $this->forbidden('Node is not active', $node->status);
        }
        
        // Attach node to request
        $request->attributes->set('node', $node);
        $request->attributes->set('auth_type', 'api_key');
        
        Log::channel('ai-engine')->debug('Node authenticated via API key', [
            'node_id' => $node->id,
            'node_slug' => $node->slug,
        ]);
        
        return $next($request);
    }
    
    /**
     * Extract token from request
     */
    protected function extractToken(Request $request): ?string
    {
        // Try Authorization header first (Bearer token)
        $authHeader = $request->header('Authorization');
        
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }
        
        // Try X-API-Key header
        $apiKey = $request->header('X-API-Key');
        if ($apiKey) {
            return $apiKey;
        }
        
        // Try query parameter (not recommended for production)
        return $request->query('api_key');
    }
    
    /**
     * Return unauthorized response
     */
    protected function unauthorized(string $message): \Illuminate\Http\JsonResponse
    {
        Log::channel('ai-engine')->warning('Node authentication failed', [
            'message' => $message,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
        
        return response()->json([
            'error' => 'Unauthorized',
            'message' => $message,
        ], 401);
    }
    
    /**
     * Return forbidden response
     */
    protected function forbidden(string $message, string $status = null): \Illuminate\Http\JsonResponse
    {
        Log::channel('ai-engine')->warning('Node access forbidden', [
            'message' => $message,
            'status' => $status,
        ]);
        
        return response()->json([
            'error' => 'Forbidden',
            'message' => $message,
            'node_status' => $status,
        ], 403);
    }
}
