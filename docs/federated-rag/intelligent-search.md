# Intelligent Federated Search System

## Overview

The Intelligent Federated Search System enables AI-powered search across multiple nodes in a distributed architecture. The AI automatically discovers collections, understands their content through RAG descriptions, and intelligently selects which collections to search based on the user's query.

## Key Features

- 🌐 **Federated Collection Discovery** - Automatically discovers collections from all connected nodes
- 🤖 **Intelligent Collection Selection** - AI analyzes queries and selects relevant collections
- 📝 **RAG Descriptions** - Collections describe their content for better AI understanding
- 🔒 **Flexible Access Control** - Simple property-based or method-based filter control
- ⚡ **Auto-Discovery Mode** - Works without explicitly specifying collections
- 🔄 **Node Communication** - Secure authenticated API calls between nodes
- 📊 **Auto-Generated Descriptions** - Collections without descriptions get defaults with warnings

## Quick Start

### 1. Configure Your Model

Add RAG description and filter control to your model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;

class Post extends Model
{
    use Vectorizable;
    
    /**
     * Skip user filtering for public blog posts
     * Set to false for user-specific content
     */
    public static $skipUserFilter = true;
    
    /**
     * Describe what this collection contains for AI selection
     */
    public function getRAGDescription(): string
    {
        return 'Blog posts and articles about Laravel, PHP, web development, AI, vector search, and programming tutorials. Contains technical guides, how-to articles, and educational content.';
    }
    
    /**
     * Optional: Custom display name
     */
    public static function getRAGDisplayName(): string
    {
        return 'Blog Posts & Articles';
    }
    
    /**
     * Define what content to index
     */
    public function getVectorContent(): string
    {
        return $this->title . "\n\n" . $this->content;
    }
}
```

### 2. Index Your Content

```bash
# Index a specific model
php artisan ai-engine:vector-index "App\Models\Post"

# Check indexing status
php artisan ai-engine:vector-status "App\Models\Post"
```

### 3. Use Intelligent Search

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

$rag = app(IntelligentRAGService::class);

// Auto-discovery mode - AI selects collections automatically
$response = $rag->processMessage(
    message: 'Find Laravel articles',
    sessionId: 'user_session_123',
    availableCollections: [],  // Empty = auto-discovery
    conversationHistory: [],
    options: [],
    userId: auth()->id()
);

echo $response->getContent();
```

## Configuration Options

### Filter Control

#### Simple Property-Based (Recommended)

```php
class Post extends Model
{
    // One line - skip user filtering for public content
    public static $skipUserFilter = true;
}
```

#### Advanced Method-Based

```php
class Document extends Model
{
    public static function getVectorSearchFilters($userId, array $baseFilters): array
    {
        // Complex logic based on user role, document type, etc.
        if ($userId && User::find($userId)->isAdmin()) {
            return ['skip_user_filter' => true];
        }
        
        return [
            'user_id' => $userId,
            'status' => 'published',
        ];
    }
}
```

### RAG Descriptions

#### Best Practices

✅ **Good Description:**
```php
public function getRAGDescription(): string
{
    return 'Blog posts and articles about Laravel, PHP, web development, AI, vector search, and programming tutorials. Contains technical guides, how-to articles, and educational content.';
}
```

❌ **Poor Description:**
```php
public function getRAGDescription(): string
{
    return 'Posts';  // Too vague - AI won't select it correctly
}
```

#### What to Include

1. **Content Type** - "Blog posts", "Documents", "Email messages"
2. **Topics** - "Laravel, PHP, web development"
3. **Purpose** - "Technical guides, tutorials, how-to articles"
4. **Keywords** - Terms users might search for

#### Auto-Generated Descriptions

If you don't provide a description, the system auto-generates one:

```
"Search through Post collection"
```

A warning is logged:
```
RAG collection missing description - using auto-generated description
Recommendation: Add getRAGDescription() method to App\Models\Post for better AI selection
```

## Federated Search Architecture

### Node Setup

#### 1. Configure Master Node

```php
// config/ai-engine.php
return [
    'nodes' => [
        'enabled' => true,
        'auth' => [
            'secret_key' => env('AI_ENGINE_NODE_SECRET'),
        ],
    ],
];
```

#### 2. Register Remote Nodes

```bash
php artisan ai-engine:node-add \
    --name="Content Node" \
    --slug="content-node" \
    --url="https://content.example.com" \
    --secret="shared-secret-key"
```

#### 3. Test Connection

```bash
# Ping all nodes
php artisan ai-engine:node-ping

# Discover collections
php artisan ai-engine:discover-collections
```

### How It Works

```
┌─────────────────────────────────────────────────────────────┐
│                      User Query                              │
│              "Find Laravel articles"                         │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│              1. Collection Discovery                         │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ Master Node  │  │ Content Node │  │  Blog Node   │      │
│  │   - User     │  │   - Post     │  │  - Article   │      │
│  │   - Email    │  │   - Document │  │  - Tutorial  │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│           2. AI Analyzes Query + Descriptions                │
│                                                              │
│  Query: "Find Laravel articles"                             │
│  Available Collections:                                      │
│  - Post: "Blog posts about Laravel, PHP..."     ✅ MATCH    │
│  - Email: "Email messages from mailboxes..."    ❌ NO MATCH │
│  - Document: "Legal documents and contracts..." ❌ NO MATCH │
│                                                              │
│  AI Decision: Search "Post" collection                      │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│              3. Federated Search Execution                   │
│                                                              │
│  Master Node → Content Node API                             │
│  POST /api/ai-engine/search                                 │
│  {                                                           │
│    "query": "Laravel articles",                             │
│    "collections": ["App\\Models\\Post"],                    │
│    "limit": 5,                                              │
│    "options": {"user_id": 1}                                │
│  }                                                           │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│              4. Results Aggregation                          │
│                                                              │
│  Content Node Returns:                                       │
│  - "Getting Started with Laravel"                           │
│  - "Laravel Routing Guide"                                  │
│  - "Building APIs with Laravel"                             │
│                                                              │
│  Master Node Formats Response                               │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    5. AI Response                            │
│                                                              │
│  "Here are the Laravel articles I found:                    │
│   1. Getting Started with Laravel                           │
│   2. Laravel Routing Guide                                  │
│   3. Building APIs with Laravel"                            │
└─────────────────────────────────────────────────────────────┘
```

## Testing

### Test Command

```bash
# Test with auto-discovery
php artisan ai-engine:test-intelligent-search \
    --query="Find Laravel articles"

# Test with specific collections
php artisan ai-engine:test-intelligent-search \
    --query="Find Laravel articles" \
    --collections="App\Models\Post"

# Test without federated search
php artisan ai-engine:test-intelligent-search \
    --query="Find Laravel articles" \
    --skip-federated
```

### Manual Testing

```php
// Test collection discovery
$discovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
$collections = $discovery->discoverWithDescriptions(useCache: false);

foreach ($collections as $collection) {
    echo $collection['display_name'] . ': ' . $collection['description'] . PHP_EOL;
}

// Test search
$service = app(\LaravelAIEngine\Services\Vector\VectorSearchService::class);
$results = $service->search(
    query: 'Laravel',
    modelClass: 'App\Models\Post',
    limit: 5,
    userId: 1
);

echo "Found: " . $results->count() . " results" . PHP_EOL;
```

## Advanced Usage

### Custom Collection Selection

```php
// Specify collections explicitly
$response = $rag->processMessage(
    message: 'Find Laravel articles',
    sessionId: 'session_123',
    availableCollections: [
        'App\Models\Post',
        'App\Models\Document',
    ],
    conversationHistory: [],
    options: [],
    userId: auth()->id()
);
```

### Conversation History

```php
$history = [
    ['role' => 'user', 'content' => 'Show me Laravel tutorials'],
    ['role' => 'assistant', 'content' => 'Here are some tutorials...'],
];

$response = $rag->processMessage(
    message: 'Tell me more about the first one',
    sessionId: 'session_123',
    availableCollections: [],
    conversationHistory: $history,
    options: [],
    userId: auth()->id()
);
```

### Custom Options

```php
$response = $rag->processMessage(
    message: 'Find Laravel articles',
    sessionId: 'session_123',
    availableCollections: [],
    conversationHistory: [],
    options: [
        'max_context' => 10,           // Max results per collection
        'min_score' => 0.5,            // Minimum relevance score
        'intelligent' => true,         // Use AI analysis
        'engine' => 'openai',          // AI engine
        'model' => 'gpt-4o',           // AI model
    ],
    userId: auth()->id()
);
```

## Troubleshooting

### Collections Not Being Discovered

**Problem:** Remote node collections not showing up

**Solutions:**
1. Check node is active: `php artisan ai-engine:node-ping`
2. Clear cache: `php artisan cache:clear`
3. Verify API endpoint: `curl https://remote-node.com/api/ai-engine/collections`
4. Check authentication secret matches

### AI Not Selecting Correct Collection

**Problem:** AI doesn't search the right collection

**Solutions:**
1. Improve RAG description with more keywords
2. Check description includes query terms
3. Use explicit collection specification for testing
4. Review AI analysis logs in `storage/logs/ai-engine.log`

### No Results Returned

**Problem:** Search returns 0 results

**Solutions:**
1. Check content is indexed: `php artisan ai-engine:vector-status "App\Models\Post"`
2. Verify `$skipUserFilter` is set correctly
3. Test with `skip_user_filter` manually:
   ```php
   $results = $service->search(
       query: 'Laravel',
       modelClass: 'App\Models\Post',
       limit: 5,
       userId: null,
       filters: ['skip_user_filter' => true]
   );
   ```
4. Check Qdrant configuration in `.env`

### Filter Issues

**Problem:** User filtering not working as expected

**Test:**
```php
// Test with filtering enabled
Post::$skipUserFilter = false;
$results = $service->search(query: 'Laravel', modelClass: 'App\Models\Post', limit: 5, userId: 1);
echo "With filtering: " . $results->count() . PHP_EOL;

// Test with filtering disabled
Post::$skipUserFilter = true;
$results = $service->search(query: 'Laravel', modelClass: 'App\Models\Post', limit: 5, userId: 1);
echo "Without filtering: " . $results->count() . PHP_EOL;
```

## Performance Optimization

### Caching

Collections are cached for 5 minutes by default:

```php
// config/ai-engine.php
'cache' => [
    'collections_ttl' => 300, // 5 minutes
],
```

### Search Optimization

```php
// Limit search queries for faster response
$response = $rag->processMessage(
    message: 'Find Laravel articles',
    sessionId: 'session_123',
    availableCollections: [],
    conversationHistory: [],
    options: [
        'max_context' => 3,  // Fewer results = faster
    ],
    userId: auth()->id()
);
```

## Security

### Node Authentication

All node-to-node communication uses JWT authentication:

```php
// Automatic authentication
$client = NodeHttpClient::makeAuthenticated($node);
$response = $client->post($node->getApiUrl('search'), $data);
```

### Access Control

User-based filtering ensures users only see their own data:

```php
// User-specific content
class Email extends Model
{
    public static $skipUserFilter = false;  // Enforce user filtering
}

// Public content
class Post extends Model
{
    public static $skipUserFilter = true;   // Skip user filtering
}
```

## API Reference

### IntelligentRAGService

```php
public function processMessage(
    string $message,              // User's query
    string $sessionId,            // Session identifier
    array $availableCollections,  // Collections to search (empty = auto-discovery)
    array $conversationHistory,   // Previous messages
    array $options,               // Additional options
    $userId                       // User ID for filtering
): AIResponse
```

### RAGCollectionDiscovery

```php
// Discover collections with descriptions
public function discoverWithDescriptions(bool $useCache = true): array

// Discover local collections only
public function discover(bool $useCache = true, bool $includeFederated = true): array
```

### VectorSearchService

```php
public function search(
    string $query,          // Search query
    string $modelClass,     // Model class to search
    int $limit = 10,        // Max results
    $userId = null,         // User ID for filtering
    array $filters = []     // Additional filters
): Collection
```

## Best Practices

1. ✅ **Always provide RAG descriptions** for better AI selection
2. ✅ **Use specific, keyword-rich descriptions** that match user queries
3. ✅ **Set `$skipUserFilter` appropriately** for your content type
4. ✅ **Test both with and without filtering** to ensure correct behavior
5. ✅ **Monitor logs** for auto-generated description warnings
6. ✅ **Index content regularly** to keep search results fresh
7. ✅ **Use conversation history** for context-aware responses
8. ✅ **Cache collections** to reduce discovery overhead

## Examples

See the [examples directory](./examples/) for complete working examples:

- [Basic Search](./examples/basic-search.php)
- [Federated Search](./examples/federated-search.php)
- [Custom Filters](./examples/custom-filters.php)
- [Conversation Context](./examples/conversation-context.php)

## Support

- 📖 [Full Documentation](./README.md)
- 🐛 [Issue Tracker](https://github.com/your-repo/issues)
- 💬 [Discussions](https://github.com/your-repo/discussions)
