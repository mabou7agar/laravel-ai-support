# RAG (Retrieval Augmented Generation) Guide

Complete guide to building context-aware AI applications with RAG.

## Table of Contents

- [Introduction](#introduction)
- [How RAG Works](#how-rag-works)
- [Setup](#setup)
- [Basic Usage](#basic-usage)
- [Advanced Features](#advanced-features)
- [Best Practices](#best-practices)

## Introduction

RAG (Retrieval Augmented Generation) combines vector search with AI chat to provide context-aware responses based on your data.

### Benefits

- 🎯 **Accurate Answers** - AI responses based on your actual data
- 📚 **Source Citations** - Know where answers come from
- 🔄 **Always Current** - Uses latest data without retraining
- 💰 **Cost Effective** - No model fine-tuning required

### Use Cases

- Customer support chatbots
- Documentation assistants
- Knowledge base search
- Content recommendation
- Research assistants

## How RAG Works

```
1. User Query → "What are Laravel best practices?"
2. Vector Search → Find relevant content from your database
3. Context Building → Combine search results
4. AI Generation → Generate answer using context
5. Response → Answer with sources
```

## Setup

### 1. Enable Vector Search

Follow the [Vector Search Guide](vector-search.md) to set up vector search.

### 2. Add Traits to Model

```php
use LaravelAIEngine\Traits\HasVectorSearch;
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Traits\HasVectorChat;

class Post extends Model
{
    use HasVectorSearch, Vectorizable, HasVectorChat;
    
    public function toVectorContent(): string
    {
        return $this->title . "\n\n" . $this->content;
    }
}
```

### 3. Index Your Data

```bash
php artisan ai-engine:vector-index "App\Models\Post"
```

## Basic Usage

### Simple RAG Chat

```php
$response = Post::ragChat('What are Laravel best practices?');

echo $response['answer'];
// "Based on the documentation, Laravel best practices include..."

// View sources
foreach ($response['sources'] as $source) {
    echo $source->title;
}
```

### With Options

```php
$response = Post::ragChat(
    query: 'Explain Laravel queues',
    maxContext: 5,           // Number of relevant items to retrieve
    minRelevance: 0.7,       // Minimum similarity score
    includeSources: true,    // Include source citations
    model: 'gpt-4o',        // AI model to use
    temperature: 0.7,        // Response creativity
);
```

### Streaming Responses

```php
Post::ragChatStream(
    query: 'Write a tutorial about Laravel',
    callback: function ($chunk) {
        echo $chunk;
        flush();
    },
    maxContext: 5
);
```

## Advanced Features

### Using RAG Service Directly

```php
use LaravelAIEngine\Services\RAG\VectorRAGBridge;

$rag = app(VectorRAGBridge::class);

$response = $rag->chat(
    modelClass: Post::class,
    query: 'What is dependency injection?',
    options: [
        'max_context_items' => 5,
        'min_relevance_score' => 0.6,
        'include_sources' => true,
    ]
);
```

### Custom Context Building

```php
use LaravelAIEngine\Services\RAG\VectorRAGBridge;

$rag = app(VectorRAGBridge::class);

// Get relevant context
$context = $rag->getRelevantContext(
    modelClass: Post::class,
    query: 'Laravel routing',
    maxItems: 5,
    minScore: 0.7
);

// Build custom prompt
$prompt = "Based on the following information:\n\n";
foreach ($context as $item) {
    $prompt .= "- {$item['content']}\n";
}
$prompt .= "\nAnswer: {$query}";

// Use with AI
$response = AIEngine::chat($prompt);
```

### Conversation with RAG

```php
use LaravelAIEngine\Services\ConversationManager;

$conversation = app(ConversationManager::class)->createConversation(
    userId: auth()->id(),
    title: 'Laravel Help'
);

// Enable RAG for conversation
$response = $conversation->ragMessage(
    message: 'How do I create a middleware?',
    modelClass: Post::class,
    maxContext: 5
);

// Continue conversation with context
$response = $conversation->ragMessage(
    message: 'Can you show me an example?',
    modelClass: Post::class
);
```

### Multi-Model RAG

Search across multiple models:

```php
use LaravelAIEngine\Services\RAG\VectorRAGBridge;

$rag = app(VectorRAGBridge::class);

// Search posts and documentation
$postContext = $rag->getRelevantContext(Post::class, $query, 3);
$docContext = $rag->getRelevantContext(Documentation::class, $query, 3);

$allContext = array_merge($postContext, $docContext);

// Generate answer with combined context
$response = $rag->generateWithContext($query, $allContext);
```

### Custom System Prompts

```php
$response = Post::ragChat(
    query: 'Explain Laravel',
    systemPrompt: 'You are a Laravel expert. Provide detailed, technical answers with code examples.'
);
```

### Metadata Filtering

```php
// Only search published posts
$response = Post::where('status', 'published')
    ->ragChat('Laravel tutorials');

// Filter by category
$response = Post::where('category_id', 5)
    ->ragChat('Advanced techniques');
```

## Configuration

Edit `config/ai-engine.php`:

```php
'vector' => [
    'rag' => [
        'enabled' => true,
        'max_context_items' => env('VECTOR_RAG_MAX_CONTEXT', 5),
        'include_sources' => env('VECTOR_RAG_INCLUDE_SOURCES', true),
        'min_relevance_score' => env('VECTOR_RAG_MIN_SCORE', 0.5),
        'system_prompt' => 'You are a helpful assistant. Answer based on the provided context.',
    ],
],
```

## Response Format

### Standard Response

```php
[
    'answer' => 'Based on the documentation...',
    'sources' => [
        [
            'id' => 1,
            'content' => 'Laravel is a PHP framework...',
            'score' => 0.89,
            'metadata' => [
                'title' => 'Getting Started',
                'url' => 'https://...',
            ],
        ],
        // ... more sources
    ],
    'context_used' => 3,
    'total_tokens' => 450,
]
```

### Streaming Response

Chunks are sent as they're generated:

```php
Post::ragChatStream('query', function ($chunk) {
    // $chunk contains partial response text
    echo $chunk;
});
```

## Best Practices

### 1. Optimize Context Size

```php
// Too few - may miss relevant info
$response = Post::ragChat($query, maxContext: 1);

// Too many - expensive and may confuse AI
$response = Post::ragChat($query, maxContext: 20);

// Optimal - balance relevance and cost
$response = Post::ragChat($query, maxContext: 5);
```

### 2. Set Appropriate Thresholds

```php
// High threshold - only very relevant results
$response = Post::ragChat($query, minRelevance: 0.8);

// Low threshold - more results, less relevant
$response = Post::ragChat($query, minRelevance: 0.3);

// Recommended
$response = Post::ragChat($query, minRelevance: 0.6);
```

### 3. Include Rich Metadata

```php
public function toVectorMetadata(): array
{
    return [
        'title' => $this->title,
        'url' => route('posts.show', $this),
        'author' => $this->user->name,
        'category' => $this->category->name,
        'published_at' => $this->published_at->format('Y-m-d'),
        'excerpt' => Str::limit($this->content, 200),
    ];
}
```

### 4. Prepare Quality Content

```php
public function toVectorContent(): string
{
    // Include context
    $content = "Title: {$this->title}\n\n";
    $content .= "Category: {$this->category->name}\n\n";
    $content .= $this->content;
    
    // Add related information
    if ($this->tags->isNotEmpty()) {
        $content .= "\n\nTags: " . $this->tags->pluck('name')->join(', ');
    }
    
    return $content;
}
```

### 5. Handle No Results

```php
$response = Post::ragChat($query);

if (empty($response['sources'])) {
    // Fallback to general AI response
    $response = AIEngine::chat($query);
} else {
    // Use RAG response
    echo $response['answer'];
}
```

### 6. Cache Frequent Queries

```php
use Illuminate\Support\Facades\Cache;

$cacheKey = 'rag:' . md5($query);

$response = Cache::remember($cacheKey, 3600, function () use ($query) {
    return Post::ragChat($query);
});
```

### 7. Monitor Performance

```php
use LaravelAIEngine\Services\Vector\VectorAnalyticsService;

$analytics = app(VectorAnalyticsService::class);

// Track RAG usage
$stats = $analytics->getGlobalAnalytics(days: 7);
echo "Average execution time: {$stats['summary']['avg_execution_time']}ms";
```

## Use Case Examples

### Customer Support Bot

```php
class SupportController extends Controller
{
    public function chat(Request $request)
    {
        $response = SupportArticle::ragChat(
            query: $request->question,
            maxContext: 5,
            minRelevance: 0.7
        );
        
        return response()->json([
            'answer' => $response['answer'],
            'articles' => $response['sources'],
        ]);
    }
}
```

### Documentation Search

```php
class DocsController extends Controller
{
    public function search(Request $request)
    {
        $response = Documentation::where('version', '11.x')
            ->ragChat(
                query: $request->query,
                maxContext: 3,
                includeSources: true
            );
        
        return view('docs.search', [
            'answer' => $response['answer'],
            'sources' => $response['sources'],
        ]);
    }
}
```

### Knowledge Base Assistant

```php
class KnowledgeBaseController extends Controller
{
    public function ask(Request $request)
    {
        // Multi-model search
        $rag = app(VectorRAGBridge::class);
        
        $context = collect([
            ...$rag->getRelevantContext(Article::class, $request->question, 3),
            ...$rag->getRelevantContext(FAQ::class, $request->question, 2),
        ]);
        
        $response = $rag->generateWithContext(
            query: $request->question,
            context: $context->toArray()
        );
        
        return response()->json($response);
    }
}
```

### Streaming Chat Interface

```php
class ChatController extends Controller
{
    public function stream(Request $request)
    {
        return response()->stream(function () use ($request) {
            Post::ragChatStream(
                query: $request->message,
                callback: function ($chunk) {
                    echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                    ob_flush();
                    flush();
                },
                maxContext: 5
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
```

## Troubleshooting

### Poor Answer Quality

1. **Increase context items:**
   ```php
   Post::ragChat($query, maxContext: 10)
   ```

2. **Lower relevance threshold:**
   ```php
   Post::ragChat($query, minRelevance: 0.4)
   ```

3. **Improve content quality:**
   ```php
   public function toVectorContent(): string
   {
       // Add more context and structure
       return "Title: {$this->title}\n\n{$this->content}";
   }
   ```

### No Relevant Context Found

1. Check if data is indexed
2. Verify vector search is working
3. Lower the threshold
4. Improve query phrasing

### High Costs

1. Reduce `maxContext`
2. Enable caching
3. Use cheaper models (gpt-4o-mini)
4. Implement query deduplication

## Performance Tuning

### Model Selection

Choose the right models for optimal performance:

```env
# .env
INTELLIGENT_RAG_ANALYSIS_MODEL=gpt-4o-mini    # Fast query classification
INTELLIGENT_RAG_RESPONSE_MODEL=gpt-5-mini     # Quality responses
```

| Task | Recommended Model | Why |
|------|------------------|-----|
| Query Analysis | `gpt-4o-mini` | Fast, cheap, sufficient for classification |
| Response Generation | `gpt-5-mini` | Good quality, balanced cost |
| Complex Reasoning | `gpt-5.1` | Best quality, higher cost |

### Context Optimization

```env
# Truncate long content to prevent slow API calls
VECTOR_RAG_MAX_ITEM_LENGTH=2000

# Limit context items
INTELLIGENT_RAG_MAX_CONTEXT=5
```

### Performance Benchmarks

| Config | Analysis | Response | Total |
|--------|----------|----------|-------|
| Both gpt-4o-mini | ~2s | ~2s | ~3-4s |
| gpt-4o-mini + gpt-5-mini | ~2s | ~3s | ~5-6s |
| Both GPT-5 | ~6s | ~6s | ~12s |

**Note:** GPT-5 models have reasoning overhead. Use `gpt-4o-mini` for analysis tasks.

## Next Steps

- [Vector Search Guide](vector-search.md)
- [Conversation Management](conversations.md)
- [Performance Optimization](performance.md)
