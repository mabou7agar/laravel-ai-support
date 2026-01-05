<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Log;

class NodeAuthService
{
    /**
     * Check which JWT library is available
     */
    protected function getJwtLibrary(): ?string
    {
        // Check config first
        $configuredLibrary = config('ai-engine.nodes.jwt.library');
        if ($configuredLibrary) {
            return $configuredLibrary;
        }
        
        // Auto-detect
        if (class_exists('\Firebase\JWT\JWT')) {
            return 'firebase';
        }
        
        if (class_exists('\Tymon\JWTAuth\Facades\JWTAuth')) {
            return 'tymon';
        }
        
        return null;
    }
    
    /**
     * Generate JWT token for node
     */
    public function generateToken(AINode $node, ?int $expiresIn = null): string
    {
        // Use configured TTL if not specified
        $expiresIn = $expiresIn ?? config('ai-engine.nodes.jwt.ttl', 3600);
        
        $library = $this->getJwtLibrary();
        
        if (!$library) {
            throw new \RuntimeException(
                'No JWT library found. Please install either firebase/php-jwt or tymon/jwt-auth. ' .
                'Run: composer require firebase/php-jwt'
            );
        }
        
        $payload = [
            'iss' => config('ai-engine.nodes.jwt.issuer', config('app.url')),
            'sub' => $node->id,
            'node_slug' => $node->slug,
            'node_name' => $node->name,
            'iat' => time(),
            'exp' => time() + $expiresIn,
            'capabilities' => $node->capabilities ?? [],
            'type' => $node->type,
        ];
        
        // Add audience if configured
        if ($audience = config('ai-engine.nodes.jwt.audience')) {
            $payload['aud'] = $audience;
        }
        
        if ($library === 'firebase') {
            return $this->generateTokenFirebase($payload);
        }
        
        return $this->generateTokenTymon($payload, $expiresIn);
    }
    
    /**
     * Generate token using Firebase JWT
     */
    protected function generateTokenFirebase(array $payload): string
    {
        $secret = $this->getJwtSecret();
        $algorithm = config('ai-engine.nodes.jwt.algorithm', 'HS256');
        return \Firebase\JWT\JWT::encode($payload, $secret, $algorithm);
    }
    
    /**
     * Generate token using Tymon JWT
     */
    protected function generateTokenTymon(array $payload, int $expiresIn): string
    {
        $secret = $this->getJwtSecret();
        
        // Create custom claims for Tymon
        $customClaims = [
            'node_slug' => $payload['node_slug'],
            'node_name' => $payload['node_name'],
            'capabilities' => $payload['capabilities'],
            'type' => $payload['type'],
        ];
        
        // Use Tymon's factory to create token
        try {
            $factory = app('tymon.jwt');
            return $factory->customClaims($customClaims)
                ->ttl($expiresIn / 60) // Convert seconds to minutes
                ->fromSubject($payload['sub']);
        } catch (\Exception $e) {
            // Fallback to manual encoding if Tymon factory fails
            Log::channel('ai-engine')->warning('Tymon JWT factory failed, using manual encoding', [
                'error' => $e->getMessage(),
            ]);
            
            // Manual JWT encoding as fallback
            $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
            $payloadEncoded = base64_encode(json_encode($payload));
            $signature = hash_hmac('sha256', "$header.$payloadEncoded", $secret, true);
            $signatureEncoded = base64_encode($signature);
            
            return "$header.$payloadEncoded.$signatureEncoded";
        }
    }
    
    /**
     * Validate JWT token
     */
    public function validateToken(string $token): ?array
    {
        $library = $this->getJwtLibrary();
        
        if (!$library) {
            Log::channel('ai-engine')->error('No JWT library available for token validation');
            return null;
        }
        
        if ($library === 'firebase') {
            return $this->validateTokenFirebase($token);
        }
        
        return $this->validateTokenTymon($token);
    }
    
    /**
     * Validate token using Firebase JWT
     */
    protected function validateTokenFirebase(string $token): ?array
    {
        try {
            $secret = $this->getJwtSecret();
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            
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
     * Validate token using Tymon JWT
     */
    protected function validateTokenTymon(string $token): ?array
    {
        try {
            $parser = app('tymon.jwt.parser');
            $payload = $parser->setRequest(request()->create('/', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token
            ]))->parseToken()->getPayload();
            
            return $payload->toArray();
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            Log::channel('ai-engine')->warning('JWT token expired (Tymon)', [
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Invalid JWT token (Tymon)', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Generate refresh token
     */
    public function generateRefreshToken(AINode $node, ?int $expiresInSeconds = null): string
    {
        // Use configured refresh TTL if not specified
        $expiresInSeconds = $expiresInSeconds ?? config('ai-engine.nodes.jwt.refresh_ttl', 86400);
        
        $token = bin2hex(random_bytes(32));
        
        // Store hashed token in database
        $node->update([
            'refresh_token' => hash('sha256', $token),
            'refresh_token_expires_at' => now()->addSeconds($expiresInSeconds),
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
