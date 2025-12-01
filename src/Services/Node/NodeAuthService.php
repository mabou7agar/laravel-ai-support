<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class NodeAuthService
{
    /**
     * Generate JWT token for node
     */
    public function generateToken(AINode $node, int $expiresIn = 3600): string
    {
        $payload = [
            'iss' => config('app.url'),
            'sub' => $node->id,
            'node_slug' => $node->slug,
            'node_name' => $node->name,
            'iat' => time(),
            'exp' => time() + $expiresIn,
            'capabilities' => $node->capabilities ?? [],
            'type' => $node->type,
        ];
        
        $secret = $this->getJwtSecret();
        
        return JWT::encode($payload, $secret, 'HS256');
    }
    
    /**
     * Validate JWT token
     */
    public function validateToken(string $token): ?array
    {
        try {
            $secret = $this->getJwtSecret();
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            
            return (array) $decoded;
        } catch (\Firebase\JWT\ExpiredException $e) {
            Log::channel('ai-engine')->warning('JWT token expired', [
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Invalid JWT token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Generate refresh token
     */
    public function generateRefreshToken(AINode $node, int $expiresInDays = 30): string
    {
        $token = bin2hex(random_bytes(32));
        
        // Store hashed token in database
        $node->update([
            'refresh_token' => hash('sha256', $token),
            'refresh_token_expires_at' => now()->addDays($expiresInDays),
        ]);
        
        Log::channel('ai-engine')->info('Refresh token generated', [
            'node_id' => $node->id,
            'node_slug' => $node->slug,
            'expires_at' => $node->refresh_token_expires_at,
        ]);
        
        return $token;
    }
    
    /**
     * Refresh access token using refresh token
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $hashedToken = hash('sha256', $refreshToken);
        
        $node = AINode::where('refresh_token', $hashedToken)
            ->where('refresh_token_expires_at', '>', now())
            ->where('status', 'active')
            ->first();
        
        if (!$node) {
            Log::channel('ai-engine')->warning('Invalid or expired refresh token');
            return null;
        }
        
        // Generate new access token
        $accessToken = $this->generateToken($node);
        
        Log::channel('ai-engine')->info('Access token refreshed', [
            'node_id' => $node->id,
            'node_slug' => $node->slug,
        ]);
        
        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'node' => [
                'id' => $node->id,
                'slug' => $node->slug,
                'name' => $node->name,
            ],
        ];
    }
    
    /**
     * Revoke refresh token
     */
    public function revokeRefreshToken(AINode $node): void
    {
        $node->update([
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ]);
        
        Log::channel('ai-engine')->info('Refresh token revoked', [
            'node_id' => $node->id,
            'node_slug' => $node->slug,
        ]);
    }
    
    /**
     * Validate node by API key (fallback for backward compatibility)
     */
    public function validateApiKey(string $apiKey): ?AINode
    {
        return AINode::where('api_key', $apiKey)
            ->where('status', 'active')
            ->first();
    }
    
    /**
     * Get JWT secret from config
     */
    protected function getJwtSecret(): string
    {
        $secret = config('ai-engine.nodes.jwt_secret');
        
        if (!$secret) {
            throw new \RuntimeException('JWT secret not configured. Set AI_ENGINE_JWT_SECRET in .env');
        }
        
        return $secret;
    }
    
    /**
     * Generate authentication response
     */
    public function generateAuthResponse(AINode $node): array
    {
        $accessToken = $this->generateToken($node);
        $refreshToken = $this->generateRefreshToken($node);
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'node' => [
                'id' => $node->id,
                'slug' => $node->slug,
                'name' => $node->name,
                'type' => $node->type,
                'capabilities' => $node->capabilities,
                'version' => $node->version,
            ],
        ];
    }
    
    /**
     * Verify node has required capability
     */
    public function verifyCapability(AINode $node, string $capability): bool
    {
        return $node->hasCapability($capability);
    }
}
