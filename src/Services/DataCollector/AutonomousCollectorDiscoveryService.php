<?php

namespace LaravelAIEngine\Services\DataCollector;

use LaravelAIEngine\Contracts\DiscoverableAutonomousCollector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use LaravelAIEngine\Services\Node\NodeHttpClient;

/**
 * Discovers autonomous collectors implementing DiscoverableAutonomousCollector interface
 * 
 * Scans configured directories and automatically registers collectors
 * based on their self-declared goals and descriptions.
 */
class AutonomousCollectorDiscoveryService
{
    protected $nodeRegistry = null;

    public function __construct()
    {
        // Lazy load node registry if available
        if (class_exists(\LaravelAIEngine\Services\Node\NodeRegistryService::class)) {
            $this->nodeRegistry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);
        }
    }

    /**
     * Discover all autonomous collectors (local + remote)
     * 
     * @param bool $useCache Whether to use cached results
     * @param bool $includeRemote Whether to include collectors from remote nodes
     * @return array Map of collector name => [config, description, priority]
     */
    public function discoverCollectors(bool $useCache = true, bool $includeRemote = true): array
    {
        $cacheKey = 'ai-engine:discovered-autonomous-collectors';
        
        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        // Discover local collectors
        $collectors = $this->discoverFromLocal();
        
        // Discover from remote nodes if enabled
        if ($includeRemote && $this->nodeRegistry && config('ai-engine.nodes.enabled', false)) {
            $remoteCollectors = $this->discoverFromNodes();
            $collectors = array_merge($collectors, $remoteCollectors);
        }
        
        // Sort by priority (higher first)
        uasort($collectors, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        Log::channel('ai-engine')->info('Autonomous collector discovery completed', [
            'total_collectors' => count($collectors),
            'collectors' => array_keys($collectors),
        ]);
        
        // Only cache if we found collectors (prevent caching empty results from early boot)
        if (!empty($collectors)) {
            Cache::put($cacheKey, $collectors, 300);
        }
        
        return $collectors;
    }

    /**
     * Discover collectors from local directories
     * 
     * @return array
     */
    protected function discoverFromLocal(): array
    {
        $collectors = [];
        
        // Scan collector directories
        $collectorDirs = $this->getCollectorDirectories();
        
        foreach ($collectorDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            
            $files = File::allFiles($dir);
            
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                
                try {
                    $className = $this->getClassNameFromFile($file->getPathname());
                    
                    if (!$className || !class_exists($className)) {
                        continue;
                    }
                    
                    // Check if class implements DiscoverableAutonomousCollector
                    if (!$this->implementsDiscoverableCollector($className)) {
                        continue;
                    }
                    
                    // Get collector metadata
                    $name = $className::getName();
                    $description = $className::getDescription();
                    $priority = method_exists($className, 'getPriority') 
                        ? $className::getPriority() 
                        : 0;
                    $modelClass = method_exists($className, 'getModelClass') 
                        ? $className::getModelClass() 
                        : null;
                    $filterConfig = method_exists($className, 'getFilterConfig') 
                        ? $className::getFilterConfig() 
                        : [];
                    
                    $collectors[$name] = [
                        'class' => $className,
                        'description' => $description,
                        'priority' => $priority,
                        'model_class' => $modelClass,
                        'filter_config' => $filterConfig,
                        'has_permissions' => method_exists($className, 'getAllowedOperations'),
                        'source' => 'local',
                    ];
                    
                    Log::channel('ai-engine')->debug('Discovered local autonomous collector', [
                        'name' => $name,
                        'class' => $className,
                        'description' => $description,
                        'priority' => $priority,
                    ]);
                    
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->debug('Failed to discover collector', [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        return $collectors;
    }

    /**
     * Discover collectors from remote nodes
     *
     * @return array
     */
    protected function discoverFromNodes(): array
    {
        $collectors = [];

        try {
            $nodes = $this->nodeRegistry->getActiveNodes();

            foreach ($nodes as $node) {
                try {
                    $response = NodeHttpClient::makeAuthenticated($node)
                        ->get($node->getApiUrl('autonomous-collectors'));

                    if ($response->successful()) {
                        $data = $response->json();
                        foreach ($data['collectors'] ?? [] as $collectorName => $collectorData) {
                            // Add node information to remote collectors
                            $collectors[$collectorName] = array_merge($collectorData, [
                                'source' => 'remote',
                                'node_id' => $node->id,
                                'node_slug' => $node->slug,
                                'node_name' => $node->name,
                            ]);
                            
                            Log::channel('ai-engine')->debug('Discovered remote autonomous collector', [
                                'name' => $collectorName,
                                'node' => $node->slug,
                                'description' => $collectorData['description'] ?? '',
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->debug('Failed to get collectors from node', [
                        'node' => $node->slug,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to discover collectors from nodes', [
                'error' => $e->getMessage(),
            ]);
        }

        return $collectors;
    }

    /**
     * Register all discovered collectors with the registry
     */
    public function registerDiscoveredCollectors(bool $useCache = true): void
    {
        $discovered = $this->discoverCollectors($useCache);
        
        foreach ($discovered as $name => $metadata) {
            $className = $metadata['class'];
            
            // Register with the registry (lazy config loading)
            AutonomousCollectorRegistry::register($name, [
                'config' => fn() => $className::getConfig(),
                'description' => $metadata['description'],
                'class' => $className, // Include class for permission checking
            ]);
        }
        
        Log::channel('ai-engine')->info('Registered discovered autonomous collectors', [
            'count' => count($discovered),
        ]);
    }
    
    /**
     * Get collector directories to scan
     */
    protected function getCollectorDirectories(): array
    {
        $dirs = [];
        
        // App collectors directory
        $appCollectorDir = app_path('AI/Configs');
        if (is_dir($appCollectorDir)) {
            $dirs[] = $appCollectorDir;
        }
        
        // Alternative directory name
        $appCollectorDir2 = app_path('AI/Collectors');
        if (is_dir($appCollectorDir2)) {
            $dirs[] = $appCollectorDir2;
        }
        
        // Additional directories from config
        $configDirs = config('ai-engine.autonomous_collector.discovery_paths', []);
        foreach ($configDirs as $dir) {
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }
        
        return $dirs;
    }
    
    /**
     * Extract class name from PHP file
     */
    protected function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            $namespace = $namespaceMatches[1];
        } else {
            return null;
        }
        
        // Extract class name
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = $classMatches[1];
            return $namespace . '\\' . $className;
        }
        
        return null;
    }
    
    /**
     * Check if class implements DiscoverableAutonomousCollector interface
     */
    protected function implementsDiscoverableCollector(string $className): bool
    {
        try {
            $reflection = new \ReflectionClass($className);
            return $reflection->implementsInterface(DiscoverableAutonomousCollector::class);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Clear collector discovery cache
     */
    public function clearCache(): void
    {
        Cache::forget('ai-engine:discovered-autonomous-collectors');
    }
}
