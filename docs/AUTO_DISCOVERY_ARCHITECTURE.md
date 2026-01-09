# Auto-Discovery Architecture for Dynamic Workflows

## Your Vision

Instead of hardcoding workflows for specific models (Invoice, Order, etc.), the system should:

1. **Auto-discover all models** in your application
2. **Extract their capabilities** (fields, relationships, validation rules)
3. **Analyze their complexity** (dependencies, required entities)
4. **Generate workflows dynamically** based on model structure
5. **Update ComplexityAnalyzer** with discovered patterns

---

## The Problem with Current Approach

### Current (Hardcoded):
```php
// config/ai-agent.php
'workflows' => [
    \App\AI\Workflows\CreateInvoiceWorkflow::class => ['create invoice'],
    \App\AI\Workflows\CreateOrderWorkflow::class => ['create order'],
    \App\AI\Workflows\CreateBillWorkflow::class => ['create bill'],
    // ... need to add manually for every model
],
```

**Issues:**
- ❌ Manual configuration for each model
- ❌ Doesn't scale to 50+ models
- ❌ Hardcoded complexity rules
- ❌ No automatic relationship detection
- ❌ Requires developer intervention

### Your Vision (Auto-Discovery):
```php
// System automatically discovers:
- Invoice model has: customer_id, products (relationship), total (calculated)
- Order model has: customer_id, items (relationship), status
- Product model has: category_id (relationship), price, stock
- Category model has: name, description

// System automatically generates:
- CreateInvoiceWorkflow (because it has relationships + calculations)
- CreateOrderWorkflow (because it has relationships + status)
- CreateProductWorkflow (because it has relationship to Category)
- CreateCategoryWorkflow (simple, uses guided_flow)

// ComplexityAnalyzer automatically knows:
- "Create invoice" → HIGH (has relationships)
- "Create order" → HIGH (has relationships)
- "Create product" → MEDIUM (has one relationship)
- "Create category" → SIMPLE (no relationships)
```

---

## Architecture Design

### 1. Model Discovery Service

**Purpose:** Scan application and find all models with their metadata

```php
namespace LaravelAIEngine\Services\Agent\Discovery;

class ModelDiscoveryService
{
    public function discoverModels(): array
    {
        // Scan app/Models directory
        // Find all classes extending Model
        // Return array of model metadata
    }
    
    public function analyzeModel(string $modelClass): ModelMetadata
    {
        return new ModelMetadata([
            'class' => $modelClass,
            'table' => $model->getTable(),
            'fillable' => $model->getFillable(),
            'relationships' => $this->extractRelationships($model),
            'validation_rules' => $this->extractValidationRules($model),
            'calculated_fields' => $this->extractCalculatedFields($model),
            'required_fields' => $this->extractRequiredFields($model),
            'complexity_score' => $this->calculateComplexity($model),
        ]);
    }
}
```

### 2. Relationship Analyzer

**Purpose:** Detect model relationships and dependencies

```php
namespace LaravelAIEngine\Services\Agent\Discovery;

class RelationshipAnalyzer
{
    public function extractRelationships($model): array
    {
        // Use reflection to find relationship methods
        // Return: ['belongsTo' => [...], 'hasMany' => [...]]
    }
    
    public function analyzeDependencies(string $modelClass): array
    {
        // Find which models must exist before creating this one
        // Example: Invoice needs Customer, Product
        return [
            'required' => ['Customer', 'Product'],
            'optional' => ['Discount', 'Tax'],
        ];
    }
}
```

### 3. Complexity Calculator

**Purpose:** Automatically determine model complexity

```php
namespace LaravelAIEngine\Services\Agent\Discovery;

class ComplexityCalculator
{
    public function calculateComplexity(ModelMetadata $metadata): string
    {
        $score = 0;
        
        // Add points for complexity factors
        $score += count($metadata->relationships) * 10;
        $score += count($metadata->calculated_fields) * 5;
        $score += count($metadata->required_fields) * 2;
        $score += $metadata->has_validation ? 3 : 0;
        
        // Determine complexity level
        if ($score >= 20) return 'HIGH';
        if ($score >= 10) return 'MEDIUM';
        return 'SIMPLE';
    }
    
    public function determineStrategy(ModelMetadata $metadata): string
    {
        $complexity = $this->calculateComplexity($metadata);
        
        return match($complexity) {
            'HIGH' => 'agent_mode',
            'MEDIUM' => 'guided_flow',
            'SIMPLE' => 'quick_action',
        };
    }
}
```

### 4. Workflow Generator

**Purpose:** Dynamically generate workflows based on model structure

```php
namespace LaravelAIEngine\Services\Agent\Discovery;

class WorkflowGenerator
{
    public function generateWorkflow(ModelMetadata $metadata): string
    {
        // Generate workflow class dynamically
        $steps = $this->generateSteps($metadata);
        
        return $this->buildWorkflowClass($metadata->class, $steps);
    }
    
    protected function generateSteps(ModelMetadata $metadata): array
    {
        $steps = [];
        
        // Always start with data extraction
        $steps[] = 'extract_data';
        
        // Add relationship validation steps
        foreach ($metadata->relationships as $relation) {
            $steps[] = "validate_{$relation['name']}";
            $steps[] = "handle_missing_{$relation['name']}";
        }
        
        // Add confirmation step
        $steps[] = 'confirm_creation';
        
        // Add creation step
        $steps[] = 'create_record';
        
        return $steps;
    }
}
```

### 5. Dynamic Complexity Analyzer

**Purpose:** Update ComplexityAnalyzer with discovered models

```php
namespace LaravelAIEngine\Services\Agent\Discovery;

class DynamicComplexityAnalyzer extends ComplexityAnalyzer
{
    protected array $discoveredModels = [];
    
    public function __construct(
        AIEngineService $ai,
        ModelDiscoveryService $discovery
    ) {
        parent::__construct($ai);
        $this->discoveredModels = $discovery->discoverModels();
    }
    
    protected function buildAnalysisPrompt(string $message, UnifiedActionContext $context): string
    {
        $prompt = parent::buildAnalysisPrompt($message, $context);
        
        // Add dynamically discovered models
        $prompt .= "\n\nDISCOVERED MODELS AND COMPLEXITY:\n";
        
        foreach ($this->discoveredModels as $metadata) {
            $prompt .= "- {$metadata->name}: {$metadata->complexity}\n";
            $prompt .= "  Relationships: " . implode(', ', $metadata->relationships) . "\n";
            $prompt .= "  Strategy: {$metadata->strategy}\n\n";
        }
        
        return $prompt;
    }
}
```

---

## Implementation Example

### Step 1: Model Configuration

Models define their AI capabilities:

```php
namespace App\Models;

class Invoice extends Model
{
    use HasAICapabilities;
    
    public static function getAIConfiguration(): array
    {
        return [
            'fields' => [
                'customer_name' => 'required|string',
                'total' => 'calculated', // Auto-calculated
            ],
            'relationships' => [
                'customer' => [
                    'type' => 'belongsTo',
                    'required' => true,
                    'can_create' => false, // Must exist
                ],
                'items' => [
                    'type' => 'hasMany',
                    'required' => true,
                    'can_create' => true, // Can create on-the-fly
                    'nested_model' => Product::class,
                ],
            ],
            'workflow_type' => 'auto', // Auto-generate workflow
        ];
    }
}
```

### Step 2: Auto-Discovery Command

```bash
php artisan ai:discover-models
```

**Output:**
```
🔍 Discovering models...

✅ Invoice (HIGH complexity)
   - Relationships: customer, items
   - Strategy: agent_mode
   - Workflow: Auto-generated

✅ Order (HIGH complexity)
   - Relationships: customer, items, shipping
   - Strategy: agent_mode
   - Workflow: Auto-generated

✅ Product (MEDIUM complexity)
   - Relationships: category
   - Strategy: guided_flow
   - Workflow: Auto-generated

✅ Category (SIMPLE complexity)
   - Relationships: none
   - Strategy: quick_action
   - No workflow needed

📊 Summary:
   - 4 models discovered
   - 2 agent workflows generated
   - 1 guided flow configured
   - 1 quick action configured

💾 Configuration saved to: storage/ai-agent/discovered-models.json
```

### Step 3: Dynamic Complexity Analysis

```php
// ComplexityAnalyzer now knows about all models automatically
User: "Create invoice"
→ Checks discovered-models.json
→ Finds: Invoice = HIGH complexity, agent_mode
→ Routes to CreateInvoiceWorkflow (auto-generated)

User: "Create product"
→ Checks discovered-models.json
→ Finds: Product = MEDIUM complexity, guided_flow
→ Routes to DataCollector with Product fields

User: "Create category"
→ Checks discovered-models.json
→ Finds: Category = SIMPLE complexity, quick_action
→ Executes immediately
```

---

## Benefits

### 1. **Zero Configuration** ✅
```php
// Before: Manual configuration for each model
'workflows' => [
    CreateInvoiceWorkflow::class => ['create invoice'],
    CreateOrderWorkflow::class => ['create order'],
    // ... 50 more models
],

// After: Automatic discovery
// Just run: php artisan ai:discover-models
```

### 2. **Scales to Any Number of Models** ✅
- Add new model → Automatically discovered
- Change relationships → Automatically updated
- No manual configuration needed

### 3. **Intelligent Complexity Detection** ✅
```php
// System automatically knows:
Invoice (3 relationships) → HIGH → agent_mode
Order (2 relationships) → HIGH → agent_mode
Product (1 relationship) → MEDIUM → guided_flow
Category (0 relationships) → SIMPLE → quick_action
```

### 4. **Dynamic Workflow Generation** ✅
```php
// Workflow steps generated based on model structure
Invoice workflow:
1. Extract data
2. Validate customer (relationship)
3. Handle missing customer
4. Validate items (relationship)
5. Handle missing items
6. Calculate total (calculated field)
7. Confirm creation
8. Create invoice
```

### 5. **Relationship-Aware** ✅
```php
// System knows dependencies
Creating Invoice requires:
- Customer (must exist or create)
- Products (must exist or create)
  └─ Category (must exist or create)
```

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────┐
│                 Model Discovery Service                  │
│  • Scans app/Models                                     │
│  • Extracts metadata                                    │
│  • Analyzes relationships                               │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│              Complexity Calculator                       │
│  • Calculates complexity score                          │
│  • Determines strategy                                  │
│  • Identifies dependencies                              │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│              Workflow Generator                          │
│  • Generates workflow steps                             │
│  • Creates workflow classes                             │
│  • Registers workflows                                  │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│         Dynamic Complexity Analyzer                      │
│  • Uses discovered model metadata                       │
│  • Routes to appropriate strategy                       │
│  • No hardcoded rules needed                            │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│              Agent Orchestrator                          │
│  • Receives request                                     │
│  • Checks discovered models                             │
│  • Executes appropriate workflow                        │
└─────────────────────────────────────────────────────────┘
```

---

## Implementation Plan

### Phase 1: Model Discovery (Week 1)
- [ ] Create ModelDiscoveryService
- [ ] Create RelationshipAnalyzer
- [ ] Create ComplexityCalculator
- [ ] Create discovery command
- [ ] Test with 5-10 models

### Phase 2: Workflow Generation (Week 2)
- [ ] Create WorkflowGenerator
- [ ] Generate dynamic workflow classes
- [ ] Register workflows automatically
- [ ] Test generated workflows

### Phase 3: Integration (Week 3)
- [ ] Update ComplexityAnalyzer to use discovered models
- [ ] Update AgentOrchestrator to route dynamically
- [ ] Add caching for discovered models
- [ ] Performance optimization

### Phase 4: Polish (Week 4)
- [ ] Add model configuration validation
- [ ] Create documentation
- [ ] Add monitoring/logging
- [ ] Production testing

---

## Example: Complete Auto-Discovery Flow

```bash
# 1. Define models with AI capabilities
class Invoice extends Model {
    use HasAICapabilities;
    // Define relationships, fields, etc.
}

# 2. Run discovery
php artisan ai:discover-models

# 3. System automatically:
✅ Scans all models
✅ Analyzes relationships
✅ Calculates complexity
✅ Generates workflows
✅ Updates ComplexityAnalyzer
✅ Registers everything

# 4. Use immediately
User: "Create invoice with product iPhone"
→ System knows Invoice = HIGH complexity
→ Routes to auto-generated CreateInvoiceWorkflow
→ Workflow knows to validate Product
→ Workflow knows Product needs Category
→ Handles entire flow automatically

# 5. Add new model
class Quotation extends Model {
    use HasAICapabilities;
}

# 6. Re-run discovery
php artisan ai:discover-models

# 7. New model automatically available
User: "Create quotation"
→ System automatically knows how to handle it
```

---

## Your Question Answered

**Q:** "Should I embed or do something for all available models and their capabilities?"

**A:** Yes! Here's the approach:

### Option 1: Model Trait (Recommended)
```php
trait HasAICapabilities
{
    public static function getAIConfiguration(): array
    {
        return [
            'fields' => static::getAIFields(),
            'relationships' => static::getAIRelationships(),
            'complexity' => static::calculateAIComplexity(),
        ];
    }
}
```

### Option 2: Auto-Reflection (Fully Automatic)
```php
// System automatically extracts from model:
- $fillable → fields
- Relationship methods → relationships
- Validation rules → required fields
- No configuration needed!
```

### Option 3: Hybrid (Best of Both)
```php
// Auto-discover everything
// Allow manual override for special cases
class Invoice extends Model
{
    use HasAICapabilities;
    
    // Optional: Override auto-discovery
    public static function getAIConfiguration(): array
    {
        return array_merge(parent::getAIConfiguration(), [
            'custom_workflow' => CustomInvoiceWorkflow::class,
        ]);
    }
}
```

---

## Next Steps

Would you like me to:

1. **Implement Model Discovery Service** - Start with auto-scanning models
2. **Create Relationship Analyzer** - Extract relationships automatically
3. **Build Workflow Generator** - Generate workflows dynamically
4. **Update ComplexityAnalyzer** - Use discovered models instead of hardcoded rules

Which would you prefer to start with? 🚀
