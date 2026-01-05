<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Connection Pool for Node HTTP Clients
 * 
 * Manages reusable HTTP connections to reduce overhead
 */
class NodeConnectionPool
{
    protected array $connections = [];
    protected int $maxConnectionsPerNode;
    protected int $connectionTtl;
    
    public function __construct()
    {
        $this->maxConnectionsPerNode = config('ai-engine.nodes.connection_pool.max_per_node', 5);
        $this->connectionTtl = config('ai-engine.nodes.connection_pool.ttl', 300); // 5 minutes
    }
    
    /**
     * Get or create a connection for a node
     */
    public function getConnection(AINode $node, string $type = 'default'): PendingRequest
    {
        $key = $this->getConnectionKey($node, $type);
        
        // Check if we have a cached connection
        if (isset($this->connections[$key])) {
            $connection = $this->connections[$key];
            
            // Check if connection is still valid
            if ($this->isConnectionValid($connection)) {
                return $connection['client'];
            }
            
            // Remove expired connection
            unset($this->connections[$key]);
        }
        
        // Create new connection
        return $this->createConnection($node, $type);
    }
    
    /**
     * Create a new connection
     */
    protected function createConnection(AINode $node, string $type): PendingRequest
    {
        $key = $this->getConnectionKey($node, $type);
        
        // Clean up old connections if we're at the limit
        $this->cleanupConnectionsForNode($node);
        
        // Create connection based on type
        $client = match($type) {
            'health' => NodeHttpClient::makeForHealthCheck($node),
            'search' => NodeHttpClient::makeForSearch($node),
            'action' => NodeHttpClient::makeForAction($node),
            default => NodeHttpClient::makeAuthenticated($node),
        };
        
        // Store connection with metadata
        $this->connections[$key] = [
            'client' => $client,
            'created_at' => time(),
            'last_used' => time(),
            'use_count' => 0,
        ];
        
        return $client;
    }
    
    /**
     * Get connection key
     */
    protected function getConnectionKey(AINode $node, string $type): string
    {
        return "node_{$node->id}_{$type}";
    }
    
    /**
     * Check if connection is still valid
     */
    protected function isConnectionValid(array $connection): bool
    {
        $age = time() - $connection['created_at'];
        return $age < $this->connectionTtl;
    }
    
    /**
     * Clean up old connections for a node
     */
    protected function cleanupConnectionsForNode(AINode $node): void
    {
        $nodeConnections = array_filter(
            $this->connections,
            fn($key) => str_starts_with($key, "node_{$node->id}_"),
            ARRAY_FILTER_USE_KEY
        );
        
        // If we're at the limit, remove oldest connection
        if (count($nodeConnections) >= $this->maxConnectionsPerNode) {
            $oldest = null;
            $oldestKey = null;
            
            foreach ($nodeConnections as $key => $connection) {
                if ($oldest === null || $connection['last_used'] < $oldest) {
                    $oldest = $connection['last_used'];
                    $oldestKey = $key;
                }
            }
            
            if ($oldestKey) {
                unset($this->connections[$oldestKey]);
            }
        }
    }
    
    /**
     * Mark connection as used
     */
    public function markUsed(AINode $node, string $type = 'default'): void
    {
        $key = $this->getConnectionKey($node, $type);
        
        if (isset($this->connections[$key])) {
            $this->connections[$key]['last_used'] = time();
            $this->connections[$key]['use_count']++;
        }
    }
    
    /**
     * Close connection for a node
     */
    public function closeConnection(AINode $node, string $type = 'default'): void
    {
        $key = $this->getConnectionKey($node, $type);
        unset($this->connections[$key]);
    }
    
    /**
     * Close all connections for a node
     */
    public function closeAllConnectionsForNode(AINode $node): void
    {
        $nodePrefix = "node_{$node->id}_";
        
        foreach (array_keys($this->connections) as $key) {
            if (str_starts_with($key, $nodePrefix)) {
                unset($this->connections[$key]);
            }
        }
    }
    
    /**
     * Get connection statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_connections' => count($this->connections),
            'by_node' => [],
            'by_type' => [],
        ];
        
        foreach ($this->connections as $key => $connection) {
            // Parse key: node_{id}_{type}
            if (preg_match('/^node_(\d+)_(.+)$/', $key, $matches)) {
                $nodeId = $matches[1];
                $type = $matches[2];
                
                $stats['by_node'][$nodeId] = ($stats['by_node'][$nodeId] ?? 0) + 1;
                $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
            }
        }
        
        return $stats;
    }
    
    /**
     * Clean up all expired connections
     */
    public function cleanupExpired(): int
    {
        $removed = 0;
        
        foreach ($this->connections as $key => $connection) {
            if (!$this->isConnectionValid($connection)) {
                unset($this->connections[$key]);
                $removed++;
            }
        }
        
        return $removed;
    }
    
    /**
     * Clear all connections
     */
    public function clear(): void
    {
        $this->connections = [];
    }
}
