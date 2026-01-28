# RAG & Autonomous Collector Complete Guide

This comprehensive guide covers the **RAG (Retrieval Augmented Generation)** system and **Autonomous Collector** pattern in Laravel AI Engine.

## Table of Contents

1. [Overview](#overview)
2. [RAG System](#rag-system)
   - [Vectorizable Trait](#vectorizable-trait)
   - [VectorizableInterface](#vectorizableinterface)
   - [Vector Search](#vector-search)
   - [Indexing Models](#indexing-models)
3. [Autonomous Collector](#autonomous-collector)
   - [AutonomousCollectorConfig](#autonomouscollectorconfig)
   - [Tools Pattern](#tools-pattern)
   - [DiscoverableAutonomousCollector](#discoverableautonomouscollector)
   - [AgentResponse](#agentresponse)
4. [Integration Examples](#integration-examples)
5. [Best Practices](#best-practices)

---

## Overview

The Laravel AI Engine provides two powerful systems for building intelligent applications:

| System | Purpose | Use Case |
|--------|---------|----------|
| **RAG** | Semantic search over your data | Find relevant records using natural language |
| **Autonomous Collector** | AI-driven data collection | Create/update entities through conversation |

These systems work together to enable natural language interactions with your application data.

---

## RAG System

RAG (Retrieval Augmented Generation) enables semantic search over your Eloquent models using vector embeddings.

### Vectorizable Trait

Add the `Vectorizable` trait to any model to enable vector search:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Contracts\VectorizableInterface;

class Product extends Model implements VectorizableInterface
{
    use Vectorizable;

    protected $fillable = ['name', 'description', 'price', 'category_id'];

    /**
     * REQUIRED: Define the content to be vectorized
     * This text will be converted to embeddings for semantic search
     */
    public function getVectorContent(): string
    {
        $content = [];
        
        // Include searchable text fields
        $content[] = "Product: {$this->name}";
        
        if ($this->description) {
            $content[] = "Description: {$this->description}";
        }
        
        // Include related data for better context
        if ($this->category) {
            $content[] = "Category: {$this->category->name}";
        }
        
        return implode("\n", $content);
    }

    /**
     * REQUIRED: Define metadata for filtering
     * These fields can be used to filter search results
     */
    public function getVectorMetadata(): array
    {
        return [
            'workspace_id' => $this->workspace_id,
            'category_id' => $this->category_id,
            'price' => $this->price,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
        ];
    }

    /**
     * OPTIONAL: Format for RAG display
     * Used when showing results to users or AI
     */
    public function toRAGContent(): string
    {
        return "**{$this->name}** - \${$this->price}\n{$this->description}";
    }

    /**
     * OPTIONAL: Control which records get indexed
     */
    public function shouldBeIndexed(): bool
    {
        return $this->is_active && !$this->trashed();
    }

    /**
     * OPTIONAL: Define Qdrant indexes for efficient filtering
     */
    public function getQdrantIndexes(): array
    {
        return [
            'workspace_id' => 'integer',
            'category_id' => 'integer',
            'is_active' => 'bool',
            'created_by' => 'integer',
        ];
    }
}
```

### VectorizableInterface

The `VectorizableInterface` defines the contract for vectorizable models:

```php
interface VectorizableInterface
{
    // Required methods
    public function getVectorContent(): string;      // Text to embed
    public function getVectorMetadata(): array;      // Metadata for filtering
    
    // Methods with defaults in trait
    public function toRAGContent(): string;          // Formatted for display
    public function getVectorCollectionName(): string; // Collection name
    public function shouldBeIndexed(): bool;         // Whether to index
    public function getQdrantIndexes(): array;       // Custom indexes
}
```

### Vector Search

Once your model is vectorizable, you can perform semantic searches:

```php
// Basic semantic search
$products = Product::vectorSearch('wireless charging accessories');

// Search with limit
$products = Product::vectorSearch('laptop accessories', limit: 5);

// Search with metadata filters
$products = Product::vectorSearch(
    query: 'office supplies',
    filters: [
        'workspace_id' => auth()->user()->workspace_id,
        'is_active' => true,
    ]
);

// Search with score threshold
$products = Product::vectorSearch(
    query: 'ergonomic furniture',
    minScore: 0.7
);
```

### Indexing Models

Index your models for vector search:

```bash
# Analyze a model for vectorization
php artisan vector:analyze "App\Models\Product"

# Generate vector configuration
php artisan vector:generate-config "App\Models\Product"

# Index all records
php artisan vector:index "App\Models\Product"

# Re-index with force
php artisan vector:index "App\Models\Product" --force

# Check indexing status
php artisan vector:status
```

**Auto-indexing**: Enable automatic indexing on model changes:

```php
// config/ai-engine.php
'vector' => [
    'auto_index' => true,  // Index on create/update
],
```

---

## Autonomous Collector

The Autonomous Collector enables AI-driven data collection through natural conversation.

### AutonomousCollectorConfig

Create a configuration that defines the collection goal, tools, and output schema:

```php
<?php

namespace App\AI\Configs;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Contracts\DiscoverableAutonomousCollector;

class InvoiceAutonomousConfig implements DiscoverableAutonomousCollector
{
    public static function getName(): string
    {
        return 'invoice';
    }

    public static function getDescription(): string
    {
        return 'Create a sales invoice with customer and products';
    }

    public static function getPriority(): int
    {
        return 100; // Higher = checked first for intent matching
    }

    public static function getConfig(): AutonomousCollectorConfig
    {
        return self::create();
    }

    public static function create(): AutonomousCollectorConfig
    {
        $workspace = getActiveWorkSpace() ?: 1;
        
        return new AutonomousCollectorConfig(
            // The goal describes what the AI should accomplish
            goal: 'Create a sales invoice',
            
            // Description helps AI understand the task
            description: <<<DESC
            Help the user create an invoice by:
            1. Finding or creating the customer
            2. Finding or creating products
            3. Calculating totals
            4. Confirming before creation
            DESC,
            
            // Tools the AI can use
            tools: [
                'find_customer' => [
                    'description' => 'Search for customer by name or email',
                    'parameters' => [
                        'query' => 'required|string - Name or email to search',
                    ],
                    'handler' => function ($query) use ($workspace) {
                        $query = is_array($query) ? ($query['query'] ?? '') : $query;
                        
                        $customers = Customer::where('workspace', $workspace)
                            ->where(function($q) use ($query) {
                                $q->where('name', 'like', "%{$query}%")
                                  ->orWhere('email', 'like', "%{$query}%");
                            })
                            ->limit(5)
                            ->get(['id', 'user_id', 'name', 'email']);
                        
                        if ($customers->isEmpty()) {
                            return [
                                'found' => false,
                                'message' => "Customer '{$query}' not found. Ask user if they want to create.",
                            ];
                        }
                        
                        return [
                            'found' => true,
                            'customers' => $customers->toArray(),
                        ];
                    },
                ],
                
                'create_customer' => [
                    'description' => 'Create a new customer. ONLY call after user confirms.',
                    'parameters' => [
                        'name' => 'required|string',
                        'email' => 'required|email',
                    ],
                    'handler' => function ($data) use ($workspace) {
                        // Create customer logic
                        $customer = Customer::create([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'workspace' => $workspace,
                        ]);
                        
                        return [
                            'created' => true,
                            'id' => $customer->id,
                            'name' => $customer->name,
                        ];
                    },
                ],
                
                'find_product' => [
                    'description' => 'Search for products by name',
                    'parameters' => [
                        'query' => 'required|string',
                    ],
                    'handler' => function ($query) use ($workspace) {
                        // Product search logic
                    },
                ],
                
                // Vector search tool example
                'suggest_category' => [
                    'description' => 'Find best matching category using semantic search',
                    'parameters' => [
                        'product_name' => 'required|string',
                    ],
                    'handler' => function ($data) use ($workspace) {
                        $productName = $data['product_name'] ?? '';
                        
                        // Try vector search first
                        if (method_exists(Category::class, 'vectorSearch')) {
                            $results = Category::vectorSearch($productName, 3);
                            if (!empty($results)) {
                                return [
                                    'found' => true,
                                    'method' => 'vector_search',
                                    'matches' => collect($results)->map(fn($r) => [
                                        'id' => $r->id,
                                        'name' => $r->name,
                                        'score' => $r->vector_score ?? null,
                                    ])->toArray(),
                                    'best_match' => $results[0]->name,
                                ];
                            }
                        }
                        
                        // Fallback to pattern matching
                        return ['found' => false, 'suggested' => 'General'];
                    },
                ],
            ],
            
            // Expected output schema
            outputSchema: [
                'customer_id' => 'integer|required',
                'items' => [
                    'type' => 'array',
                    'required' => true,
                    'items' => [
                        'product_id' => 'integer|required',
                        'quantity' => 'integer|required',
                        'unit_price' => 'numeric|required',
                    ],
                ],
                'subtotal' => 'numeric|required',
                'total' => 'numeric|required',
            ],
            
            // Called when collection is complete
            onComplete: function (array $data) use ($workspace) {
                // Create the invoice
                $invoice = Invoice::create([
                    'customer_id' => $data['customer_id'],
                    'workspace' => $workspace,
                    // ... other fields
                ]);
                
                return [
                    'success' => true,
                    'invoice_id' => $invoice->id,
                ];
            },
            
            // Require user confirmation before completing
            confirmBeforeComplete: true,
            
            // Additional instructions for the AI
            systemPromptAddition: <<<PROMPT
            IMPORTANT RULES:
            
            1. CONFIRMATION REQUIRED:
               - Always ask before creating new entities
               - Show summary before final creation
               - Wait for explicit "yes" or "confirm"
            
            2. WHEN CREATING PRODUCTS:
               - Use suggest_category to find best category
               - Show: "Product: [name], Category: [suggested], Price: $[price]"
               - Ask: "Is this correct? (yes/no)"
            
            3. CALCULATIONS:
               - Line total = quantity × unit_price
               - Subtotal = sum of line totals
               - Total = subtotal (no tax by default)
            PROMPT,
            
            // Unique name for this collector
            name: 'invoice',
        );
    }
}
```

### Tools Pattern

Tools are the building blocks of autonomous collectors. Each tool should:

1. **Search first, create second**: Always try to find existing entities
2. **Return structured data**: Include `found`, `message`, and data
3. **Require confirmation**: Never auto-create without user consent

```php
// Good tool pattern
'find_entity' => [
    'description' => 'Search for entity. Returns not_found if no match.',
    'parameters' => ['query' => 'required|string'],
    'handler' => function ($query) {
        $results = Entity::search($query)->get();
        
        if ($results->isEmpty()) {
            return [
                'found' => false,
                'message' => "Not found. Would you like to create it?",
            ];
        }
        
        return [
            'found' => true,
            'entities' => $results->toArray(),
        ];
    },
],

'create_entity' => [
    'description' => 'Create entity. ONLY call after user confirms.',
    'parameters' => [
        'name' => 'required|string',
        'type' => 'required|string',
    ],
    'handler' => function ($data) {
        $entity = Entity::create($data);
        
        return [
            'created' => true,
            'id' => $entity->id,
            'name' => $entity->name,
        ];
    },
],
```

### DiscoverableAutonomousCollector

Implement this interface to auto-register your collector:

```php
interface DiscoverableAutonomousCollector
{
    public static function getName(): string;
    public static function getDescription(): string;
    public static function getPriority(): int;
    public static function getConfig(): AutonomousCollectorConfig;
    
    // Optional: Permission-based access control
    public static function getAllowedOperations(?int $userId): array;
    
    // Optional: Model filtering
    public static function getModelClass(): ?string;
    public static function getFilterConfig(): array;
}
```

**Permission-based access control**:

```php
public static function getAllowedOperations(?int $userId): array
{
    if (!$userId) {
        return [];
    }
    
    $user = User::find($userId);
    if (!$user) {
        return [];
    }
    
    $operations = ['list'];
    
    if ($user->can('invoice create') || $user->type === 'admin') {
        $operations[] = 'create';
    }
    
    if ($user->can('invoice edit')) {
        $operations[] = 'update';
    }
    
    if ($user->can('invoice delete') && $user->type === 'admin') {
        $operations[] = 'delete';
    }
    
    return $operations;
}
```

**Auto-discovery registration**:

```php
// In a service provider or bootstrap
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;

app(AutonomousCollectorDiscoveryService::class)->registerDiscoveredCollectors();
```

### AgentResponse

The `AgentResponse` DTO provides structured responses with UI hints:

```php
// Response structure
{
    "success": true,
    "message": "Product details:\n- Name: Laptop Stand\n- Category: Electronics\n- Price: $45\n\nIs this correct?",
    "needs_user_input": true,
    "is_complete": false,
    "required_inputs": [
        {
            "name": "confirmation",
            "type": "confirm",
            "label": "Confirm",
            "required": true,
            "options": [
                {"value": "yes", "label": "Yes"},
                {"value": "no", "label": "No"}
            ]
        },
        {
            "name": "category",
            "type": "text",
            "label": "Category",
            "required": false,
            "default": "Electronics",
            "placeholder": "Enter category or keep suggested"
        },
        {
            "name": "price",
            "type": "number",
            "label": "Price",
            "required": true,
            "default": "45"
        }
    ]
}
```

**Input types supported**:

| Type | Description | Properties |
|------|-------------|------------|
| `confirm` | Yes/No buttons | `options` |
| `text` | Text input | `default`, `placeholder` |
| `number` | Numeric input | `default`, `min`, `max` |
| `email` | Email input | `placeholder` |
| `select` | Dropdown | `options` |
| `readonly` | Display only | `value` |

**Using in frontend**:

```javascript
// React example
function ChatResponse({ response }) {
    const { message, required_inputs, needs_user_input } = response;
    
    if (needs_user_input && required_inputs) {
        return (
            <div>
                <p>{message}</p>
                <DynamicForm 
                    inputs={required_inputs}
                    onSubmit={handleSubmit}
                />
            </div>
        );
    }
    
    return <p>{message}</p>;
}

function DynamicForm({ inputs, onSubmit }) {
    return (
        <form onSubmit={onSubmit}>
            {inputs.map(input => (
                <FormField key={input.name} {...input} />
            ))}
        </form>
    );
}
```

---

## Integration Examples

### Complete Invoice Creation Flow

```php
// 1. User sends message
$response = $orchestrator->process(
    'create invoice for John Doe with 2 laptops at $999 each',
    $sessionId,
    $userId
);

// 2. AI searches for customer and products
// Response: "Customer found. Product 'laptop' not found. Create it?"
// required_inputs: [{ type: 'confirm', ... }]

// 3. User confirms
$response = $orchestrator->process('yes', $sessionId, $userId);

// 4. AI suggests category
// Response: "Product: Laptop, Category: Electronics, Price: $999. Correct?"
// required_inputs: [{ type: 'confirm' }, { name: 'category', default: 'Electronics' }]

// 5. User confirms
$response = $orchestrator->process('yes', $sessionId, $userId);

// 6. AI shows invoice summary
// Response: "Invoice Summary:\n- Customer: John Doe\n- Items: 2x Laptop @ $999\n- Total: $1998\n\nCreate invoice?"
// required_inputs: [{ type: 'confirm' }]

// 7. User confirms
$response = $orchestrator->process('yes', $sessionId, $userId);

// 8. Invoice created
// Response: "✅ Invoice #INV001 created successfully!"
// is_complete: true
```

### RAG-Enhanced Search Tool

```php
'search_similar_accounts' => [
    'description' => 'Find similar accounts using semantic search',
    'parameters' => [
        'account_name' => 'required|string',
    ],
    'handler' => function ($data) use ($workspace) {
        $name = $data['account_name'];
        
        // Check exact match first
        $exact = ChartOfAccount::where('name', $name)
            ->where('workspace', $workspace)
            ->first();
        
        if ($exact) {
            return [
                'exact_match' => true,
                'account' => $exact->toArray(),
                'message' => "Account '{$name}' already exists.",
            ];
        }
        
        // Try vector search
        try {
            $results = ChartOfAccount::vectorSearch($name, 5);
            
            if (!empty($results)) {
                return [
                    'exact_match' => false,
                    'method' => 'vector_search',
                    'similar' => collect($results)->map(fn($r) => [
                        'id' => $r->id,
                        'name' => $r->name,
                        'type' => $r->types->name ?? 'Unknown',
                        'score' => $r->vector_score ?? null,
                    ])->toArray(),
                    'message' => "Found similar: '{$results[0]->name}'. Use this?",
                ];
            }
        } catch (\Exception $e) {
            // Vector search not available
        }
        
        return [
            'exact_match' => false,
            'similar' => [],
            'message' => "No similar accounts found. Safe to create.",
        ];
    },
],
```

---

## Best Practices

### 1. Vector Content Design

```php
// ✅ Good: Include context and relationships
public function getVectorContent(): string
{
    return implode("\n", [
        "Account: {$this->name}",
        "Code: {$this->code}",
        "Type: " . ($this->types->name ?? 'Unknown'),
        "Category: " . ($this->subType->name ?? 'General'),
        "Description: {$this->description}",
    ]);
}

// ❌ Bad: Just the name
public function getVectorContent(): string
{
    return $this->name;
}
```

### 2. Metadata for Filtering

```php
// ✅ Good: Include all filterable fields
public function getVectorMetadata(): array
{
    return [
        'workspace_id' => $this->workspace_id,
        'user_id' => $this->created_by,
        'type_id' => $this->type,
        'status' => $this->status,
        'is_active' => $this->is_active,
    ];
}

// ❌ Bad: Missing important filters
public function getVectorMetadata(): array
{
    return ['id' => $this->id];
}
```

### 3. Tool Design

```php
// ✅ Good: Clear description, structured response
'find_product' => [
    'description' => 'Search products by name. Returns found status and matches.',
    'parameters' => [
        'query' => 'required|string - Product name to search',
    ],
    'handler' => function ($query) {
        $products = Product::search($query)->get();
        
        return [
            'found' => $products->isNotEmpty(),
            'count' => $products->count(),
            'products' => $products->toArray(),
            'message' => $products->isEmpty() 
                ? "No products found. Create new?" 
                : "Found {$products->count()} products.",
        ];
    },
],

// ❌ Bad: Vague description, unstructured response
'search' => [
    'description' => 'Search stuff',
    'handler' => fn($q) => Product::where('name', 'like', "%$q%")->get(),
],
```

### 4. Confirmation Flow

```php
// ✅ Good: Always confirm before creating
systemPromptAddition: <<<PROMPT
RULES:
1. NEVER create without explicit confirmation
2. Show details: "Name: X, Price: $Y, Category: Z"
3. Ask: "Is this correct? (yes/no)"
4. Wait for "yes", "confirm", "create it"
5. If user says "no" or suggests changes, update and re-confirm
PROMPT,

// ❌ Bad: Auto-create without asking
systemPromptAddition: 'Create entities as needed.',
```

### 5. Error Handling

```php
'create_entity' => [
    'handler' => function ($data) {
        try {
            $entity = Entity::create($data);
            
            return [
                'success' => true,
                'id' => $entity->id,
                'message' => "Created successfully!",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => "Failed to create. Please try again.",
            ];
        }
    },
],
```

---

## Configuration Reference

### ai-engine.php

```php
return [
    // Vector search settings
    'vector' => [
        'driver' => 'qdrant',
        'auto_index' => true,
        'collection_prefix' => 'vec_',
        'embedding_model' => 'text-embedding-3-small',
    ],
    
    // RAG settings
    'rag' => [
        'auto_update_limitations' => true,
        'max_results' => 10,
        'min_score' => 0.5,
    ],
    
    // Autonomous collector settings
    'autonomous_collector' => [
        'discovery_paths' => [
            app_path('AI/Configs'),
        ],
        'cache_configs' => true,
    ],
];
```

---

## Troubleshooting

### Vector search returns no results

1. Check if model is indexed: `php artisan vector:status`
2. Verify `getVectorContent()` returns meaningful text
3. Check metadata filters match your query
4. Lower `minScore` threshold

### Autonomous collector not detected

1. Ensure class implements `DiscoverableAutonomousCollector`
2. Check discovery path in config
3. Call `registerDiscoveredCollectors()` in service provider
4. Verify `getName()` returns unique name

### Tools not executing

1. Check tool name matches exactly
2. Verify handler returns array (not model)
3. Check parameter validation
4. Review AI engine logs: `storage/logs/ai-engine.log`

---

## API Reference

### Vectorizable Trait Methods

| Method | Description |
|--------|-------------|
| `vectorSearch($query, $limit)` | Perform semantic search |
| `getVectorContent()` | Get content for embedding |
| `getVectorMetadata()` | Get metadata for filtering |
| `toRAGContent()` | Format for RAG display |
| `shouldBeIndexed()` | Check if should be indexed |
| `getQdrantIndexes()` | Get custom Qdrant indexes |

### AgentResponse Factory Methods

| Method | Description |
|--------|-------------|
| `success($message, $data)` | Successful completion |
| `failure($message, $data)` | Failed operation |
| `needsUserInput($message, ...)` | Awaiting user input |
| `needsInputs($message, $inputs)` | With structured inputs |
| `needsConfirmation($message)` | Yes/no confirmation |
| `needsSelection($message, $options)` | Selection from options |

### AutonomousCollectorConfig Properties

| Property | Type | Description |
|----------|------|-------------|
| `goal` | string | What to accomplish |
| `description` | string | Detailed description |
| `tools` | array | Available tools |
| `outputSchema` | array | Expected output format |
| `onComplete` | callable | Completion handler |
| `confirmBeforeComplete` | bool | Require confirmation |
| `systemPromptAddition` | string | Extra AI instructions |
| `name` | string | Unique identifier |

---

## Further Reading

- [Data Collector Chat Guide](DATA_COLLECTOR_CHAT_GUIDE.md)
- [Autonomous Collector Guide](AUTONOMOUS_COLLECTOR_GUIDE.md)
- [Vector Indexer Guide](VECTOR_INDEXER_GUIDE.md)
- [AI Engine Configuration](CONFIGURATION_GUIDE.md)
