# RAG and Workflows Guide

This guide covers how to use the Retrieval-Augmented Generation (RAG) system and AI Workflows in Laravel AI Engine.

## Table of Contents

1. [RAG (Retrieval-Augmented Generation)](#rag-retrieval-augmented-generation)
   - [Overview](#overview)
   - [Setting Up Models for RAG](#setting-up-models-for-rag)
   - [Vector Indexing](#vector-indexing)
   - [Searching with RAG](#searching-with-rag)
   - [Aggregate Queries](#aggregate-queries)
   - [Multi-Tenant Filtering](#multi-tenant-filtering)
2. [Workflows](#workflows)
   - [Overview](#workflow-overview)
   - [Creating a Workflow](#creating-a-workflow)
   - [Workflow Steps](#workflow-steps)
   - [Entity Resolution](#entity-resolution)
   - [Confirmation Flow](#confirmation-flow)
3. [Chat API Integration](#chat-api-integration)
4. [Configuration](#configuration)
5. [Troubleshooting](#troubleshooting)

---

## RAG (Retrieval-Augmented Generation)

### Overview

RAG enables AI to answer questions using your application's data. It works by:

1. **Indexing** - Converting your model data into vector embeddings stored in Qdrant
2. **Searching** - Finding relevant data using semantic similarity
3. **Generating** - Using AI to answer questions with the retrieved context

### Setting Up Models for RAG

#### Step 1: Add the Vectorizable Trait

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;

class Invoice extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'invoice_id', 'user_id', 'customer_id', 'issue_date', 
        'due_date', 'status', 'created_by'
    ];
}
```

#### Step 2: Define Vector Content

Override `getVectorContent()` to provide meaningful content for semantic search:

```php
public function getVectorContent(): string
{
    $parts = ["Invoice #{$this->invoice_id}"];
    
    // Add customer info
    if ($this->customer) {
        $parts[] = "Customer: {$this->customer->name}";
        $parts[] = "Email: {$this->customer->email}";
    }
    
    // Add dates
    $parts[] = "Issue Date: {$this->issue_date}";
    $parts[] = "Due Date: {$this->due_date}";
    $parts[] = "Status: {$this->status}";
    
    // Add items
    if ($this->items->isNotEmpty()) {
        $itemLines = $this->items->map(fn($item) => 
            "{$item->description} x{$item->quantity} @ \${$item->price}"
        );
        $parts[] = "Items: " . $itemLines->implode(', ');
    }
    
    // Add total
    $parts[] = "Total: \${$this->getTotal()}";
    
    return implode("\n", $parts);
}
```

#### Step 3: Define Vector Metadata

Override `getVectorMetadata()` for filtering and multi-tenant support:

```php
public function getVectorMetadata(): array
{
    return [
        'user_id' => $this->created_by,      // Owner for multi-tenant filtering
        'customer_id' => $this->customer_id,
        'workspace' => $this->workspace,
        'status' => $this->status,
        'created_at_ts' => $this->created_at?->timestamp,
    ];
}
```

**Important**: The `user_id` in metadata is used for multi-tenant filtering. Make sure it represents the **owner** of the record, not a reference to another entity.

### Vector Indexing

#### Index a Single Model

```bash
php artisan ai-engine:vector-index "App\Models\Invoice" --id=123
```

#### Index All Records

```bash
php artisan ai-engine:vector-index "App\Models\Invoice"
```

#### Force Re-index (Recreates Collection)

```bash
php artisan ai-engine:vector-index "App\Models\Invoice" --force
```

#### Auto-Indexing

Models are automatically indexed on create/update when `auto_index` is enabled:

```php
// config/ai-engine.php
'vector' => [
    'auto_index' => env('VECTOR_AUTO_INDEX', true),
],
```

### Searching with RAG

#### Via Chat API

```bash
curl -X POST 'https://your-app.com/ai-demo/chat/send' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "message": "show my invoices",
    "session_id": "unique-session-id",
    "intelligent_rag": true
  }'
```

#### Via Code

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

$rag = app(IntelligentRAGService::class);

$response = $rag->processMessage(
    message: 'show my invoices',
    sessionId: 'session-123',
    availableCollections: [App\Models\Invoice::class],
    conversationHistory: [],
    options: ['engine' => 'openai', 'model' => 'gpt-4o-mini'],
    userId: auth()->id()
);

echo $response->getContent();
```

#### Direct Vector Search

```php
use LaravelAIEngine\Services\Vector\VectorSearchService;

$vectorSearch = app(VectorSearchService::class);

$results = $vectorSearch->search(
    modelClass: App\Models\Invoice::class,
    query: 'unpaid invoices',
    limit: 10,
    threshold: 0.3,
    filters: [],
    userId: auth()->id()
);

foreach ($results as $invoice) {
    echo "Invoice #{$invoice->id} - Score: {$invoice->vector_score}\n";
}
```

### Aggregate Queries

The system automatically detects aggregate queries and returns counts:

```bash
# Examples of aggregate queries:
"how many invoices do I have?"
"count my orders this month"
"total invoices in January 2026"
```

Response:
```
Based on your data:
- **5** Invoice(s) (from 2026-01-01 to 2026-01-31)
```

#### Supported Date Filters

- `today` - Current day
- `this week` - Current week (Monday to Sunday)
- `this month` - Current month
- `January 2026` - Specific month
- `2026` - Specific year

### Multi-Tenant Filtering

All RAG queries are automatically filtered by `user_id` to ensure users only see their own data.

#### How It Works

1. The `user_id` from your model's `getVectorMetadata()` is stored in the vector index
2. When searching, the system filters by the authenticated user's ID
3. Results only include records where `metadata.user_id` matches the current user

#### Index Types

The system automatically creates indexes with correct types:

| Field Pattern | Index Type |
|--------------|------------|
| `*_id` | `integer` |
| `*_ts`, `*_timestamp` | `integer` |
| `status`, `type`, `visibility` | `keyword` |
| `is_*`, `has_*` | `bool` |

---

## Workflows

### Workflow Overview

Workflows guide users through multi-step processes like creating invoices, orders, or customers via conversational AI.

### Creating a Workflow

```php
<?php

namespace App\AI\Workflows;

use LaravelAIEngine\Workflows\BaseWorkflow;
use LaravelAIEngine\Workflows\WorkflowStep;

class CreateInvoiceWorkflow extends BaseWorkflow
{
    public function getName(): string
    {
        return 'create_invoice';
    }
    
    public function getDescription(): string
    {
        return 'Create a new invoice for a customer';
    }
    
    public function getTriggerPhrases(): array
    {
        return [
            'create invoice',
            'new invoice',
            'make an invoice',
            'invoice for',
        ];
    }
    
    public function getSteps(): array
    {
        return [
            new WorkflowStep(
                name: 'collect_customer',
                prompt: 'Who is this invoice for? Please provide the customer name.',
                validation: ['customer' => 'required|string'],
                entityType: 'customer'
            ),
            new WorkflowStep(
                name: 'collect_items',
                prompt: 'What items should be on this invoice?',
                validation: ['items' => 'required|array'],
                entityType: 'product'
            ),
            new WorkflowStep(
                name: 'confirm',
                prompt: 'Please confirm the invoice details.',
                isConfirmation: true
            ),
        ];
    }
    
    public function execute(array $data): mixed
    {
        return Invoice::create([
            'customer_id' => $data['customer']['id'],
            'items' => $data['items'],
            'created_by' => $data['user_id'],
        ]);
    }
}
```

### Workflow Steps

#### Step Types

1. **Data Collection** - Collects user input
2. **Entity Resolution** - Resolves references to existing entities (customers, products)
3. **Confirmation** - Shows summary and asks for confirmation

#### Step Configuration

```php
new WorkflowStep(
    name: 'step_name',           // Unique identifier
    prompt: 'Question to ask',   // Displayed to user
    validation: [                // Laravel validation rules
        'field' => 'required|string'
    ],
    entityType: 'customer',      // For entity resolution
    isConfirmation: false,       // Is this a confirmation step?
    isOptional: false,           // Can be skipped?
)
```

### Entity Resolution

When a step has `entityType`, the system automatically:

1. Searches for matching entities in the database
2. Presents options if multiple matches found
3. Creates new entity if no match and user confirms

```php
// User says: "create invoice for John"
// System finds: Customer "John Smith" (ID: 123)
// Response: "I found customer John Smith. Is this correct?"
```

### Confirmation Flow

The final step typically shows a summary:

```
**Please confirm the following details:**

**Customer:** John Smith
**Items:**
- Widget x2 @ $50 = $100
- Gadget x1 @ $75 = $75

**Grand Total:** $175

Would you like to proceed? Type 'yes' to confirm.
```

---

## Chat API Integration

### Endpoint

```
POST /ai-demo/chat/send
```

### Request

```json
{
  "message": "create an invoice for John with 2 widgets at $50",
  "session_id": "unique-session-id",
  "memory": true,
  "actions": true,
  "intelligent_rag": true
}
```

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `message` | string | required | User's message |
| `session_id` | string | required | Unique session identifier |
| `memory` | boolean | true | Enable conversation memory |
| `actions` | boolean | true | Enable workflows/actions |
| `intelligent_rag` | boolean | true | Enable RAG search |

### Response

```json
{
  "success": true,
  "response": "I found 2 invoices...",
  "actions": [],
  "session_id": "unique-session-id",
  "metadata": {
    "user_id": "58",
    "used_rag": true,
    "sources": [
      {
        "id": 123,
        "model_class": "App\\Models\\Invoice",
        "relevance": 85.5,
        "content_preview": "Invoice #INV-001..."
      }
    ],
    "workflow_active": false
  }
}
```

---

## Configuration

### Environment Variables

```env
# Vector Database
QDRANT_HOST=localhost
QDRANT_PORT=6333
QDRANT_API_KEY=your-api-key

# Auto-indexing
VECTOR_AUTO_INDEX=true

# RAG Settings
INTELLIGENT_RAG_MIN_SCORE=0.3
INTELLIGENT_RAG_FALLBACK_THRESHOLD=0.0

# AI Engine
AI_ENGINE_DEFAULT=openai
OPENAI_API_KEY=your-openai-key
```

### Config File

```php
// config/ai-engine.php
return [
    'vector' => [
        'auto_index' => env('VECTOR_AUTO_INDEX', true),
        'driver' => env('VECTOR_DRIVER', 'qdrant'),
        'embedding_model' => env('VECTOR_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'dimensions' => env('VECTOR_DIMENSIONS', 3072),
    ],
    
    'intelligent_rag' => [
        'min_relevance_score' => env('INTELLIGENT_RAG_MIN_SCORE', 0.3),
        'fallback_threshold' => env('INTELLIGENT_RAG_FALLBACK_THRESHOLD', 0.0),
        'enable_database_fallback' => env('INTELLIGENT_RAG_DB_FALLBACK', false),
        'database_fallback_limit' => 20,
    ],
];
```

---

## Troubleshooting

### No Results Found

1. **Check if model is indexed:**
   ```bash
   php artisan ai-engine:vector-index "App\Models\Invoice" --id=123
   ```

2. **Verify user_id in metadata:**
   ```php
   $invoice = Invoice::find(123);
   dd($invoice->getVectorMetadata());
   // Should show: ['user_id' => 58, ...]
   ```

3. **Check index types:**
   ```bash
   php artisan tinker
   >>> $driver = app(\LaravelAIEngine\Services\Vector\VectorDriverManager::class)->driver();
   >>> $driver->getExistingIndexesWithTypes('vec_invoice');
   // Should show: ['user_id' => 'integer', 'created_at_ts' => 'integer', ...]
   ```

### Low Relevance Scores

1. **Improve vector content:**
   ```php
   // Bad: Just raw field values
   public function getVectorContent(): string {
       return implode(' ', $this->toArray());
   }
   
   // Good: Meaningful, descriptive content
   public function getVectorContent(): string {
       return "Invoice #{$this->invoice_id}\n" .
              "Customer: {$this->customer->name}\n" .
              "Total: \${$this->getTotal()}";
   }
   ```

2. **Re-index after changes:**
   ```bash
   php artisan ai-engine:vector-index "App\Models\Invoice" --force
   ```

### Memory Issues

1. **Disable database fallback:**
   ```env
   INTELLIGENT_RAG_DB_FALLBACK=false
   ```

2. **Reduce max results:**
   ```php
   // config/ai-engine.php
   'intelligent_rag' => [
       'max_context_items' => 5,
       'database_fallback_limit' => 10,
   ],
   ```

### Index Type Mismatch

The system auto-fixes index types, but you can manually fix:

```bash
php artisan tinker
>>> $driver = app(\LaravelAIEngine\Services\Vector\VectorDriverManager::class)->driver();
>>> $driver->deletePayloadIndex('vec_invoice', 'user_id');
>>> $driver->createPayloadIndex('vec_invoice', 'user_id', 'integer');
```

---

## Quick Start Example

### 1. Create a Model with RAG Support

```php
// app/Models/Product.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;

class Product extends Model
{
    use Vectorizable;
    
    protected $fillable = ['name', 'description', 'price', 'category', 'user_id'];
    
    public function getVectorContent(): string
    {
        return "{$this->name}\n{$this->description}\nCategory: {$this->category}\nPrice: \${$this->price}";
    }
    
    public function getVectorMetadata(): array
    {
        return [
            'user_id' => $this->user_id,
            'category' => $this->category,
            'price' => $this->price,
            'created_at_ts' => $this->created_at?->timestamp,
        ];
    }
}
```

### 2. Index Your Data

```bash
php artisan ai-engine:vector-index "App\Models\Product"
```

### 3. Query via Chat

```bash
curl -X POST 'https://your-app.com/ai-demo/chat/send' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"message": "show me products under $100", "session_id": "test", "intelligent_rag": true}'
```

### 4. Create a Workflow

```php
// app/AI/Workflows/CreateProductWorkflow.php
<?php

namespace App\AI\Workflows;

use App\Models\Product;
use LaravelAIEngine\Workflows\BaseWorkflow;
use LaravelAIEngine\Workflows\WorkflowStep;

class CreateProductWorkflow extends BaseWorkflow
{
    public function getName(): string { return 'create_product'; }
    public function getDescription(): string { return 'Create a new product'; }
    public function getTriggerPhrases(): array { return ['create product', 'new product', 'add product']; }
    
    public function getSteps(): array
    {
        return [
            new WorkflowStep('name', 'What is the product name?', ['name' => 'required|string']),
            new WorkflowStep('description', 'Describe the product:', ['description' => 'required|string']),
            new WorkflowStep('price', 'What is the price?', ['price' => 'required|numeric|min:0']),
            new WorkflowStep('confirm', 'Please confirm:', isConfirmation: true),
        ];
    }
    
    public function execute(array $data): mixed
    {
        return Product::create([
            'name' => $data['name'],
            'description' => $data['description'],
            'price' => $data['price'],
            'user_id' => $data['user_id'],
        ]);
    }
}
```

### 5. Register the Workflow

```php
// app/Providers/AppServiceProvider.php
use LaravelAIEngine\Services\Agent\WorkflowRegistry;

public function boot()
{
    app(WorkflowRegistry::class)->register(new \App\AI\Workflows\CreateProductWorkflow());
}
```

### 6. Use via Chat

```bash
curl -X POST 'https://your-app.com/ai-demo/chat/send' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"message": "create a new product called Widget for $50", "session_id": "test", "actions": true}'
```

---

## Support

For issues or questions, check:
- GitHub Issues
- Documentation at `/docs`
- Laravel AI Engine Discord
