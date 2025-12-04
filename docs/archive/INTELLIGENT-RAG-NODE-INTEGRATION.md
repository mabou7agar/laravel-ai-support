# 🧠 Intelligent RAG + Master-Node Integration

## 📋 Overview

This document explains how **Intelligent RAG** (Retrieval Augmented Generation) integrates with the **Master-Node Architecture** to create a powerful distributed AI system.

---

## 🎯 The Power of Integration

### **Without Master-Node:**
```
User Query → Intelligent RAG → Search Local DB → AI Response
```

### **With Master-Node:**
```
User Query → Intelligent RAG → Search ALL Nodes → Aggregate Results → AI Response
```

**Result:** AI can now access data from ALL your applications, not just one! 🚀

---

## 🏗️ Architecture Integration

```
┌─────────────────────────────────────────────────────────────┐
│                    MASTER NODE                               │
│                                                              │
│  ┌────────────────────────────────────────────────────┐    │
│  │         Intelligent RAG System                      │    │
│  │  ┌──────────────────────────────────────────┐     │    │
│  │  │  1. Analyze Query                         │     │    │
│  │  │     - Detect if search needed             │     │    │
│  │  │     - Determine which collections         │     │    │
│  │  │     - Decide which NODES to search        │ ◄───┼────┼─ NEW!
│  │  └──────────────────────────────────────────┘     │    │
│  │                                                     │    │
│  │  ┌──────────────────────────────────────────┐     │    │
│  │  │  2. Federated Search                      │     │    │
│  │  │     - Search local node                   │     │    │
│  │  │     - Search remote nodes (parallel)      │ ◄───┼────┼─ NEW!
│  │  │     - Aggregate results                   │     │    │
│  │  └──────────────────────────────────────────┘     │    │
│  │                                                     │    │
│  │  ┌──────────────────────────────────────────┐     │    │
│  │  │  3. Context Building                      │     │    │
│  │  │     - Rank all results                    │     │    │
│  │  │     - Build unified context               │     │    │
│  │  │     - Include source attribution          │ ◄───┼────┼─ Enhanced!
│  │  └──────────────────────────────────────────┘     │    │
│  │                                                     │    │
│  │  ┌──────────────────────────────────────────┐     │    │
│  │  │  4. AI Response                           │     │    │
│  │  │     - Generate with full context          │     │    │
│  │  │     - Cite sources from all nodes         │     │    │
│  │  └──────────────────────────────────────────┘     │    │
│  └────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ Searches
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│  E-COMMERCE   │   │     BLOG      │   │      CRM      │
│               │   │               │   │               │
│  Products     │   │  Articles     │   │  Customers    │
│  Orders       │   │  Tutorials    │   │  Tickets      │
│  Reviews      │   │  Docs         │   │  Contacts     │
└───────────────┘   └───────────────┘   └───────────────┘
```

---

## 🔄 Integration Flow

### **Step 1: Query Analysis (Enhanced)**

The Intelligent RAG now decides **which nodes** to search:

```php
// Before (single node)
$ragDecision = $intelligentRAG->analyzeQuery($message);
// Returns: ['should_search' => true, 'collections' => ['Product', 'Order']]

// After (multi-node)
$ragDecision = $intelligentRAG->analyzeQuery($message, $availableNodes);
// Returns: [
//     'should_search' => true,
//     'collections' => ['Product', 'Order'],
//     'nodes' => ['ecommerce', 'blog'],  // ← NEW!
//     'search_strategy' => 'parallel'     // ← NEW!
// ]
```

### **Step 2: Federated Search Execution**

```php
// Intelligent RAG triggers federated search
if ($ragDecision['should_search']) {
    $context = $federatedSearch->search(
        query: $message,
        nodeIds: $ragDecision['nodes'],  // ← Search specific nodes
        limit: 5,
        options: [
            'collections' => $ragDecision['collections'],
            'strategy' => $ragDecision['search_strategy'],
        ]
    );
}
```

### **Step 3: Context Building (Enhanced)**

Results now include **source node attribution**:

```php
// Context with node sources
$context = [
    'results' => [
        [
            'content' => 'Laravel is a PHP framework...',
            'score' => 0.95,
            'source_node' => 'blog',           // ← NEW!
            'source_node_name' => 'Blog',      // ← NEW!
            'collection' => 'Article',
        ],
        [
            'content' => 'Product: Laravel Book - $49.99',
            'score' => 0.87,
            'source_node' => 'ecommerce',      // ← NEW!
            'source_node_name' => 'E-commerce',// ← NEW!
            'collection' => 'Product',
        ],
    ],
    'nodes_searched' => 2,                      // ← NEW!
];
```

### **Step 4: AI Response with Multi-Node Citations**

```php
// AI response includes sources from all nodes
$response = [
    'message' => 'Based on our blog and store, Laravel is a PHP framework. 
                  We have a Laravel book available for $49.99.',
    'sources' => [
        [
            'title' => 'What is Laravel?',
            'node' => 'Blog',                   // ← NEW!
            'url' => 'https://blog.example.com/what-is-laravel',
        ],
        [
            'title' => 'Laravel Book',
            'node' => 'E-commerce',             // ← NEW!
            'url' => 'https://shop.example.com/products/laravel-book',
        ],
    ],
];
```

---

## 💻 Implementation

### **Enhanced Intelligent RAG Service**

```php
// src/Services/RAG/IntelligentRAGService.php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\FederatedSearchService;

class IntelligentRAGService
{
    public function __construct(
        protected AIEngineManager $aiEngine,
        protected NodeRegistryService $nodeRegistry,      // ← NEW!
        protected FederatedSearchService $federatedSearch, // ← NEW!
    ) {}
    
    /**
     * Analyze query and determine search strategy (ENHANCED)
     */
    public function analyzeQuery(
        string $message,
        ?array $availableNodes = null
    ): array {
        // Get available nodes
        $nodes = $availableNodes ?? $this->nodeRegistry->getActiveNodes();
        
        // Ask AI to analyze query
        $analysis = $this->aiEngine->chat([
            'role' => 'system',
            'content' => $this->getAnalysisPrompt($nodes),
        ], [
            'role' => 'user',
            'content' => $message,
        ]);
        
        $decision = json_decode($analysis, true);
        
        return [
            'should_search' => $decision['should_search'] ?? false,
            'collections' => $decision['collections'] ?? [],
            'nodes' => $decision['nodes'] ?? [],              // ← NEW!
            'search_strategy' => $decision['strategy'] ?? 'parallel', // ← NEW!
            'reasoning' => $decision['reasoning'] ?? '',
        ];
    }
    
    /**
     * Get analysis prompt (ENHANCED)
     */
    protected function getAnalysisPrompt(Collection $nodes): string
    {
        $nodeList = $nodes->map(fn($node) => [
            'slug' => $node->slug,
            'name' => $node->name,
            'type' => $node->metadata['type'] ?? 'general',
            'capabilities' => $node->capabilities,
        ])->toJson();
        
        return <<<PROMPT
You are a search strategy analyzer. Analyze the user's query and determine:

1. Should we search for additional context?
2. Which collections to search? (Product, Order, Article, Customer, etc.)
3. Which nodes to search?

Available nodes:
{$nodeList}

Respond in JSON format:
{
    "should_search": true/false,
    "collections": ["Collection1", "Collection2"],
    "nodes": ["node-slug-1", "node-slug-2"],
    "strategy": "parallel" or "sequential",
    "reasoning": "Why this strategy"
}

Examples:
- "What Laravel books do you have?" → Search ecommerce node, Product collection
- "Show me Laravel tutorials" → Search blog node, Article collection  
- "Find Laravel resources" → Search BOTH blog and ecommerce nodes
- "What's the weather?" → No search needed
PROMPT;
    }
    
    /**
     * Execute intelligent RAG with federated search (ENHANCED)
     */
    public function execute(
        string $message,
        ?array $availableNodes = null,
        array $options = []
    ): array {
        // Step 1: Analyze query
        $decision = $this->analyzeQuery($message, $availableNodes);
        
        if (!$decision['should_search']) {
            return [
                'context' => null,
                'searched' => false,
                'reasoning' => $decision['reasoning'],
            ];
        }
        
        // Step 2: Execute federated search
        $searchResults = $this->federatedSearch->search(
            query: $message,
            nodeIds: $this->resolveNodeIds($decision['nodes']),
            limit: $options['limit'] ?? 5,
            options: [
                'collections' => $decision['collections'],
            ]
        );
        
        // Step 3: Build context with node attribution
        $context = $this->buildContext($searchResults);
        
        return [
            'context' => $context,
            'searched' => true,
            'nodes_searched' => $searchResults['nodes_searched'],
            'total_results' => $searchResults['total_results'],
            'reasoning' => $decision['reasoning'],
        ];
    }
    
    /**
     * Build context with node attribution (NEW)
     */
    protected function buildContext(array $searchResults): string
    {
        $context = "Relevant information from our systems:\n\n";
        
        foreach ($searchResults['results'] as $index => $result) {
            $nodeInfo = "[{$result['source_node_name']}]";
            $context .= ($index + 1) . ". {$nodeInfo} {$result['content']}\n";
        }
        
        return $context;
    }
    
    /**
     * Resolve node slugs to IDs
     */
    protected function resolveNodeIds(array $nodeSlugs): ?array
    {
        if (empty($nodeSlugs)) {
            return null; // Search all nodes
        }
        
        return $this->nodeRegistry->getActiveNodes()
            ->whereIn('slug', $nodeSlugs)
            ->pluck('id')
            ->toArray();
    }
}
```

---

## 🎯 Usage Examples

### **Example 1: Simple Query (Single Node)**

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

$rag = app(IntelligentRAGService::class);

// User asks about products
$result = $rag->execute("What Laravel books do you have?");

// AI automatically:
// 1. Detects it's a product query
// 2. Searches only the e-commerce node
// 3. Returns product information
```

**Response:**
```
Based on our e-commerce store, we have:
1. "Laravel Up & Running" - $49.99
2. "Laravel: The Definitive Guide" - $59.99

[Sources: E-commerce Store]
```

---

### **Example 2: Multi-Node Query**

```php
// User asks a broad question
$result = $rag->execute("Tell me everything about Laravel");

// AI automatically:
// 1. Detects it needs multiple sources
// 2. Searches blog node (articles) + ecommerce node (products)
// 3. Aggregates results from both
```

**Response:**
```
Laravel is a PHP framework for web development [Blog]. 

We have several resources:
- Tutorial: "Getting Started with Laravel" [Blog]
- Book: "Laravel Up & Running" - $49.99 [E-commerce]
- Article: "Laravel Best Practices" [Blog]

[Sources: Blog, E-commerce Store]
```

---

### **Example 3: Specific Node Query**

```php
// User specifies which system
$result = $rag->execute(
    "Show me customer support tickets about Laravel",
    availableNodes: ['crm'] // Only search CRM
);

// AI automatically:
// 1. Searches only CRM node
// 2. Finds customer tickets
// 3. Returns support data
```

**Response:**
```
Found 3 support tickets about Laravel:
1. Ticket #123: "Laravel installation issue" [CRM]
2. Ticket #456: "Laravel upgrade help needed" [CRM]
3. Ticket #789: "Laravel performance question" [CRM]

[Sources: CRM System]
```

---

### **Example 4: Cross-Node Intelligence**

```php
// User asks a complex question
$result = $rag->execute(
    "Which customers bought Laravel books and also read our tutorials?"
);

// AI automatically:
// 1. Searches e-commerce (purchases)
// 2. Searches blog (article views)
// 3. Searches CRM (customer data)
// 4. Correlates data across all nodes
```

**Response:**
```
Found 5 customers who both purchased Laravel books and read tutorials:

1. John Doe [CRM]
   - Purchased: "Laravel Up & Running" [E-commerce]
   - Read: "Getting Started with Laravel" [Blog]

2. Jane Smith [CRM]
   - Purchased: "Laravel: The Definitive Guide" [E-commerce]
   - Read: "Laravel Best Practices" [Blog]

[Sources: CRM, E-commerce, Blog]
```

---

## 🔧 Configuration

### **Enable Multi-Node RAG**

```php
// config/ai-engine.php

'intelligent_rag' => [
    'enabled' => true,
    
    // Enable federated search across nodes
    'federated_search' => env('AI_ENGINE_FEDERATED_RAG', true),
    
    // Which nodes to search by default (null = all)
    'default_nodes' => env('AI_ENGINE_RAG_NODES', null),
    
    // Max nodes to search in parallel
    'max_parallel_nodes' => env('AI_ENGINE_RAG_MAX_NODES', 5),
    
    // Cache federated results
    'cache_federated_results' => true,
    'cache_ttl' => 900, // 15 minutes
],
```

---

## 🎨 Chat Service Integration

### **Enhanced Chat Service**

```php
// src/Services/ChatService.php

public function processMessage(
    string $message,
    string $sessionId,
    bool $useIntelligentRAG = false,
    ?array $ragNodes = null,  // ← NEW! Specify which nodes
    array $ragCollections = []
): array {
    if ($useIntelligentRAG) {
        // Execute intelligent RAG with node support
        $ragResult = $this->intelligentRAG->execute(
            message: $message,
            availableNodes: $ragNodes,
            options: [
                'collections' => $ragCollections,
            ]
        );
        
        if ($ragResult['searched']) {
            // Add context to conversation
            $messages[] = [
                'role' => 'system',
                'content' => "Context from {$ragResult['nodes_searched']} systems:\n\n" 
                           . $ragResult['context'],
            ];
            
            Log::channel('ai-engine')->info('Federated RAG executed', [
                'nodes_searched' => $ragResult['nodes_searched'],
                'total_results' => $ragResult['total_results'],
                'reasoning' => $ragResult['reasoning'],
            ]);
        }
    }
    
    // Continue with AI response...
}
```

---

## 📊 Real-World Scenarios

### **Scenario 1: E-commerce + Blog Integration**

**User:** "I want to learn Laravel and buy a book"

**System:**
1. Searches blog node → Finds tutorials
2. Searches e-commerce node → Finds books
3. Combines results

**Response:**
```
Great! Here's what we have:

Learning Resources [Blog]:
- "Getting Started with Laravel" (free tutorial)
- "Laravel Best Practices" (free article)

Books Available [E-commerce]:
- "Laravel Up & Running" - $49.99
- "Laravel: The Definitive Guide" - $59.99

Would you like to start with the free tutorial or purchase a book?
```

---

### **Scenario 2: CRM + E-commerce Integration**

**User:** "Show me VIP customers who haven't purchased in 30 days"

**System:**
1. Searches CRM node → Finds VIP customers
2. Searches e-commerce node → Finds recent orders
3. Correlates data

**Response:**
```
Found 12 VIP customers with no purchases in 30+ days:

1. John Doe [CRM]
   - Last purchase: "Laravel Book" 45 days ago [E-commerce]
   - VIP since: 2023
   
2. Jane Smith [CRM]
   - Last purchase: "PHP Course" 60 days ago [E-commerce]
   - VIP since: 2022

Suggested action: Send re-engagement email campaign
```

---

### **Scenario 3: Full System Search**

**User:** "Find everything related to customer #12345"

**System:**
1. Searches CRM → Customer profile
2. Searches e-commerce → Order history
3. Searches blog → Article interactions
4. Searches support → Tickets

**Response:**
```
Complete profile for Customer #12345:

Profile [CRM]:
- Name: John Doe
- Email: john@example.com
- VIP Status: Gold

Purchase History [E-commerce]:
- 5 orders, $500 total
- Last order: "Laravel Book" (2 weeks ago)

Content Engagement [Blog]:
- Read 15 articles
- Favorite topic: Laravel

Support History [Support]:
- 2 tickets (both resolved)
- Satisfaction: 5/5 stars
```

---

## 🚀 Benefits

### **1. Unified Intelligence**
- AI has access to ALL your data
- No more siloed information
- Complete context for better answers

### **2. Automatic Node Selection**
- AI decides which nodes to search
- No manual configuration needed
- Intelligent routing

### **3. Performance**
- Parallel search across nodes
- Cached results
- Fast response times

### **4. Source Attribution**
- Know where data comes from
- Transparency for users
- Easy to verify information

### **5. Scalability**
- Add new nodes anytime
- No code changes needed
- Automatic discovery

---

## 📈 Performance Considerations

### **Caching Strategy**

```php
// Cache federated search results
Cache::remember(
    "federated_rag:{$queryHash}",
    900, // 15 minutes
    fn() => $federatedSearch->search(...)
);
```

### **Parallel Execution**

```php
// Search all nodes in parallel (not sequential)
$results = Http::pool(function ($pool) use ($nodes) {
    foreach ($nodes as $node) {
        yield $pool->post($node->getApiUrl('search'), [...]);
    }
});
```

### **Smart Node Selection**

```php
// Only search relevant nodes (not all)
if (str_contains($query, 'product')) {
    $nodes = ['ecommerce']; // Only search e-commerce
} else {
    $nodes = null; // Search all nodes
}
```

---

## 🎯 Next Steps

### **To Enable Federated RAG:**

1. ✅ Implement master-node architecture (Tasks 1-16)
2. ✅ Update IntelligentRAGService (code above)
3. ✅ Update ChatService (code above)
4. ✅ Configure nodes in config
5. ✅ Test with real queries

---

## 📝 Summary

**Intelligent RAG + Master-Node = 🚀**

- **Before:** Search one database
- **After:** Search ALL your applications
- **Result:** AI with complete knowledge of your entire ecosystem

**The AI can now:**
- Search products, articles, customers, tickets, etc.
- Correlate data across systems
- Provide comprehensive answers
- Cite sources from all nodes

**All automatically, with zero manual configuration!** 🎉

---

**Ready to implement?** Start with the master-node tasks, then enhance Intelligent RAG! 🚀
