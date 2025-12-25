# Vector Search Guide

Complete guide to semantic vector search with Laravel AI Engine.

## Table of Contents

- [Introduction](#introduction)
- [Setup](#setup)
- [Configuration](#configuration)
- [Model Setup](#model-setup)
- [Indexing](#indexing)
- [Searching](#searching)
- [Advanced Features](#advanced-features)
- [Artisan Commands](#artisan-commands)

## Introduction

Vector search enables semantic search capabilities in your Laravel application. Instead of exact keyword matching, vector search understands the meaning and context of queries.

### Benefits

- 🎯 **Semantic Understanding** - Find relevant content even with different wording
- 🚀 **Fast Performance** - Optimized vector databases (Qdrant, Pinecone)
- 🔍 **Better Results** - Context-aware search results
- 📊 **Analytics** - Track search performance and usage

## Setup

### 1. Choose Vector Database

#### Option A: Qdrant (Recommended)

```bash
# Install Qdrant locally with Docker
docker run -p 6333:6333 qdrant/qdrant

# Or use Qdrant Cloud
# https://cloud.qdrant.io/
```

```env
VECTOR_DB_DRIVER=qdrant
QDRANT_HOST=http://localhost:6333
QDRANT_API_KEY=
```

#### Option B: Pinecone

```env
VECTOR_DB_DRIVER=pinecone
PINECONE_API_KEY=your-api-key
PINECONE_ENVIRONMENT=us-west1-gcp
PINECONE_INDEX=my-index
```

### 2. Configure OpenAI

Vector search requires OpenAI for generating embeddings:

```env
OPENAI_API_KEY=sk-...
VECTOR_EMBEDDING_MODEL=text-embedding-3-large
VECTOR_EMBEDDING_DIMENSIONS=3072
```

### 3. Run Migrations

```bash
php artisan migrate
```

## Configuration

Edit `config/ai-engine.php`:

```php
'vector' => [
    'enabled' => true,
    'driver' => env('VECTOR_DB_DRIVER', 'qdrant'),
    
    'drivers' => [
        'qdrant' => [
            'host' => env('QDRANT_HOST', 'http://localhost:6333'),
            'api_key' => env('QDRANT_API_KEY'),
            'timeout' => 30,
        ],
        'pinecone' => [
            'api_key' => env('PINECONE_API_KEY'),
            'environment' => env('PINECONE_ENVIRONMENT'),
            'index' => env('PINECONE_INDEX'),
        ],
    ],
    
    'embeddings' => [
        'model' => env('VECTOR_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'dimensions' => (int) env('VECTOR_EMBEDDING_DIMENSIONS', 3072),
        'cache_enabled' => true,
        'cache_ttl' => 86400, // 24 hours
    ],
],
```

## Model Setup

### Add Traits

```php
use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\HasVectorSearch;
use LaravelAIEngine\Traits\Vectorizable;

class Post extends Model
{
    use HasVectorSearch, Vectorizable;
    
    /**
     * Define what content should be indexed
     */
    public function toVectorContent(): string
    {
        return $this->title . "\n\n" . $this->content;
    }
    
    /**
     * Optional: Add metadata to search results
     */
    public function toVectorMetadata(): array
    {
        return [
            'author' => $this->user->name,
            'category' => $this->category->name,
            'published_at' => $this->published_at?->toIso8601String(),
            'status' => $this->status,
        ];
    }
    
    /**
     * Optional: Control which models should be indexed
     */
    public function shouldBeIndexed(): bool
    {
        return $this->status === 'published';
    }
}
```

### Collection Name

By default, the collection name is the model's table name. Customize it:

```php
class Post extends Model
{
    use HasVectorSearch, Vectorizable;
    
    protected $vectorCollection = 'posts_v2';
}
```

## Indexing

### Manual Indexing

```php
// Index a single model
$post = Post::find(1);
$post->indexVector();

// Index with specific user (for credit tracking)
$post->indexVector(userId: auth()->id());
```

### Batch Indexing

```php
// Index all posts
Post::chunk(100, function ($posts) {
    foreach ($posts as $post) {
        $post->indexVector();
    }
});
```

### Artisan Command

```bash
# Index all posts
php artisan ai-engine:vector-index "App\Models\Post"

# Index specific IDs
php artisan ai-engine:vector-index "App\Models\Post" --id=1 --id=2 --id=3

# Batch size
php artisan ai-engine:vector-index "App\Models\Post" --batch=100

# Queue for background processing
php artisan ai-engine:vector-index "App\Models\Post" --queue

# Force re-indexing (deletes and recreates collection)
php artisan ai-engine:vector-index "App\Models\Post" --force
```

### Force Recreate Collections

The `--force` flag is powerful for fixing dimension mismatches or updating payload indexes:

```bash
# Force recreate all collections
php artisan ai-engine:vector-index --force

# Force recreate specific model collection
php artisan ai-engine:vector-index "App\Models\Email" --force
```

**What `--force` does:**
1. **Deletes existing collection** - Removes old collection with wrong dimensions/indexes
2. **Creates new collection** - With correct embedding dimensions (e.g., 1536 for text-embedding-3-small)
3. **Auto-detects relationships** - Creates payload indexes for all `belongsTo` foreign keys
4. **Schema-based types** - Detects field types (integer, UUID, string) from database

### Smart Payload Indexes

The system automatically creates payload indexes for efficient filtering:

```php
// Example: EmailCache model with belongsTo relations
class EmailCache extends Model
{
    use Vectorizable;
    
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function mailbox(): BelongsTo { return $this->belongsTo(Mailbox::class); }
}

// Automatically creates payload indexes for:
// - user_id (integer - detected from schema)
// - mailbox_id (keyword - UUID detected from schema)
// - Plus config fields: tenant_id, workspace_id, status, etc.
```

Configure base payload index fields in `config/ai-engine.php`:

```php
'vector' => [
    'payload_index_fields' => [
        'user_id',
        'tenant_id', 
        'workspace_id',
        'model_id',
        'status',
        'visibility',
        'type',
    ],
],
```

### Auto-Indexing

Enable automatic indexing when models are created/updated:

```php
// In AppServiceProvider
use LaravelAIEngine\Observers\VectorIndexObserver;

public function boot()
{
    Post::observe(VectorIndexObserver::class);
}
```

Configure in `config/ai-engine.php`:

```php
'vector' => [
    'auto_index' => [
        'enabled' => true,
        'queue' => true, // Use queue for background indexing
        'on_create' => true,
        'on_update' => true,
        'on_delete' => true,
    ],
],
```

## Searching

### Basic Search

```php
$results = Post::vectorSearch('Laravel best practices');

foreach ($results as $post) {
    echo $post->title;
    echo "Similarity: {$post->similarity_score}";
}
```

### Search with Options

```php
$results = Post::vectorSearch(
    query: 'machine learning tutorials',
    limit: 20,
    threshold: 0.7, // Minimum similarity score (0-1)
    userId: auth()->id() // For analytics
);
```

### Filter Results

```php
$results = Post::vectorSearch('Laravel')
    ->where('status', 'published')
    ->where('category_id', 5)
    ->get();
```

### Get Raw Results

```php
use LaravelAIEngine\Services\Vector\VectorSearchService;

$vectorSearch = app(VectorSearchService::class);

$results = $vectorSearch->search(
    collection: 'posts',
    query: 'Laravel tutorials',
    limit: 10,
    threshold: 0.5
);

// Results include:
// - id: Model ID
// - score: Similarity score
// - metadata: Model metadata
```

### Artisan Command

```bash
# Search from CLI
php artisan ai-engine:vector-search "App\Models\Post" "Laravel best practices"

# With options
php artisan ai-engine:vector-search "App\Models\Post" "AI" --limit=20 --threshold=0.7

# JSON output
php artisan ai-engine:vector-search "App\Models\Post" "query" --json
```

## Advanced Features

### Chunking Large Content

For large documents, use automatic chunking:

```php
use LaravelAIEngine\Services\Vector\ChunkingService;

$chunker = app(ChunkingService::class);

$chunks = $chunker->chunk(
    text: $largeDocument,
    maxTokens: 500,
    overlap: 50,
    type: 'markdown' // text, code, markdown, html
);

foreach ($chunks as $chunk) {
    // Index each chunk separately
    $vectorSearch->index($model, content: $chunk['text']);
}
```

### Authorization & Security

Enable row-level security:

```php
use LaravelAIEngine\Services\Vector\VectorAuthorizationService;

$auth = app(VectorAuthorizationService::class);

// Check if user can search
if ($auth->canSearch(auth()->user(), 'posts')) {
    $results = Post::vectorSearch('query');
}

// Apply filters
$filters = $auth->getSearchFilters(auth()->user(), 'posts');
$results = $vectorSearch->search('posts', 'query', filters: $filters);
```

Configure in `config/ai-engine.php`:

```php
'vector' => [
    'authorization' => [
        'enabled' => true,
        'filter_by_user' => true,
        'filter_by_visibility' => true,
        'row_level_security' => [
            ['field' => 'user_id', 'operator' => '==', 'value' => '{user_id}'],
        ],
    ],
],
```

### Analytics

Track search performance:

```php
use LaravelAIEngine\Services\Vector\VectorAnalyticsService;

$analytics = app(VectorAnalyticsService::class);

// Global analytics
$stats = $analytics->getGlobalAnalytics(days: 30);

// User analytics
$userStats = $analytics->getUserAnalytics(userId: auth()->id(), days: 7);

// Model analytics
$modelStats = $analytics->getModelAnalytics(modelClass: Post::class);

// Performance metrics
$performance = $analytics->getPerformanceMetrics(days: 7);
```

### Caching

Embeddings are automatically cached. Configure:

```php
'vector' => [
    'embeddings' => [
        'cache_enabled' => true,
        'cache_ttl' => 86400, // 24 hours
    ],
],
```

## Artisan Commands

### Index Command

```bash
php artisan ai-engine:vector-index "App\Models\Post" [options]

Options:
  --id[=ID]         Specific model IDs (multiple)
  --batch[=BATCH]   Batch size [default: 100]
  --force           Force re-indexing
  --queue           Queue the jobs
```

### Search Command

```bash
php artisan ai-engine:vector-search "App\Models\Post" "query" [options]

Options:
  --limit[=LIMIT]          Results to return [default: 10]
  --threshold[=THRESHOLD]  Minimum similarity [default: 0.3]
  --json                   Output as JSON
```

### Analytics Command

```bash
php artisan ai-engine:vector-analytics [options]

Options:
  --user[=USER]      User ID
  --model[=MODEL]    Model class
  --days[=DAYS]      Days to analyze [default: 30]
  --export[=EXPORT]  Export to CSV
  --global           Show global analytics
```

### Clean Command

```bash
php artisan ai-engine:vector-clean [options]

Options:
  --model[=MODEL]          Model class to clean
  --orphaned               Remove orphaned embeddings
  --analytics[=ANALYTICS]  Clean analytics older than N days
  --dry-run                Show what would be deleted
  --force                  Skip confirmation
```

## Best Practices

### 1. Content Preparation

```php
public function toVectorContent(): string
{
    // Include relevant context
    $content = $this->title . "\n\n";
    $content .= $this->content . "\n\n";
    $content .= "Category: " . $this->category->name . "\n";
    $content .= "Tags: " . $this->tags->pluck('name')->join(', ');
    
    return $content;
}
```

### 2. Metadata

```php
public function toVectorMetadata(): array
{
    return [
        'id' => $this->id,
        'type' => 'post',
        'url' => route('posts.show', $this),
        'author' => $this->user->name,
        'created_at' => $this->created_at->toIso8601String(),
    ];
}
```

### 3. Filtering

```php
public function shouldBeIndexed(): bool
{
    return $this->status === 'published' 
        && !$this->is_draft
        && $this->content !== null;
}
```

### 4. Background Processing

Always use queues for large indexing operations:

```bash
php artisan ai-engine:vector-index "App\Models\Post" --queue
```

### 5. Monitoring

Regularly check analytics:

```bash
php artisan ai-engine:vector-analytics --global --days=7
```

## Troubleshooting

### No Results Found

1. Check if models are indexed:
   ```bash
   php artisan ai-engine:vector-index "App\Models\Post" --force
   ```

2. Lower the threshold:
   ```php
   Post::vectorSearch('query', threshold: 0.1)
   ```

3. Verify vector database connection

### Slow Performance

1. Enable caching
2. Use batch indexing
3. Optimize content length
4. Use queues for indexing

### High API Costs

1. Enable embedding cache
2. Reduce re-indexing frequency
3. Use smaller embedding models
4. Implement content deduplication

## Next Steps

- [RAG Guide](rag.md)
- [API Reference](api-reference.md)
- [Performance Optimization](performance.md)
