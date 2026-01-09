# Integration: Existing Model Discovery → Agent Workflows

## Your Existing System (Already Built!) ✅

You have a comprehensive model analysis system:

### 1. **ModelAnalyzer** ✅
**File:** `src/Services/ModelAnalyzer.php`

**Capabilities:**
- Combines schema and relationship analysis
- Generates recommendations
- Creates indexing plans
- Comprehensive model understanding

### 2. **RelationshipAnalyzer** ✅
**File:** `src/Services/RelationshipAnalyzer.php`

**Capabilities:**
- Detects all relationships using reflection
- Identifies relationship types (BelongsTo, HasMany, etc.)
- Gets related model classes
- Estimates relationship counts
- Recommends relationships for indexing
- Detects circular references

### 3. **SchemaAnalyzer** ✅
**File:** `src/Services/SchemaAnalyzer.php`

**Capabilities:**
- Analyzes database schema
- Identifies text fields
- Recommends fields for indexing
- Estimates data size

---

## What You Have vs What We Need

### Your Existing System:
```php
$analyzer = app(ModelAnalyzer::class);
$analysis = $analyzer->analyze('App\\Models\\Invoice');

// Returns:
[
    'model' => 'App\\Models\\Invoice',
    'schema' => [
        'table' => 'invoices',
        'text_fields' => [...],
        'recommended_config' => [...],
    ],
    'relationships' => [
        'relationships' => [
            ['name' => 'customer', 'type' => 'BelongsTo', ...],
            ['name' => 'items', 'type' => 'HasMany', ...],
        ],
        'recommended' => [...],
        'suggested_depth' => 2,
    ],
]
```

### What Agent System Needs:
```php
// Same data, just need to:
1. Calculate complexity score from relationships
2. Determine if needs agent_mode, guided_flow, or quick_action
3. Generate workflow configuration
4. Feed to ComplexityAnalyzer
```

---

## Integration Architecture

```
┌─────────────────────────────────────────────────────────┐
│         Your Existing System (Already Built)            │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ModelAnalyzer                                          │
│  ├─ SchemaAnalyzer (fields, types, recommendations)    │
│  └─ RelationshipAnalyzer (relationships, types, depth) │
│                                                          │
└──────────────────┬──────────────────────────────────────┘
                   │
                   │ Integration Layer (NEW)
                   ▼
┌─────────────────────────────────────────────────────────┐
│           AgentModelAdapter (NEW)                        │
│  • Converts ModelAnalyzer output to Agent format        │
│  • Calculates complexity score                          │
│  • Determines strategy (agent_mode/guided_flow/quick)   │
│  • Generates workflow configuration                     │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│         Agent System (Phase 1 & 3)                      │
│  • ComplexityAnalyzer (uses adapted data)              │
│  • AgentOrchestrator (routes based on analysis)        │
│  • AgentMode (executes workflows)                      │
│  • Tools (validate, suggest, search, explain)          │
└─────────────────────────────────────────────────────────┘
```

---

## Implementation: AgentModelAdapter

### Create Integration Service

```php
<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Services\ModelAnalyzer;

class AgentModelAdapter
{
    public function __construct(
        protected ModelAnalyzer $modelAnalyzer
    ) {}
    
    /**
     * Convert ModelAnalyzer output to Agent format
     */
    public function adaptModel(string $modelClass): array
    {
        // Use your existing analyzer
        $analysis = $this->modelAnalyzer->analyze($modelClass);
        
        // Calculate complexity from relationships
        $complexity = $this->calculateComplexity($analysis);
        
        // Determine strategy
        $strategy = $this->determineStrategy($complexity, $analysis);
        
        // Generate workflow config
        $workflowConfig = $this->generateWorkflowConfig($analysis);
        
        return [
            'model' => $modelClass,
            'name' => class_basename($modelClass),
            'complexity' => $complexity,
            'strategy' => $strategy,
            'relationships' => $this->extractRelationships($analysis),
            'fields' => $this->extractFields($analysis),
            'workflow_config' => $workflowConfig,
            'original_analysis' => $analysis, // Keep full analysis
        ];
    }
    
    /**
     * Calculate complexity from existing analysis
     */
    protected function calculateComplexity(array $analysis): string
    {
        $score = 0;
        
        // Relationships add complexity
        $relationships = $analysis['relationships']['relationships'] ?? [];
        $score += count($relationships) * 10;
        
        // Many-to-many relationships add more complexity
        foreach ($relationships as $rel) {
            if ($rel['is_many_to_many'] ?? false) {
                $score += 5;
            }
        }
        
        // Suggested depth indicates complexity
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
     * Determine strategy based on complexity
     */
    protected function determineStrategy(string $complexity, array $analysis): string
    {
        $relationships = $analysis['relationships']['relationships'] ?? [];
        
        // If has relationships that need validation, use agent_mode
        if ($complexity === 'HIGH' && count($relationships) > 0) {
            return 'agent_mode';
        }
        
        // If medium complexity, use guided_flow
        if ($complexity === 'MEDIUM') {
            return 'guided_flow';
        }
        
        // Simple models use quick_action
        return 'quick_action';
    }
    
    /**
     * Extract relationships in agent format
     */
    protected function extractRelationships(array $analysis): array
    {
        $relationships = $analysis['relationships']['relationships'] ?? [];
        $adapted = [];
        
        foreach ($relationships as $rel) {
            $adapted[] = [
                'name' => $rel['name'],
                'type' => $rel['type'],
                'related_model' => $rel['related_model'],
                'required' => $this->isRequiredRelationship($rel),
                'can_create' => $this->canCreateRelated($rel),
                'validation_needed' => true,
            ];
        }
        
        return $adapted;
    }
    
    /**
     * Extract fields in agent format
     */
    protected function extractFields(array $analysis): array
    {
        $textFields = $analysis['schema']['text_fields'] ?? [];
        $fields = [];
        
        foreach ($textFields as $field) {
            if ($field['recommended']) {
                $fields[$field['name']] = [
                    'type' => $this->mapFieldType($field['type']),
                    'required' => $this->isRequiredField($field['name']),
                    'validation' => $this->generateValidation($field),
                ];
            }
        }
        
        return $fields;
    }
    
    /**
     * Generate workflow configuration
     */
    protected function generateWorkflowConfig(array $analysis): array
    {
        $relationships = $analysis['relationships']['relationships'] ?? [];
        
        return [
            'steps' => $this->generateWorkflowSteps($relationships),
            'validations' => $this->generateValidations($analysis),
            'confirmations' => $this->generateConfirmations($relationships),
        ];
    }
    
    /**
     * Generate workflow steps based on relationships
     */
    protected function generateWorkflowSteps(array $relationships): array
    {
        $steps = ['extract_data'];
        
        foreach ($relationships as $rel) {
            $steps[] = "validate_{$rel['name']}";
            $steps[] = "handle_missing_{$rel['name']}";
        }
        
        $steps[] = 'confirm_creation';
        $steps[] = 'create_record';
        
        return $steps;
    }
    
    protected function isRequiredRelationship(array $rel): bool
    {
        // BelongsTo relationships are typically required
        return $rel['type'] === 'BelongsTo';
    }
    
    protected function canCreateRelated(array $rel): bool
    {
        // Can create HasMany relationships on-the-fly
        return in_array($rel['type'], ['HasMany', 'MorphMany']);
    }
    
    protected function mapFieldType(string $type): string
    {
        return match($type) {
            'text', 'longtext', 'mediumtext' => 'textarea',
            'string' => 'string',
            default => 'string',
        };
    }
    
    protected function isRequiredField(string $fieldName): bool
    {
        // Common required fields
        return in_array($fieldName, ['name', 'title', 'email']);
    }
    
    protected function generateValidation(array $field): string
    {
        $rules = ['required', 'string'];
        
        if ($field['type'] === 'string') {
            $rules[] = 'max:255';
        }
        
        return implode('|', $rules);
    }
    
    protected function generateValidations(array $analysis): array
    {
        // Generate validation rules from schema
        return [];
    }
    
    protected function generateConfirmations(array $relationships): array
    {
        // Generate confirmation steps
        return [];
    }
}
```

---

## Integration with ComplexityAnalyzer

### Update ComplexityAnalyzer to use discovered models

```php
namespace LaravelAIEngine\Services\Agent;

class ComplexityAnalyzer
{
    protected AgentModelAdapter $modelAdapter;
    protected array $discoveredModels = [];
    
    public function __construct(
        AIEngineService $ai,
        AgentModelAdapter $modelAdapter
    ) {
        $this->ai = $ai;
        $this->modelAdapter = $modelAdapter;
        $this->loadDiscoveredModels();
    }
    
    protected function loadDiscoveredModels(): void
    {
        // Load cached discovered models
        $cache = cache()->get('agent_discovered_models', []);
        $this->discoveredModels = $cache;
    }
    
    protected function buildAnalysisPrompt(string $message, UnifiedActionContext $context): string
    {
        $prompt = "Analyze this user request...\n\n";
        
        // Add discovered models dynamically
        if (!empty($this->discoveredModels)) {
            $prompt .= "DISCOVERED MODELS IN THIS APPLICATION:\n";
            
            foreach ($this->discoveredModels as $model) {
                $prompt .= "- {$model['name']} ({$model['complexity']} complexity)\n";
                $prompt .= "  Relationships: " . count($model['relationships']) . "\n";
                $prompt .= "  Strategy: {$model['strategy']}\n";
                
                if ($model['complexity'] === 'HIGH') {
                    $prompt .= "  ⚠️ Requires agent_mode (has relationships)\n";
                }
                $prompt .= "\n";
            }
        }
        
        // Rest of existing prompt...
        return $prompt;
    }
}
```

---

## Discovery Command

### Create command to discover all models

```php
<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentModelAdapter;

class DiscoverModelsForAgentCommand extends Command
{
    protected $signature = 'ai:discover-models
                            {--refresh : Refresh cached models}';
    
    protected $description = 'Discover all models and generate agent configurations';
    
    public function handle(AgentModelAdapter $adapter)
    {
        $this->info('🔍 Discovering models for AI Agent...');
        $this->newLine();
        
        // Find all models
        $models = $this->findAllModels();
        
        $discovered = [];
        $bar = $this->output->createProgressBar(count($models));
        
        foreach ($models as $modelClass) {
            try {
                $adapted = $adapter->adaptModel($modelClass);
                $discovered[] = $adapted;
                
                $this->displayModelInfo($adapted);
                
            } catch (\Exception $e) {
                $this->warn("Failed to analyze {$modelClass}: {$e->getMessage()}");
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine(2);
        
        // Cache discovered models
        cache()->put('agent_discovered_models', $discovered, now()->addDay());
        
        $this->displaySummary($discovered);
        
        return 0;
    }
    
    protected function findAllModels(): array
    {
        $models = [];
        $path = app_path('Models');
        
        if (!is_dir($path)) {
            return [];
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );
        
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $class = $this->getClassFromFile($file->getPathname());
                if ($class && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
                    $models[] = $class;
                }
            }
        }
        
        return $models;
    }
    
    protected function getClassFromFile(string $file): ?string
    {
        $contents = file_get_contents($file);
        
        if (preg_match('/namespace\s+(.+?);/', $contents, $namespaceMatch) &&
            preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            return $namespaceMatch[1] . '\\' . $classMatch[1];
        }
        
        return null;
    }
    
    protected function displayModelInfo(array $model): void
    {
        $icon = match($model['complexity']) {
            'HIGH' => '🔴',
            'MEDIUM' => '🟡',
            'SIMPLE' => '🟢',
        };
        
        $this->line("{$icon} {$model['name']} ({$model['complexity']})");
        $this->line("   Strategy: {$model['strategy']}");
        $this->line("   Relationships: " . count($model['relationships']));
    }
    
    protected function displaySummary(array $discovered): void
    {
        $this->info('📊 Discovery Summary:');
        $this->newLine();
        
        $high = count(array_filter($discovered, fn($m) => $m['complexity'] === 'HIGH'));
        $medium = count(array_filter($discovered, fn($m) => $m['complexity'] === 'MEDIUM'));
        $simple = count(array_filter($discovered, fn($m) => $m['complexity'] === 'SIMPLE'));
        
        $this->table(
            ['Complexity', 'Count', 'Strategy'],
            [
                ['HIGH', $high, 'agent_mode'],
                ['MEDIUM', $medium, 'guided_flow'],
                ['SIMPLE', $simple, 'quick_action'],
            ]
        );
        
        $this->newLine();
        $this->info('✅ Models cached and ready for AI Agent');
    }
}
```

---

## Usage

### 1. Run Discovery
```bash
php artisan ai:discover-models
```

**Output:**
```
🔍 Discovering models for AI Agent...

🔴 Invoice (HIGH)
   Strategy: agent_mode
   Relationships: 3

🔴 Order (HIGH)
   Strategy: agent_mode
   Relationships: 2

🟡 Product (MEDIUM)
   Strategy: guided_flow
   Relationships: 1

🟢 Category (SIMPLE)
   Strategy: quick_action
   Relationships: 0

📊 Discovery Summary:
┌────────────┬───────┬──────────────┐
│ Complexity │ Count │ Strategy     │
├────────────┼───────┼──────────────┤
│ HIGH       │ 2     │ agent_mode   │
│ MEDIUM     │ 1     │ guided_flow  │
│ SIMPLE     │ 1     │ quick_action │
└────────────┴───────┴──────────────┘

✅ Models cached and ready for AI Agent
```

### 2. Automatic Routing
```php
User: "Create invoice"
→ ComplexityAnalyzer checks cached models
→ Finds: Invoice = HIGH complexity, agent_mode
→ Routes to agent workflow automatically
```

---

## Benefits of Integration

### 1. **Reuses Existing Infrastructure** ✅
- Your ModelAnalyzer already does the heavy lifting
- No duplicate code
- Proven, tested system

### 2. **Zero Redundancy** ✅
- One source of truth for model analysis
- Agent system adapts existing data
- Maintains consistency

### 3. **Automatic Updates** ✅
- When you improve ModelAnalyzer, Agent benefits
- Single point of maintenance
- Shared improvements

### 4. **Leverages Your Investment** ✅
- Uses your existing relationship detection
- Uses your schema analysis
- Uses your recommendations

### 5. **Simple Integration** ✅
- Just one adapter class
- One discovery command
- Minimal code

---

## Implementation Steps

### Week 1: Integration Layer
- [ ] Create AgentModelAdapter
- [ ] Test with Invoice model
- [ ] Verify complexity calculation
- [ ] Test strategy determination

### Week 2: Discovery Command
- [ ] Create DiscoverModelsForAgentCommand
- [ ] Implement model scanning
- [ ] Add caching
- [ ] Test with all models

### Week 3: ComplexityAnalyzer Integration
- [ ] Update ComplexityAnalyzer to load discovered models
- [ ] Update prompt with discovered models
- [ ] Test automatic routing
- [ ] Verify all strategies work

### Week 4: Testing & Polish
- [ ] Test with 10+ models
- [ ] Performance optimization
- [ ] Documentation
- [ ] Production deployment

---

## Summary

**You already have 80% of what you need!**

Your existing system provides:
- ✅ Model discovery
- ✅ Relationship analysis
- ✅ Schema analysis
- ✅ Complexity indicators

We just need to:
- 🔧 Create adapter to convert format
- 🔧 Add complexity scoring
- 🔧 Integrate with ComplexityAnalyzer
- 🔧 Add discovery command

**This is much simpler than building from scratch!** 🎯

Would you like me to implement the AgentModelAdapter first?
