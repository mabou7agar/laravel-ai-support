<?php

namespace LaravelAIEngine\Services\DataCollector;

use LaravelAIEngine\Contracts\DiscoverableAutonomousCollector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/**
 * Discovers autonomous collectors implementing DiscoverableAutonomousCollector interface
 * 
 * Scans configured directories and automatically registers collectors
 * based on their self-declared goals and descriptions.
 */
class AutonomousCollectorDiscoveryService
{
    /**
     * Discover all autonomous collectors by scanning directories
     * 
     * @param bool $useCache Whether to use cached results
     * @return array Map of collector name => [config, description, priority]
     */
    public function discoverCollectors(bool $useCache = true): array
    {
        $cacheKey = 'ai-engine:discovered-autonomous-collectors';
        
        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
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
                    
                    $collectors[$name] = [
                        'class' => $className,
                        'description' => $description,
                        'priority' => $priority,
                    ];
                    
                    Log::channel('ai-engine')->debug('Discovered autonomous collector', [
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
        
        // Sort by priority (higher first)
        uasort($collectors, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        Log::channel('ai-engine')->info('Autonomous collector discovery completed', [
            'total_collectors' => count($collectors),
            'collectors' => array_keys($collectors),
        ]);
        
        // Cache for 5 minutes
        Cache::put($cacheKey, $collectors, 300);
        
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
