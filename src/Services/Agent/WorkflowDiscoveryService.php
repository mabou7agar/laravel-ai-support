<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Contracts\DiscoverableWorkflow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/**
 * Discovers workflows implementing DiscoverableWorkflow interface
 * 
 * Scans workflow directories and automatically registers workflows
 * based on their self-declared triggers and goals.
 */
class WorkflowDiscoveryService
{
    /**
     * Discover all workflows by scanning workflow directories
     * 
     * @param bool $useCache Whether to use cached results
     * @return array Map of workflow class => [triggers, goal, priority]
     */
    public function discoverWorkflows(bool $useCache = true): array
    {
        $cacheKey = 'ai-engine:discovered-workflows-v2';
        
        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        $workflows = [];
        
        // Scan workflow directories
        $workflowDirs = $this->getWorkflowDirectories();
        
        foreach ($workflowDirs as $dir) {
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
                    
                    // Check if workflow implements DiscoverableWorkflow
                    if (!$this->implementsDiscoverableWorkflow($className)) {
                        continue;
                    }
                    
                    // Get workflow metadata
                    $triggers = $className::getTriggers();
                    $goal = $className::getGoal();
                    $priority = method_exists($className, 'getPriority') 
                        ? $className::getPriority() 
                        : 0;
                    
                    if (empty($triggers)) {
                        continue;
                    }
                    
                    $workflows[$className] = [
                        'triggers' => $triggers,
                        'goal' => $goal,
                        'priority' => $priority,
                    ];
                    
                    Log::channel('ai-engine')->debug('Discovered workflow', [
                        'workflow' => $className,
                        'triggers' => $triggers,
                        'goal' => $goal,
                        'priority' => $priority,
                    ]);
                    
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->debug('Failed to discover workflow', [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        // Sort by priority (higher first)
        uasort($workflows, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        Log::channel('ai-engine')->info('Workflow discovery completed', [
            'total_workflows' => count($workflows),
            'workflows' => array_keys($workflows),
        ]);
        
        // Cache for 5 minutes
        Cache::put($cacheKey, $workflows, 300);
        
        return $workflows;
    }
    
    /**
     * Get workflow directories to scan
     */
    protected function getWorkflowDirectories(): array
    {
        $dirs = [];
        
        // App workflows directory
        $appWorkflowDir = app_path('AI/Workflows');
        if (is_dir($appWorkflowDir)) {
            $dirs[] = $appWorkflowDir;
        }
        
        // Additional directories from config
        $configDirs = config('ai-agent.workflow_directories', []);
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
     * Check if class implements DiscoverableWorkflow interface
     */
    protected function implementsDiscoverableWorkflow(string $className): bool
    {
        try {
            $reflection = new \ReflectionClass($className);
            return $reflection->implementsInterface(DiscoverableWorkflow::class);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Convert workflow metadata to simple trigger map for backward compatibility
     */
    public function getTriggersMap(bool $useCache = true): array
    {
        $workflows = $this->discoverWorkflows($useCache);
        
        $triggersMap = [];
        foreach ($workflows as $workflowClass => $metadata) {
            $triggersMap[$workflowClass] = $metadata['triggers'];
        }
        
        return $triggersMap;
    }
    
    /**
     * Clear workflow discovery cache
     */
    public function clearCache(): void
    {
        Cache::forget('ai-engine:discovered-workflows-v2');
    }
}
