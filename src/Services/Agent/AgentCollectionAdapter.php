<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\ModelAnalyzer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AgentCollectionAdapter
{
    public function __construct(
        protected RAGCollectionDiscovery $ragDiscovery,
        protected ModelAnalyzer $modelAnalyzer
    ) {}
    
    /**
     * Discover all models with agent-specific metadata
     */
    public function discoverForAgent(bool $useCache = true): array
    {
        Log::channel('ai-engine')->info('Starting agent model discovery');
        
        // Use RAG discovery to get all models
        $models = $this->ragDiscovery->discover($useCache, includeFederated: false);
        
        $adapted = [];
        
        foreach ($models as $modelClass) {
            try {
                $adapted[] = $this->adaptModel($modelClass);
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug("Skipped model during agent discovery", [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::channel('ai-engine')->info('Agent model discovery completed', [
            'total_models' => count($adapted),
        ]);
        
        return $adapted;
    }
    
    /**
     * Adapt a single model for agent use
     */
    public function adaptModel(string $modelClass): array
    {
        // Get RAG info (uses RAGCollectionDiscovery's caching)
        $ragInfo = $this->getCollectionInfo($modelClass);
        
        // Get model analysis (relationships, schema)
        $analysis = $this->modelAnalyzer->analyze($modelClass);
        
        // Calculate complexity from relationships
        $complexity = $this->calculateComplexity($analysis);
        
        // Determine strategy (pass modelClass directly)
        $strategy = $this->determineStrategy($complexity, $analysis, $modelClass);
        
        $result = [
            'class' => $modelClass,
            'name' => $ragInfo['name'],
            'display_name' => $ragInfo['display_name'],
            'description' => $ragInfo['description'],
            'complexity' => $complexity,
            'strategy' => $strategy,
            'relationships' => $this->extractRelationships($analysis),
            'relationship_count' => count($analysis['relationships']['relationships'] ?? []),
            'has_validation' => $this->hasValidation($analysis),
            'keywords' => $this->extractKeywords($modelClass),
            'score' => $this->calculateScore($analysis),
        ];
        
        // Add workflow_class if strategy is agent_mode
        if ($strategy === 'agent_mode') {
            $workflowClass = $this->getWorkflowClass($modelClass);
            if ($workflowClass) {
                $result['workflow_class'] = $workflowClass;
            }
        }
        
        return $result;
    }
    
    /**
     * Get collection info (similar to RAGCollectionDiscovery)
     */
    protected function getCollectionInfo(string $modelClass): array
    {
        $name = class_basename($modelClass);
        $description = '';
        $displayName = $name;

        try {
            $reflection = new \ReflectionClass($modelClass);

            if ($reflection->hasMethod('getRAGDescription')) {
                $method = $reflection->getMethod('getRAGDescription');
                if ($method->isStatic()) {
                    $description = $modelClass::getRAGDescription();
                } else {
                    $instance = new $modelClass();
                    $description = $instance->getRAGDescription();
                }
            }

            if ($reflection->hasMethod('getRAGDisplayName')) {
                $method = $reflection->getMethod('getRAGDisplayName');
                if ($method->isStatic()) {
                    $displayName = $modelClass::getRAGDisplayName();
                } else {
                    if (!isset($instance)) {
                        $instance = new $modelClass();
                    }
                    $displayName = $instance->getRAGDisplayName();
                }
            }
        } catch (\Exception $e) {
            // Use defaults
        }

        if (empty($description)) {
            $description = "Manage {$name} records";
        }

        return [
            'name' => $name,
            'display_name' => $displayName,
            'description' => $description,
        ];
    }
    
    /**
     * Calculate complexity from model analysis
     */
    protected function calculateComplexity(array $analysis): string
    {
        $score = 0;
        
        // Count relationships
        $relationships = $analysis['relationships']['relationships'] ?? [];
        $score += count($relationships) * 10;
        
        // Many-to-many adds more complexity
        foreach ($relationships as $rel) {
            if ($rel['is_many_to_many'] ?? false) {
                $score += 5;
            }
        }
        
        // Depth adds complexity
        $depth = $analysis['relationships']['suggested_depth'] ?? 0;
        $score += $depth * 3;
        
        // Text fields for validation
        $textFields = $analysis['schema']['text_fields'] ?? [];
        $score += count($textFields) * 2;
        
        // Determine level
        if ($score >= 20) return 'HIGH';
        if ($score >= 10) return 'MEDIUM';
        return 'SIMPLE';
    }
    
    /**
     * Calculate numeric score for sorting
     */
    protected function calculateScore(array $analysis): int
    {
        $score = 0;
        
        $relationships = $analysis['relationships']['relationships'] ?? [];
        $score += count($relationships) * 10;
        
        foreach ($relationships as $rel) {
            if ($rel['is_many_to_many'] ?? false) {
                $score += 5;
            }
        }
        
        $depth = $analysis['relationships']['suggested_depth'] ?? 0;
        $score += $depth * 3;
        
        $textFields = $analysis['schema']['text_fields'] ?? [];
        $score += count($textFields) * 2;
        
        return $score;
    }
    
    /**
     * Determine strategy based on complexity and workflow configuration
     */
    protected function determineStrategy(string $complexity, array $analysis, string $modelClass): string
    {
        // Check if model has a workflow explicitly configured
        if ($this->hasWorkflowConfigured($modelClass)) {
            return 'agent_mode';
        }
        
        $relationships = $analysis['relationships']['relationships'] ?? [];
        
        // HIGH complexity with relationships = agent_mode
        if ($complexity === 'HIGH' && count($relationships) > 0) {
            return 'agent_mode';
        }
        
        // MEDIUM = guided_flow
        if ($complexity === 'MEDIUM') {
            return 'guided_flow';
        }
        
        // SIMPLE = quick_action
        return 'quick_action';
    }
    
    /**
     * Check if model has a workflow configured in initializeAI
     */
    protected function hasWorkflowConfigured(string $modelClass): bool
    {
        try {
            if (!method_exists($modelClass, 'initializeAI')) {
                return false;
            }
            
            $instance = new $modelClass();
            $config = $instance->initializeAI();
            
            // Check if workflow is configured
            return !empty($config['workflow']);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get workflow class from model's initializeAI configuration
     */
    protected function getWorkflowClass(string $modelClass): ?string
    {
        try {
            if (!method_exists($modelClass, 'initializeAI')) {
                return null;
            }
            
            $instance = new $modelClass();
            $config = $instance->initializeAI();
            
            return $config['workflow'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Extract relationships in agent format
     */
    protected function extractRelationships(array $analysis): array
    {
        $relationships = $analysis['relationships']['relationships'] ?? [];
        $extracted = [];
        
        foreach ($relationships as $rel) {
            $extracted[] = [
                'name' => $rel['name'],
                'type' => $rel['type'],
                'related_model' => $rel['related_model'],
                'required' => $rel['type'] === 'BelongsTo',
                'can_create' => in_array($rel['type'], ['HasMany', 'MorphMany']),
            ];
        }
        
        return $extracted;
    }
    
    /**
     * Check if model has validation
     */
    protected function hasValidation(array $analysis): bool
    {
        return !empty($analysis['schema']['text_fields'] ?? []);
    }
    
    /**
     * Extract keywords for detection
     */
    protected function extractKeywords(string $modelClass): array
    {
        $name = class_basename($modelClass);
        
        return [
            strtolower($name),
            strtolower(Str::plural($name)),
            strtolower(Str::snake($name)),
            strtolower(Str::kebab($name)),
        ];
    }
    
    /**
     * Get statistics about discovered models
     */
    public function getStatistics(array $models): array
    {
        $high = array_filter($models, fn($m) => $m['complexity'] === 'HIGH');
        $medium = array_filter($models, fn($m) => $m['complexity'] === 'MEDIUM');
        $simple = array_filter($models, fn($m) => $m['complexity'] === 'SIMPLE');
        
        return [
            'total' => count($models),
            'high' => count($high),
            'medium' => count($medium),
            'simple' => count($simple),
            'by_strategy' => [
                'agent_mode' => count(array_filter($models, fn($m) => $m['strategy'] === 'agent_mode')),
                'guided_flow' => count(array_filter($models, fn($m) => $m['strategy'] === 'guided_flow')),
                'quick_action' => count(array_filter($models, fn($m) => $m['strategy'] === 'quick_action')),
            ],
        ];
    }
}
