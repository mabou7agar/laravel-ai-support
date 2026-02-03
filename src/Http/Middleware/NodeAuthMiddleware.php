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
            // JWT token is valid
            $isMaster = config('ai-engine.nodes.is_master', false);
            
            if ($isMaster) {
                // Master node: load the node from database
                $node = AINode::find($nodeData['sub'] ?? null);
                
                if (!$node) {
                    return $this->unauthorized('Node not found');
                }
                
                if ($node->status !== 'active') {
                    return $this->forbidden('Node is not active', $node->status);
                }
                
                // Attach node to request
                $request->attributes->set('node', $node);
            } else {
                // Child node: trust the JWT claims from master
                // Create a virtual node object from JWT claims
                $virtualNode = new AINode([
                    'id' => $nodeData['sub'] ?? 0,
                    'name' => $nodeData['node_name'] ?? 'master',
                    'slug' => $nodeData['node_slug'] ?? 'master',
                    'type' => $nodeData['type'] ?? 'master',
                    'capabilities' => $nodeData['capabilities'] ?? [],
                    'status' => 'active',
                ]);
                
                // Attach virtual node to request
                $request->attributes->set('node', $virtualNode);
                
                Log::channel('ai-engine')->debug('Child node accepted JWT from master', [
                    'node_slug' => $nodeData['node_slug'] ?? 'unknown',
                    'issuer' => $nodeData['iss'] ?? 'unknown',
                ]);
            }
            
            $request->attributes->set('auth_type', 'jwt');
            $request->attributes->set('node_data', $nodeData);
            
            Log::channel('ai-engine')->debug('Node authenticated via JWT', [
                'node_slug' => $nodeData['node_slug'] ?? 'unknown',
                'is_master' => $isMaster,
            ]);
            
            return $next($request);
        }
        
        // Check for shared secret from environment
        $sharedSecret = config('ai-engine.nodes.shared_secret');
        if ($sharedSecret && $token === $sharedSecret) {
            // Create a virtual node for shared secret auth
            $virtualNode = new AINode([
                'id' => 0,
                'name' => 'federated-node',
                'slug' => 'federated',
                'type' => 'federated',
                'status' => 'active',
            ]);
            
            $request->attributes->set('node', $virtualNode);
            $request->attributes->set('auth_type', 'shared_secret');
            
            Log::channel('ai-engine')->debug('Node authenticated via shared secret');
            
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
        // Try X-Node-Token header first (preferred for node authentication)
        $nodeToken = $request->header('X-Node-Token');
        if ($nodeToken) {
            return $nodeToken;
        }
        
        // Try Authorization header as fallback (Bearer token) for backward compatibility
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
