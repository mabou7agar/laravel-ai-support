# Quick Start Guide

Get started with Laravel AI Engine in 5 minutes!

## Basic Chat

### Simple Chat Request

```php
use LaravelAIEngine\Facades\AIEngine;

$response = AIEngine::chat('What is Laravel?');
echo $response;
```

### Chat with Options

```php
$response = AIEngine::chat('Explain dependency injection', [
    'model' => 'gpt-4o',
    'temperature' => 0.7,
    'max_tokens' => 500,
]);
```

### Streaming Chat

```php
AIEngine::streamChat('Write a story about Laravel', function ($chunk) {
    echo $chunk;
    flush();
});
```

## Conversations

### Create a Conversation

```php
use LaravelAIEngine\Services\ConversationManager;

$conversation = app(ConversationManager::class)->createConversation(
    userId: auth()->id(),
    title: 'Laravel Help',
    metadata: ['topic' => 'framework']
);
```

### Send Messages

```php
$response = $conversation->sendMessage('How do I create a middleware?');
echo $response;
```

### Streaming Conversation

```php
$conversation->streamMessage('Explain Laravel queues', function ($chunk) {
    echo $chunk;
});
```

## Image Generation

### Generate Images

```php
use LaravelAIEngine\Facades\AIEngine;

$images = AIEngine::generateImages(
    prompt: 'A futuristic Laravel logo',
    count: 2,
    size: '1024x1024'
);

foreach ($images as $url) {
    echo "<img src='{$url}' />";
}
```

## Vision (Image Analysis)

### Analyze Images

```php
use LaravelAIEngine\Services\Media\VisionService;

$vision = app(VisionService::class);

$analysis = $vision->analyzeImage(
    imagePath: storage_path('app/photo.jpg'),
    prompt: 'What is in this image?'
);

echo $analysis['description'];
```

## Audio Transcription

### Transcribe Audio

```php
use LaravelAIEngine\Services\Media\AudioService;

$audio = app(AudioService::class);

$transcription = $audio->transcribe(
    audioPath: storage_path('app/recording.mp3')
);

echo $transcription['text'];
```

## Vector Search

### Setup Model

```php
use LaravelAIEngine\Traits\HasVectorSearch;
use LaravelAIEngine\Traits\Vectorizable;

class Post extends Model
{
    use HasVectorSearch, Vectorizable;

    public function toVectorContent(): string
    {
        return $this->title . "\n\n" . $this->content;
    }
}
```

### Index Models

```php
// Index a single model
$post = Post::find(1);
$post->indexVector();

// Index all posts
Post::chunk(100, function ($posts) {
    foreach ($posts as $post) {
        $post->indexVector();
    }
});

// Or use Artisan command
php artisan ai-engine:vector-index "App\Models\Post"
```

### Search

```php
$results = Post::vectorSearch('Laravel best practices', limit: 10);

foreach ($results as $post) {
    echo $post->title . " (Score: {$post->similarity_score})\n";
}
```

## RAG (Retrieval Augmented Generation)

### Chat with Context

```php
use LaravelAIEngine\Traits\HasVectorChat;

class Post extends Model
{
    use HasVectorSearch, Vectorizable, HasVectorChat;
}

// Chat with automatic context retrieval
$response = Post::ragChat(
    query: 'What are Laravel best practices?',
    maxContext: 5
);

echo $response['answer'];
```

## Credit Management

### Check Credits

```php
use LaravelAIEngine\Services\CreditManager;

$credits = app(CreditManager::class);

$balance = $credits->getBalance(auth()->id());
echo "Credits: {$balance}";
```

### Add Credits

```php
$credits->addCredits(auth()->id(), 100);
```

## Analytics

### View Usage

```php
use LaravelAIEngine\Services\AnalyticsManager;

$analytics = app(AnalyticsManager::class);

$usage = $analytics->getUserUsage(auth()->id(), days: 30);
echo "Total requests: {$usage['total_requests']}";
echo "Total tokens: {$usage['total_tokens']}";
```

### Artisan Commands

```bash
# View analytics
php artisan ai-engine:analytics

# View usage report
php artisan ai-engine:usage-report --user=123

# View vector analytics
php artisan ai-engine:vector-analytics --global
```

## Advanced Features

### Custom Engine Configuration

```php
AIEngine::engine('anthropic')
    ->model('claude-3-opus-20240229')
    ->chat('Explain quantum computing');
```

### Failover

```php
AIEngine::withFailover(['openai', 'anthropic', 'gemini'])
    ->chat('What is AI?');
```

### Caching

```php
AIEngine::cached(ttl: 3600)
    ->chat('What is Laravel?');
```

### Rate Limiting

```php
AIEngine::rateLimit(maxRequests: 10, perMinutes: 1)
    ->chat('Hello');
```

## Next Steps

- [Configuration Guide](configuration.md)
- [Vector Search Guide](vector-search.md)
- [RAG Guide](rag.md)
- [API Reference](api-reference.md)
