<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Contracts\HasWorkflow;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Discovers workflows from models implementing HasWorkflow interface
 */
class WorkflowDiscoveryService
{
    public function __construct(
        protected RAGCollectionDiscovery $ragDiscovery
    ) {}
    
    /**
     * Discover all workflows from models
     * 
     * @param bool $useCache Whether to use cached results
     * @return array Map of workflow class => triggers
     */
    public function discoverWorkflows(bool $useCache = true): array
    {
        $cacheKey = 'ai-engine:discovered-workflows';
        
        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        $workflows = [];
        
        // Get all models from RAG discovery
        $models = $this->ragDiscovery->discover($useCache, includeFederated: false);
        
        foreach ($models as $modelClass) {
            try {
                // Check if model implements HasWorkflow
                if (!$this->implementsHasWorkflow($modelClass)) {
                    continue;
                }
                
                // Get workflow class and triggers
                $workflowClass = $modelClass::getWorkflowClass();
                $triggers = $modelClass::getWorkflowTriggers();
                
                if (empty($workflowClass) || empty($triggers)) {
                    continue;
                }
                
                // Merge with existing triggers if workflow already registered
                if (isset($workflows[$workflowClass])) {
                    $workflows[$workflowClass] = array_unique(
                        array_merge($workflows[$workflowClass], $triggers)
                    );
                } else {
                    $workflows[$workflowClass] = $triggers;
                }
                
                Log::channel('ai-engine')->debug('Discovered workflow from model', [
                    'model' => $modelClass,
                    'workflow' => $workflowClass,
                    'triggers' => $triggers,
                ]);
                
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug('Failed to discover workflow from model', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Also check models with initializeAI() method (legacy support)
        foreach ($models as $modelClass) {
            try {
                if (!method_exists($modelClass, 'initializeAI')) {
                    continue;
                }
                
                $instance = new $modelClass();
                $config = $instance->initializeAI();
                
                if (empty($config['workflow'])) {
                    continue;
                }
                
                $workflowClass = $config['workflow'];
                
                // Generate default triggers from model name
                $modelName = class_basename($modelClass);
                $triggers = $this->generateDefaultTriggers($modelName);
                
                // Merge with existing
                if (isset($workflows[$workflowClass])) {
                    $workflows[$workflowClass] = array_unique(
                        array_merge($workflows[$workflowClass], $triggers)
                    );
                } else {
                    $workflows[$workflowClass] = $triggers;
                }
                
                Log::channel('ai-engine')->debug('Discovered workflow from initializeAI', [
                    'model' => $modelClass,
                    'workflow' => $workflowClass,
                    'triggers' => $triggers,
                ]);
                
            } catch (\Exception $e) {
                // Skip models that fail
            }
        }
        
        Log::channel('ai-engine')->info('Workflow discovery completed', [
            'total_workflows' => count($workflows),
            'workflows' => array_keys($workflows),
        ]);
        
        // Cache for 5 minutes
        Cache::put($cacheKey, $workflows, 300);
        
        return $workflows;
    }
    
    /**
     * Check if model implements HasWorkflow interface
     */
    protected function implementsHasWorkflow(string $modelClass): bool
    {
        try {
            $reflection = new \ReflectionClass($modelClass);
            return $reflection->implementsInterface(HasWorkflow::class);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Generate default triggers from model name
     */
    protected function generateDefaultTriggers(string $modelName): array
    {
        $lower = strtolower($modelName);
        
        return [
            "create {$lower}",
            "new {$lower}",
            "add {$lower}",
            "make {$lower}",
            "{$lower} for",
        ];
    }
    
    /**
     * Clear workflow discovery cache
     */
    public function clearCache(): void
    {
        Cache::forget('ai-engine:discovered-workflows');
    }
}
