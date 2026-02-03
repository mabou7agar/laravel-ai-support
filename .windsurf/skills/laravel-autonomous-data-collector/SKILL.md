---
name: laravel-autonomous-data-collector
description: Create goal-based AI workflows that replace traditional forms and field-by-field data collection. Use this when the user wants to build ANY workflow for data collection, onboarding, form creation, or multi-step process. This is the RECOMMENDED approach over traditional workflows.
---

# Laravel Autonomous Data Collector

Create AI-powered goal-based workflows that replace traditional forms and rigid field-by-field data collection with natural, intelligent conversations.

## 🎯 Why Autonomous Over Traditional?

**Traditional Workflow** ❌:
- Define every field with type and validation
- Rigid field-by-field conversation
- Manual entity resolution
- Complex configuration

**Autonomous Workflow** ✅:
- Just define the goal
- AI handles conversation naturally
- Automatic entity resolution
- Simple, powerful configuration

## When to Use This Skill

**Use this for ALL workflow and data collection needs**:
- ✅ Creating any form or data collection
- ✅ Building onboarding/signup processes
- ✅ Invoice/order creation workflows
- ✅ Multi-step wizards
- ✅ Entity resolution (finding or creating records)
- ✅ Survey and questionnaire systems
- ✅ Application/registration forms
- ✅ Any goal-based data gathering

**This replaces traditional DataCollector** - it's simpler, more powerful, and more natural.

## Key Concepts

### The Paradigm Shift: Traditional → Autonomous

| Aspect | Traditional DataCollector ❌ | Autonomous Collector ✅ |
|--------|------------------------------|-------------------------|
| **Configuration** | Define every field, type, validation | Just define the goal |
| **Conversation** | Rigid field-by-field | Natural, contextual |
| **Entity Resolution** | Manual implementation | Automatic with tools |
| **User Experience** | Form-like, mechanical | Conversational, intelligent |
| **Flexibility** | Fixed field order | AI adapts to user input |
| **Code Complexity** | 100+ lines of config | 20-30 lines |
| **Maintenance** | Update every field change | Update goal/tools only |

### Why This Matters

**Traditional Approach** requires you to think like a form builder:
```php
// ❌ OLD WAY - Complex, rigid
'fields' => [
    'customer_name' => 'string|required|min:3',
    'customer_email' => 'email|required',
    'product_1' => 'string|required',
    'quantity_1' => 'integer|required',
    // ... 50 more field definitions
]
```

**Autonomous Approach** lets you think like a human:
```php
// ✅ NEW WAY - Simple, powerful
goal: 'Create a sales invoice',
tools: ['find_customer', 'find_product'],
outputSchema: ['customer_id', 'items']
// AI handles the rest!
```

## How It Works

1. **Define Goal**: Tell AI what you want to achieve (e.g., "Create a sales invoice")
2. **Provide Tools**: Give AI tools to search/create entities
3. **AI Converses**: AI naturally collects data from user
4. **Entity Resolution**: AI searches for existing entities or asks to create new ones
5. **Confirmation**: AI asks user to confirm before creating entities
6. **Complete**: AI produces structured output matching your schema

## Usage Examples

### Create Invoice Workflow

```php
use LaravelAIEngine\Facades\AutonomousCollector;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;

$config = new AutonomousCollectorConfig(
    goal: 'Create a sales invoice',
    tools: [
        'find_customer' => [
            'description' => 'Search customer by name or email. Returns not_found if no match.',
            'handler' => function($query) {
                $found = Customer::search($query)->get();
                if ($found->isEmpty()) {
                    return ['found' => false, 'message' => 'Ask user to confirm creation'];
                }
                return ['found' => true, 'customers' => $found->toArray()];
            },
        ],
        'create_customer' => [
            'description' => 'Create new customer. ONLY call after user confirms.',
            'handler' => fn($data) => Customer::create($data),
        ],
        'find_product' => [
            'description' => 'Search product by name or SKU',
            'handler' => fn($q) => Product::search($q)->get(),
        ],
    ],
    outputSchema: [
        'customer_id' => 'integer|required',
        'items' => [
            'type' => 'array',
            'items' => [
                'product_id' => 'integer|required',
                'quantity' => 'integer|required',
                'price' => 'number|required',
            ],
        ],
        'notes' => 'string|optional',
    ],
    onComplete: fn($data) => Invoice::create($data),
    systemPromptAddition: 'CONFIRMATION REQUIRED: Always ask user before creating new entities',
);

$response = AutonomousCollector::start('session-123', $config);
```

### Customer Onboarding

```php
$config = new AutonomousCollectorConfig(
    goal: 'Onboard new customer with company details and preferences',
    tools: [
        'check_email_exists' => [
            'description' => 'Check if email already exists in system',
            'handler' => fn($email) => User::where('email', $email)->exists(),
        ],
        'validate_company' => [
            'description' => 'Validate company registration number',
            'handler' => fn($reg) => CompanyValidator::validate($reg),
        ],
    ],
    outputSchema: [
        'name' => 'string|required',
        'email' => 'email|required',
        'company_name' => 'string|required',
        'company_registration' => 'string|required',
        'preferences' => [
            'type' => 'object',
            'properties' => [
                'newsletter' => 'boolean',
                'notifications' => 'boolean',
            ],
        ],
    ],
    onComplete: fn($data) => Customer::create($data),
);
```

### Project Setup Workflow

```php
$config = new AutonomousCollectorConfig(
    goal: 'Set up a new project with team members and milestones',
    tools: [
        'find_user' => [
            'description' => 'Search for team members by name or email',
            'handler' => fn($q) => User::search($q)->get(),
        ],
        'validate_dates' => [
            'description' => 'Validate milestone dates are in future',
            'handler' => fn($date) => Carbon::parse($date)->isFuture(),
        ],
    ],
    outputSchema: [
        'project_name' => 'string|required',
        'description' => 'string|required',
        'team_members' => [
            'type' => 'array',
            'items' => ['user_id' => 'integer', 'role' => 'string'],
        ],
        'milestones' => [
            'type' => 'array',
            'items' => ['name' => 'string', 'due_date' => 'date'],
        ],
    ],
    onComplete: fn($data) => Project::create($data),
);
```

### Support Ticket Creation

```php
$config = new AutonomousCollectorConfig(
    goal: 'Create support ticket with customer lookup and issue categorization',
    tools: [
        'find_customer' => [
            'description' => 'Find customer by email, phone, or name',
            'handler' => fn($q) => Customer::search($q)->get(),
        ],
        'suggest_category' => [
            'description' => 'AI suggests ticket category based on description',
            'handler' => fn($desc) => TicketCategorizer::suggest($desc),
        ],
    ],
    outputSchema: [
        'customer_id' => 'integer|required',
        'subject' => 'string|required',
        'description' => 'string|required',
        'category' => 'string|required',
        'priority' => 'enum:low,medium,high,urgent',
    ],
    onComplete: fn($data) => Ticket::create($data),
);
```

### Order Fulfillment Workflow

```php
$config = new AutonomousCollectorConfig(
    goal: 'Process order fulfillment with inventory check and shipping',
    tools: [
        'check_inventory' => [
            'description' => 'Check if product is in stock',
            'handler' => fn($productId, $qty) => Inventory::check($productId, $qty),
        ],
        'calculate_shipping' => [
            'description' => 'Calculate shipping cost based on address and weight',
            'handler' => fn($address, $weight) => ShippingCalculator::calculate($address, $weight),
        ],
        'find_order' => [
            'description' => 'Find existing order by order number',
            'handler' => fn($orderNum) => Order::where('order_number', $orderNum)->first(),
        ],
    ],
    outputSchema: [
        'order_id' => 'integer|required',
        'shipping_address' => 'object|required',
        'shipping_method' => 'string|required',
        'tracking_number' => 'string|optional',
    ],
    onComplete: fn($data) => Fulfillment::create($data),
);
```

### Smart Invoice with RAG Context

```php
$config = new AutonomousCollectorConfig(
    goal: 'Create invoice with intelligent customer and product lookup using RAG',
    tools: [
        'search_customer_with_rag' => [
            'description' => 'Search customer using RAG (emails, CRM, past invoices)',
            'handler' => function($query) {
                // RAG searches across multiple sources
                $ragResults = app(IntelligentRAGService::class)->search($query, [
                    'collections' => ['customers', 'emails', 'invoices'],
                ]);
                
                if ($ragResults->isEmpty()) {
                    return ['found' => false];
                }
                
                // Extract customer from RAG results
                return [
                    'found' => true,
                    'customer' => $ragResults->first()->metadata['customer'],
                    'context' => $ragResults->first()->content,
                ];
            },
        ],
        'suggest_products_from_history' => [
            'description' => 'Suggest products based on customer purchase history via RAG',
            'handler' => function($customerId) {
                $ragResults = app(IntelligentRAGService::class)->search(
                    "past orders for customer {$customerId}",
                    ['collections' => ['orders', 'invoices']]
                );
                
                // Extract frequently ordered products
                return $ragResults->pluck('metadata.products')->flatten()->unique();
            },
        ],
    ],
    outputSchema: [
        'customer_id' => 'integer|required',
        'items' => ['type' => 'array'],
        'notes' => 'string|optional',
    ],
    onComplete: fn($data) => Invoice::create($data),
    systemPromptAddition: 'Use RAG context to pre-fill customer data and suggest products',
);
```

## Orchestrator Integration with RAG

The Autonomous Collector integrates with **AgentOrchestrator** and **Intelligent RAG** for super-smart entity resolution:

### How It Works

```
User: "Create an invoice for Acme Corp"
    ↓
AgentOrchestrator analyzes message
    ↓
RAG Search retrieves context:
  - Past invoices with Acme Corp
  - Email history
  - CRM data
  - Customer preferences
    ↓
Autonomous Collector receives:
  - Customer ID (from RAG)
  - Past order patterns
  - Preferred products
  - Payment terms
    ↓
AI creates invoice with pre-filled data
```

### Benefits

**Without RAG Integration** ❌:
```
AI: "What's the customer name?"
User: "Acme Corp"
AI: "What's their email?"
User: "info@acme.com"
AI: "What's their address?"
User: "123 Main St..."
// 10+ questions...
```

**With RAG Integration** ✅:
```
AI: "I found Acme Corp in your system. They usually order 
     MacBooks and mice. Should I create an invoice with 
     their usual items?"
User: "Yes, but add 2 monitors"
AI: "Done! Invoice created with MacBooks, mice, and 2 monitors."
// 2 messages instead of 10+!
```

### Configuration

The Orchestrator automatically:
1. **Searches RAG** for relevant entities
2. **Finds collectors** across projects
3. **Pre-fills data** from RAG results
4. **Reduces questions** by 70-90%

```php
// Orchestrator handles this automatically
$orchestrator->process(
    message: 'Create invoice for Acme Corp',
    sessionId: 'user-123',
    userId: 1
);

// Behind the scenes:
// 1. RAG searches for "Acme Corp" → finds customer data
// 2. Finds "invoice_creator" collector
// 3. Starts collector with pre-filled customer_id
// 4. AI only asks for missing data (items, notes)
```

### Multi-Project Support

Orchestrator can discover collectors across multiple projects:

```php
// Project A: Invoice system
// Project B: E-commerce platform
// Project C: CRM system

// User: "Create an order for customer from last week's meeting"
// Orchestrator:
// 1. Searches Project C (CRM) for meeting notes → finds customer
// 2. Searches Project B (E-commerce) for products
// 3. Uses Project A (Invoice) collector to create order
// All automatic!
```

## Natural Language Parsing

The AI understands natural language:

**User**: "I need 2 MacBooks and 3 wireless mice"

**AI Understands**:
```json
{
  "items": [
    {"product": "MacBook", "quantity": 2},
    {"product": "wireless mouse", "quantity": 3}
  ]
}
```

## Entity Resolution Pattern

### Search Tool
```php
'find_customer' => [
    'description' => 'Search customer. Returns not_found status if no match.',
    'handler' => function($query) {
        $found = Customer::search($query)->get();
        if ($found->isEmpty()) {
            return [
                'found' => false, 
                'message' => 'No customer found. Ask user if they want to create new customer.'
            ];
        }
        return ['found' => true, 'customers' => $found->toArray()];
    },
],
```

### Create Tool (with confirmation)
```php
'create_customer' => [
    'description' => 'Create customer. ONLY call AFTER user explicitly confirms creation.',
    'handler' => function($data) {
        return Customer::create($data);
    },
],
```

## API Endpoints

```php
// Start collection
POST /api/v1/autonomous-collector/start
{
    "session_id": "user-123",
    "config": { ... }
}

// Send message
POST /api/v1/autonomous-collector/message
{
    "session_id": "user-123",
    "message": "I need an invoice for Acme Corp"
}

// Get status
GET /api/v1/autonomous-collector/status/{session_id}

// Complete and get data
POST /api/v1/autonomous-collector/complete/{session_id}
```

## Generated Files

When you create an autonomous data collector, you get:

- **Controller**: API endpoints for the workflow
- **Service**: Business logic and AI integration
- **DTOs**: Type-safe data structures
- **Config**: Workflow configuration
- **Tests**: Comprehensive test suite

## Best Practices

1. **Clear Goal**: Define a specific, achievable goal
2. **Useful Tools**: Provide tools for entity search and creation
3. **Confirmation Required**: Always ask user before creating entities
4. **Output Schema**: Define clear expected output structure
5. **Error Handling**: Handle cases where entities aren't found
6. **Natural Language**: Let AI handle the conversation naturally

## Migrating from Traditional DataCollector

If you have existing traditional DataCollector code, here's how to migrate:

### Before (Traditional) ❌
```php
$config = new DataCollectorConfig(
    name: 'invoice_creator',
    title: 'Create Invoice',
    fields: [
        'customer_name' => 'Customer name | required | min:3',
        'customer_email' => 'Email | required | email',
        'product_1' => 'Product | required',
        'quantity_1' => 'Quantity | required | integer',
        'product_2' => 'Product | optional',
        'quantity_2' => 'Quantity | optional | integer',
        // ... many more fields
    ],
    onComplete: fn($data) => Invoice::create($data),
);
```

### After (Autonomous) ✅
```php
$config = new AutonomousCollectorConfig(
    goal: 'Create a sales invoice',
    tools: [
        'find_customer' => fn($q) => Customer::search($q)->get(),
        'find_product' => fn($q) => Product::search($q)->get(),
    ],
    outputSchema: [
        'customer_id' => 'integer|required',
        'items' => ['type' => 'array', 'items' => [
            'product_id' => 'integer',
            'quantity' => 'integer',
        ]],
    ],
    onComplete: fn($data) => Invoice::create($data),
);
```

**Result**: 70% less code, infinitely more flexible!

## Example Prompts

### Basic Autonomous Workflows
- "Create an autonomous invoice workflow with customer and product lookup"
- "Build a goal-based onboarding that collects user and company details"
- "Generate an autonomous project setup workflow"
- "Create a conversational support ticket system with auto-categorization"
- "Build an order fulfillment workflow with inventory checks"
- "Create a survey system that adapts based on user responses"

### RAG-Enhanced Workflows
- "Create an invoice workflow that uses RAG to find customer data from emails and past orders"
- "Build an onboarding workflow that pre-fills data from CRM using RAG search"
- "Generate a support ticket system that searches past tickets via RAG for similar issues"
- "Create an order workflow that suggests products based on customer history via RAG"
- "Build a project setup that finds team members from past projects using RAG"

### Multi-Project Workflows
- "Create a workflow that searches customers across all projects using Orchestrator"
- "Build an invoice system that pulls data from CRM, e-commerce, and email projects"
- "Generate a workflow that discovers and uses collectors from multiple projects"
