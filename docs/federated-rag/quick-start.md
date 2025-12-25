# Quick Start: Intelligent Federated Search

Get started with intelligent federated search in 5 minutes!

## Step 1: Configure Your Model (2 minutes)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;

class Post extends Model
{
    use Vectorizable;
    
    // ✅ Step 1a: Add filter control (one line!)
    public static $skipUserFilter = true;  // For public content
    
    // ✅ Step 1b: Describe your collection
    public function getRAGDescription(): string
    {
        return 'Blog posts and articles about Laravel, PHP, web development, AI, vector search, and programming tutorials.';
    }
    
    // ✅ Step 1c: Define what to index
    public function getVectorContent(): string
    {
        return $this->title . "\n\n" . $this->content;
    }
}
```

## Step 2: Index Your Content (1 minute)

```bash
# Index your model
php artisan ai-engine:vector-index "App\Models\Post"

# Verify it worked
php artisan ai-engine:vector-status "App\Models\Post"
```

Expected output:
```
App\Models\Post
────────────────────────────────────────────────────────────
| Collection    | app_models_post |
| Total Records | 13              |
| Indexed       | 13              |
| Status        | Complete        |
────────────────────────────────────────────────────────────
```

## Step 3: Use Intelligent Search (1 minute)

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

$rag = app(IntelligentRAGService::class);

// AI automatically discovers and selects the right collections!
$response = $rag->processMessage(
    message: 'Find Laravel articles',
    sessionId: 'user_' . auth()->id(),
    availableCollections: [],  // Empty = auto-discovery
    conversationHistory: [],
    options: [],
    userId: auth()->id()
);

echo $response->getContent();
```

That's it! The AI will:
1. 🔍 Discover all available collections
2. 📖 Read their RAG descriptions
3. 🤖 Analyze your query
4. ✅ Select the Post collection (matches "articles")
5. 🔎 Search and return results

## What Just Happened?

```
User Query: "Find Laravel articles"
         ↓
    AI Analysis
         ↓
  Collection Discovery
  ├─ Post: "Blog posts about Laravel..." ✅ MATCH
  ├─ Email: "Email messages..." ❌
  └─ User: "User accounts..." ❌
         ↓
   Search "Post" Collection
         ↓
    Return Results
```

## Testing Different Scenarios

### Test 1: Public Content (skipUserFilter = true)

```php
Post::$skipUserFilter = true;

$results = Post::vectorSearch('Laravel', limit: 5);
// Returns: 5 posts (including those with user_id = NULL)
```

### Test 2: User-Specific Content (skipUserFilter = false)

```php
Post::$skipUserFilter = false;

$service = app(\LaravelAIEngine\Services\Vector\VectorSearchService::class);
$results = $service->search(
    query: 'Laravel',
    modelClass: 'App\Models\Post',
    limit: 5,
    userId: 1
);
// Returns: Only posts where user_id = 1
```

### Test 3: Full Intelligent Search

```bash
php artisan ai-engine:test-intelligent-search \
    --query="Find Laravel tutorials"
```

## Adding More Collections

Want to add another collection? Just repeat Step 1!

```php
class Document extends Model
{
    use Vectorizable;
    
    public static $skipUserFilter = false;  // User-specific
    
    public function getRAGDescription(): string
    {
        return 'Legal documents, contracts, and agreements. Contains PDFs, Word docs, and signed contracts.';
    }
    
    public function getVectorContent(): string
    {
        return $this->title . "\n\n" . $this->content;
    }
}
```

Then index it:
```bash
php artisan ai-engine:vector-index "App\Models\Document"
```

Now when users search, the AI will automatically choose between Post and Document based on the query!

## Common Queries and Which Collections They Match

| Query | Matches | Why |
|-------|---------|-----|
| "Find Laravel articles" | Post | Contains "articles", "Laravel" |
| "Show me contracts" | Document | Contains "contracts" |
| "Email from John" | Email | Contains "email" |
| "User accounts" | User | Contains "user", "accounts" |

## Next Steps

- 📖 [Full Documentation](./INTELLIGENT_FEDERATED_SEARCH.md)
- 🌐 [Federated Search Setup](./FEDERATED_NODES.md)
- 🔒 [Access Control Guide](./ACCESS_CONTROL.md)
- 🚀 [Advanced Usage](./ADVANCED_USAGE.md)

## Troubleshooting

### "No results found"

1. Check indexing: `php artisan ai-engine:vector-status "App\Models\Post"`
2. Verify `$skipUserFilter` is set correctly
3. Test manually:
   ```php
   $results = Post::vectorSearch('Laravel', limit: 5);
   echo "Found: " . $results->count();
   ```

### "AI not selecting my collection"

1. Improve RAG description with more keywords
2. Make sure description includes query terms
3. Test with explicit collection:
   ```php
   $response = $rag->processMessage(
       message: 'Find Laravel articles',
       sessionId: 'test',
       availableCollections: ['App\Models\Post'],  // Force it
       conversationHistory: [],
       options: [],
       userId: auth()->id()
   );
   ```

### "Collection not discovered"

1. Clear cache: `php artisan cache:clear`
2. Check model uses `Vectorizable` trait
3. Verify model is indexed: `php artisan ai-engine:vector-status "App\Models\Post"`

## Support

Need help? Check:
- 📖 [Full Documentation](./INTELLIGENT_FEDERATED_SEARCH.md)
- 🐛 [Issue Tracker](https://github.com/your-repo/issues)
- 💬 [Discussions](https://github.com/your-repo/discussions)
