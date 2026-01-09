# RAGCollectionDiscovery → Agent System Integration

## Your RAGCollectionDiscovery (Already Built!) ✅

**File:** `src/Services/RAG/RAGCollectionDiscovery.php`

### What It Already Does:

1. **Auto-discovers models** with `Vectorizable` trait
2. **Scans multiple paths** including glob patterns (e.g., `modules/*/Models`)
3. **Extracts class names** from files using reflection
4. **Gets model descriptions** via `getRAGDescription()` method
5. **Supports priorities** via `getRAGPriority()` method
6. **Caches results** for performance
7. **Handles federated nodes** (remote model discovery)
8. **Provides statistics** about discovered models

### Perfect for Agent Integration!

Your RAGCollectionDiscovery already:
- ✅ Discovers all models automatically
- ✅ Extracts metadata (name, description, priority)
- ✅ Caches for performance
- ✅ Supports modular architecture

---

## Simple Integration Strategy

Instead of building new discovery, just **extend** RAGCollectionDiscovery with agent-specific analysis:

```
RAGCollectionDiscovery (existing)
    ↓ discovers models
AgentCollectionAdapter (new - thin layer)
    ↓ adds complexity analysis
ComplexityAnalyzer
    ↓ uses for routing
Agent Workflows
```

---

## Implementation

### 1. Create AgentCollectionAdapter

```php
<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\ModelAnalyzer;

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
        // Use RAG discovery to get all models
        $models = $this->ragDiscovery->discover($useCache);
        
        $adapted = [];
        
        foreach ($models as $modelClass) {
            try {
                $adapted[] = $this->adaptModel($modelClass);
            } catch (\Exception $e) {
                \Log::debug("Skipped model during agent discovery: {$modelClass}");
            }
        }
        
        return $adapted;
    }
    
    /**
     * Adapt a single model for agent use
     */
    public function adaptModel(string $modelClass): array
    {
        // Get RAG info (already cached by RAGCollectionDiscovery)
        $ragInfo = $this->ragDiscovery->getCollectionInfo($modelClass, 'local');
        
        // Get model analysis (relationships, schema)
        $analysis = $this->modelAnalyzer->analyze($modelClass);
        
        // Calculate complexity from relationships
        $complexity = $this->calculateComplexity($analysis);
        
        // Determine strategy
        $strategy = $this->determineStrategy($complexity, $analysis);
        
        return [
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
        ];
    }
    
    protected function calculateComplexity(array $analysis): string
    {
        $score = 0;
        
        // Count relationships
        $relationships = $analysis['relationships']['relationships'] ?? [];
        $score += count($relationships) * 10;
        
        // Many-to-many adds complexity
        foreach ($relationships as $rel) {
            if ($rel['is_many_to_many'] ?? false) {
                $score += 5;
            }
        }
        
        // Depth adds complexity
        $depth = $analysis['relationships']['suggested_depth'] ?? 0;
        $score += $depth * 3;
        
        if ($score >= 20) return 'HIGH';
        if ($score >= 10) return 'MEDIUM';
        return 'SIMPLE';
    }
    
    protected function determineStrategy(string $complexity, array $analysis): string
    {
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
            ];
        }
        
        return $extracted;
    }
    
    protected function hasValidation(array $analysis): bool
    {
        // Check if model has validation rules
        return !empty($analysis['schema']['text_fields'] ?? []);
    }
    
    protected function extractKeywords(string $modelClass): array
    {
        $name = class_basename($modelClass);
        
        // Generate keywords for detection
        return [
            strtolower($name),
            strtolower(\Illuminate\Support\Str::plural($name)),
            strtolower(\Illuminate\Support\Str::snake($name)),
        ];
    }
}
```

---

### 2. Update ComplexityAnalyzer

```php
namespace LaravelAIEngine\Services\Agent;

class ComplexityAnalyzer
{
    protected AgentCollectionAdapter $collectionAdapter;
    protected array $discoveredModels = [];
    
    public function __construct(
        AIEngineService $ai,
        AgentCollectionAdapter $collectionAdapter
    ) {
        $this->ai = $ai;
        $this->collectionAdapter = $collectionAdapter;
        $this->loadDiscoveredModels();
    }
    
    protected function loadDiscoveredModels(): void
    {
        // Load from cache (populated by discovery command)
        $this->discoveredModels = cache()->get('agent_discovered_models', []);
        
        // If empty, discover now
        if (empty($this->discoveredModels)) {
            $this->discoveredModels = $this->collectionAdapter->discoverForAgent();
            cache()->put('agent_discovered_models', $this->discoveredModels, now()->addDay());
        }
    }
    
    protected function buildAnalysisPrompt(string $message, UnifiedActionContext $context): string
    {
        $prompt = parent::buildAnalysisPrompt($message, $context);
        
        // Add discovered models
        if (!empty($this->discoveredModels)) {
            $prompt .= "\n\nDISCOVERED MODELS:\n";
            
            foreach ($this->discoveredModels as $model) {
                $prompt .= "- {$model['display_name']} ({$model['complexity']})\n";
                $prompt .= "  Description: {$model['description']}\n";
                $prompt .= "  Relationships: {$model['relationship_count']}\n";
                $prompt .= "  Strategy: {$model['strategy']}\n";
                
                if (!empty($model['keywords'])) {
                    $prompt .= "  Keywords: " . implode(', ', $model['keywords']) . "\n";
                }
                
                $prompt .= "\n";
            }
        }
        
        return $prompt;
    }
}
```

---

### 3. Discovery Command

```php
<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentCollectionAdapter;

class DiscoverModelsForAgentCommand extends Command
{
    protected $signature = 'ai:discover-agent-models {--refresh}';
    protected $description = 'Discover models for AI Agent using RAG discovery';
    
    public function handle(AgentCollectionAdapter $adapter)
    {
        $this->info('🔍 Discovering models for AI Agent...');
        $this->info('Using RAGCollectionDiscovery + ModelAnalyzer');
        $this->newLine();
        
        $useCache = !$this->option('refresh');
        
        $models = $adapter->discoverForAgent($useCache);
        
        if (empty($models)) {
            $this->warn('No models discovered. Make sure models use Vectorizable trait.');
            return 1;
        }
        
        $this->displayModels($models);
        
        // Cache for ComplexityAnalyzer
        cache()->put('agent_discovered_models', $models, now()->addDay());
        
        $this->newLine();
        $this->info('✅ Discovery complete! Models cached for AI Agent.');
        
        return 0;
    }
    
    protected function displayModels(array $models): void
    {
        $high = [];
        $medium = [];
        $simple = [];
        
        foreach ($models as $model) {
            $icon = match($model['complexity']) {
                'HIGH' => '🔴',
                'MEDIUM' => '🟡',
                'SIMPLE' => '🟢',
            };
            
            $line = "{$icon} {$model['display_name']} ({$model['complexity']})";
            $this->line($line);
            $this->line("   Strategy: {$model['strategy']}");
            $this->line("   Relationships: {$model['relationship_count']}");
            $this->line("   Description: {$model['description']}");
            $this->newLine();
            
            match($model['complexity']) {
                'HIGH' => $high[] = $model,
                'MEDIUM' => $medium[] = $model,
                'SIMPLE' => $simple[] = $model,
            };
        }
        
        $this->newLine();
        $this->table(
            ['Complexity', 'Count', 'Strategy'],
            [
                ['HIGH', count($high), 'agent_mode'],
                ['MEDIUM', count($medium), 'guided_flow'],
                ['SIMPLE', count($simple), 'quick_action'],
            ]
        );
    }
}
```

---

## Usage

### 1. Run Discovery (Uses Your RAGCollectionDiscovery)
```bash
php artisan ai:discover-agent-models
```

**Output:**
```
🔍 Discovering models for AI Agent...
Using RAGCollectionDiscovery + ModelAnalyzer

🔴 Invoice (HIGH)
   Strategy: agent_mode
   Relationships: 3
   Description: Search through invoices and billing records

🔴 Order (HIGH)
   Strategy: agent_mode
   Relationships: 2
   Description: Search through customer orders

🟡 Product (MEDIUM)
   Strategy: guided_flow
   Relationships: 1
   Description: Search through product catalog

🟢 Category (SIMPLE)
   Strategy: quick_action
   Relationships: 0
   Description: Search through product categories

┌────────────┬───────┬──────────────┐
│ Complexity │ Count │ Strategy     │
├────────────┼───────┼──────────────┤
│ HIGH       │ 2     │ agent_mode   │
│ MEDIUM     │ 1     │ guided_flow  │
│ SIMPLE     │ 1     │ quick_action │
└────────────┴───────┴──────────────┘

✅ Discovery complete! Models cached for AI Agent.
```

### 2. Automatic Routing
```php
User: "Create invoice"
→ ComplexityAnalyzer loads discovered models
→ Finds: Invoice = HIGH, agent_mode
→ Routes to agent workflow automatically
```

---

## Benefits

### 1. **Reuses RAGCollectionDiscovery** ✅
- Already discovers all models
- Already extracts metadata
- Already caches results
- Already supports modular architecture

### 2. **Adds Agent-Specific Analysis** ✅
- Complexity calculation from relationships
- Strategy determination
- Keyword extraction for detection

### 3. **Leverages ModelAnalyzer** ✅
- Relationship analysis
- Schema analysis
- Validation detection

### 4. **Zero Redundancy** ✅
- One discovery system (RAG)
- One analysis system (ModelAnalyzer)
- One thin adapter layer

### 5. **Simple Integration** ✅
- Just 3 new files:
  - AgentCollectionAdapter
  - DiscoverModelsForAgentCommand
  - Update to ComplexityAnalyzer

---

## Models Need Minimal Changes

Models already using `Vectorizable` trait just need to add:

```php
class Invoice extends Model
{
    use Vectorizable;
    
    // Already have this for RAG
    public static function getRAGDescription(): string
    {
        return 'Search through invoices and billing records';
    }
    
    // Optional: Add priority
    public function getRAGPriority(): int
    {
        return 80; // Higher priority models
    }
}
```

**That's it!** The adapter will:
- Use RAGCollectionDiscovery to find the model
- Use ModelAnalyzer to analyze relationships
- Calculate complexity automatically
- Determine strategy automatically

---

## Implementation Steps

### Week 1: Adapter Layer
- [ ] Create AgentCollectionAdapter
- [ ] Test with 2-3 models
- [ ] Verify complexity calculation

### Week 2: Command & Integration
- [ ] Create DiscoverModelsForAgentCommand
- [ ] Update ComplexityAnalyzer
- [ ] Test automatic routing

### Week 3: Testing
- [ ] Test with all discovered models
- [ ] Verify strategies correct
- [ ] Performance testing

---

## Summary

**Perfect Integration!**

Your RAGCollectionDiscovery:
- ✅ Already discovers models
- ✅ Already extracts metadata
- ✅ Already caches
- ✅ Already supports modules

We just need:
- 🔧 AgentCollectionAdapter (converts to agent format)
- 🔧 Add complexity calculation
- 🔧 Update ComplexityAnalyzer to use it

**This is the cleanest integration possible!** 🎯

Would you like me to implement the AgentCollectionAdapter now?
