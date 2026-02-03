---
name: laravel-vector-search
description: Set up and configure vector search with embeddings for semantic search capabilities. Use this when the user wants to implement similarity search, recommendations, semantic search, or any feature requiring vector embeddings.
---

# Laravel Vector Search Setup

Configure and implement vector search with embeddings for powerful semantic search capabilities in your Laravel application.

## When to Use This Skill

- User wants semantic search functionality
- User needs product recommendations
- User wants document similarity search
- User needs content discovery features
- User wants to search by meaning, not just keywords

## What is Vector Search?

Vector search uses AI embeddings to find similar content based on meaning, not just keyword matching.

**Traditional Search**: "red shoes" only finds exact matches
**Vector Search**: "red shoes" also finds "crimson footwear", "scarlet sneakers"

## Setup Process

### 1. Choose Embedding Provider

- **OpenAI**: `text-embedding-3-small` (1536 dimensions)
- **Voyage AI**: `voyage-2` (1024 dimensions)
- **Cohere**: `embed-english-v3.0` (1024 dimensions)

### 2. Database Setup

Add vector column to your table:

```php
// Migration
Schema::table('products', function (Blueprint $table) {
    $table->vector('embedding', 1536)->nullable();
    $table->index('embedding', 'products_embedding_idx', 'vector');
});
```

### 3. Model Configuration

```php
use LaravelAIEngine\Traits\HasVectorSearch;

class Product extends Model
{
    use HasVectorSearch;

    protected $vectorSearchFields = ['name', 'description', 'category'];
    
    protected $embeddingProvider = 'openai';
    
    protected $embeddingDimension = 1536;
}
```

### 4. Generate Embeddings

```php
// Automatic on save
$product = Product::create([
    'name' => 'Wireless Headphones',
    'description' => 'Premium noise-cancelling headphones',
]);
// Embedding automatically generated

// Manual generation
$product->generateEmbedding();

// Batch generation
Product::generateEmbeddingsForAll();
```

### 5. Search

```php
// Semantic search
$results = Product::vectorSearch('audio equipment for music')
    ->threshold(0.3)
    ->limit(10)
    ->get();

// With filters
$results = Product::vectorSearch('comfortable headphones')
    ->where('price', '<', 200)
    ->where('in_stock', true)
    ->get();

// Get similarity scores
$results = Product::vectorSearch('wireless audio')
    ->withSimilarity()
    ->get();

foreach ($results as $product) {
    echo $product->similarity_score; // 0.0 to 1.0
}
```

## Complete Example

### Product Recommendation System

```php
// 1. Migration
php artisan make:migration add_vector_search_to_products

// 2. In migration
public function up()
{
    Schema::table('products', function (Blueprint $table) {
        $table->vector('embedding', 1536)->nullable();
        $table->index('embedding', 'products_embedding_idx', 'vector');
    });
}

// 3. Model
class Product extends Model
{
    use HasVectorSearch;

    protected $vectorSearchFields = ['name', 'description', 'category', 'tags'];
    protected $embeddingProvider = 'openai';
}

// 4. Controller
class ProductController extends Controller
{
    public function search(Request $request)
    {
        $results = Product::vectorSearch($request->query)
            ->threshold(0.3)
            ->where('active', true)
            ->limit(20)
            ->withSimilarity()
            ->get();

        return ProductResource::collection($results);
    }

    public function recommendations(Product $product)
    {
        $similar = Product::vectorSearchSimilar($product)
            ->where('id', '!=', $product->id)
            ->limit(5)
            ->get();

        return ProductResource::collection($similar);
    }
}

// 5. API Routes
Route::get('/products/search', [ProductController::class, 'search']);
Route::get('/products/{product}/recommendations', [ProductController::class, 'recommendations']);
```

## Artisan Commands

```bash
# Generate embeddings for all records
php artisan vector:generate Product

# Generate for specific records
php artisan vector:generate Product --ids=1,2,3

# Regenerate all embeddings
php artisan vector:regenerate Product

# Test vector search
php artisan vector:test Product "search query"
```

## Configuration

```php
// config/ai-engine.php
'vector_search' => [
    'default_provider' => 'openai',
    'default_dimension' => 1536,
    'default_threshold' => 0.3,
    'batch_size' => 100,
    
    'providers' => [
        'openai' => [
            'model' => 'text-embedding-3-small',
            'dimension' => 1536,
            'cost_per_1k' => 0.00002,
        ],
        'voyage' => [
            'model' => 'voyage-2',
            'dimension' => 1024,
            'cost_per_1k' => 0.00001,
        ],
    ],
],
```

## Multi-Tenant Support

```php
class Product extends Model
{
    use HasVectorSearch, BelongsToWorkspace;

    // Automatic workspace scoping
    public function scopeVectorSearchInWorkspace($query, $searchQuery)
    {
        return $query->vectorSearch($searchQuery)
            ->where('workspace_id', auth()->user()->workspace_id);
    }
}

// Usage
$results = Product::vectorSearchInWorkspace('laptop computers')->get();
```

## Performance Tips

1. **Batch Generation**: Generate embeddings in batches for better performance
2. **Caching**: Cache frequently searched embeddings
3. **Threshold**: Use appropriate threshold (0.3-0.5 recommended)
4. **Limit Results**: Always limit results for better performance
5. **Index**: Ensure vector column is indexed

## Use Cases

### E-commerce
- Product recommendations
- Similar product search
- Visual search with descriptions

### Content Platform
- Article recommendations
- Content discovery
- Duplicate detection

### Support System
- Similar ticket search
- Knowledge base search
- Auto-categorization

### HR System
- Resume matching
- Job recommendations
- Skill-based search

## Example Prompts

- "Set up vector search for product recommendations in my e-commerce app"
- "Implement semantic search for blog posts with OpenAI embeddings"
- "Create a similar items feature using vector search"
- "Add document similarity search to my knowledge base"
