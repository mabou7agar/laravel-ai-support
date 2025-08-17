# Laravel AI Engine

A comprehensive Laravel package for multi-AI engine integration with advanced job queue processing, intelligent rate limiting, credit management, streaming, analytics, and enterprise-grade features.

## Features

### 🚀 **Core AI Integration**
- **Multi-Engine Support**: OpenAI, Anthropic, Gemini, Stable Diffusion, ElevenLabs, FAL AI, and more
- **Streaming Responses**: Real-time AI output streaming with SSE support
- **Content Types**: Text generation, image creation, video generation, audio processing
- **Templates**: Reusable prompt templates with variable substitution

### ⚡ **Advanced Job Queue System**
- **Background Processing**: Queue AI requests for asynchronous processing
- **Batch Operations**: Process multiple AI requests efficiently in batches
- **Long-Running Tasks**: Handle expensive operations (video generation, large batches) with progress tracking
- **Webhook Notifications**: Reliable webhook delivery with retry mechanisms
- **Job Status Tracking**: Real-time job progress monitoring and status updates

### 🎯 **Intelligent Rate Limiting**
- **Queue-Based Rate Limiting**: Smart rate limiting integrated with job queue system
- **User-Specific Limits**: Per-user rate limiting for multi-tenant applications
- **Automatic Job Delays**: Rate-limited jobs are automatically delayed and retried
- **Batch Intelligence**: Intelligent splitting of batch requests when rate limited
- **Configurable Delays**: Exponential backoff with jitter to prevent thundering herd

### 💰 **Enterprise Features**
- **Credit System**: Built-in usage tracking and billing management
- **Analytics**: Comprehensive usage analytics and monitoring
- **Retry & Fallback**: Automatic retry mechanisms with fallback engines
- **Caching**: Response caching to reduce costs and improve performance
- **Error Handling**: Robust error handling and logging
- **Laravel Integration**: Seamless Laravel integration with Artisan commands

## Installation

```bash
composer require magicai/laravel-ai-engine
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=ai-engine-config
```

Run the migrations:

```bash
php artisan migrate
```

## Configuration

Add your AI service API keys to your `.env` file:

```env
# OpenAI
OPENAI_API_KEY=your_openai_api_key
OPENAI_ORGANIZATION=your_org_id

# Anthropic
ANTHROPIC_API_KEY=your_anthropic_api_key

# Gemini
GEMINI_API_KEY=your_gemini_api_key

# Stability AI
STABILITY_API_KEY=your_stability_api_key

# Default engine
AI_ENGINE_DEFAULT=openai

# Credit system
AI_CREDITS_ENABLED=true
AI_DEFAULT_CREDITS=100.0

# Caching
AI_CACHE_ENABLED=true
AI_CACHE_TTL=3600

# Rate limiting
AI_RATE_LIMITING_ENABLED=true
AI_RATE_LIMITING_APPLY_TO_JOBS=true
```

## Quick Start

### Basic Text Generation

```php
use MagicAI\LaravelAIEngine\Facades\AIEngine;

// Simple text generation
$response = AIEngine::engine('openai')
    ->model('gpt-4o')
    ->generate('Write a blog post about Laravel');

echo $response->content;
```

### With User and Credit Management

```php
$response = AIEngine::engine('openai')
    ->model('gpt-4o')
    ->forUser($user->id)
    ->generate('Explain quantum computing');

if ($response->isSuccess()) {
    echo "Content: " . $response->content;
    echo "Credits used: " . $response->creditsUsed;
} else {
    echo "Error: " . $response->error;
}
```

### Streaming Responses

```php
$stream = AIEngine::engine('anthropic')
    ->model('claude-3-5-sonnet')
    ->generateStream('Write a story about AI');

foreach ($stream as $chunk) {
    echo $chunk; // Output each chunk as it arrives
}
```

### Image Generation

```php
$response = AIEngine::engine('openai')
    ->model('dall-e-3')
    ->generateImage('A futuristic city at sunset', count: 2);

foreach ($response->files as $imageUrl) {
    echo "<img src='{$imageUrl}' alt='Generated image'>";
}
```

### Video Generation

```php
$response = AIEngine::engine('stable_diffusion')
    ->model('sd3-large')
    ->generateVideo('A cat playing piano in a jazz club');

echo "Video URL: " . $response->getFirstFile();
```

### Audio Processing

```php
// Text to speech
$response = AIEngine::engine('eleven_labs')
    ->model('eleven_multilingual_v2')
    ->generateAudio('Hello, this is a test of text to speech');

// Speech to text
$response = AIEngine::engine('openai')
    ->model('whisper-1')
    ->audioToText('/path/to/audio/file.mp3');

echo "Transcription: " . $response->content;
```

## Advanced Features

### Error Handling and Retry

```php
$response = AIEngine::engine('openai')
    ->model('gpt-4o')
    ->withRetry(maxAttempts: 3, backoff: 'exponential')
    ->fallbackTo('anthropic')
    ->generate('Complex prompt that might fail');
```

### Caching

```php
// Enable caching for this request
$response = AIEngine::engine('openai')
    ->model('gpt-4o')
    ->cache(enabled: true, ttl: 7200)
    ->generate('Expensive computation');

// Check if response was cached
if ($response->isCached()) {
    echo "This response was served from cache!";
}
```

### Rate Limiting

```php
// Check remaining requests
$remaining = AIEngine::engine('openai')->getRemainingRequests();
echo "Remaining requests: {$remaining}";

// The engine will automatically handle rate limiting
$response = AIEngine::engine('openai')
    ->model('gpt-4o')
    ->rateLimit(enabled: true)
    ->generate('Your prompt');
```

### Content Moderation

```php
$response = AIEngine::engine('openai')
    ->model('gpt-4o')
    ->withModeration(level: 'strict')
    ->generate('User-generated content');
```

### Templates

```php
// Define templates in config/ai-engine.php
'templates' => [
    'blog_post' => 'Write a {tone} blog post about {topic} targeting {audience}.',
    'email_subject' => 'Create an email subject line for {product} targeting {demographic}.',
],

// Use templates
$response = AIEngine::template('blog_post')
    ->with([
        'tone' => 'professional',
        'topic' => 'Laravel development',
        'audience' => 'developers'
    ])
    ->options(['engine' => 'anthropic', 'model' => 'claude-3-5-sonnet'])
    ->generate();
```

### Job Queue System

The package includes a comprehensive job queue system for processing AI requests asynchronously, handling batch operations, and managing long-running tasks with intelligent rate limiting.

### Background Processing

Queue individual AI requests for background processing:

```php
use MagicAI\LaravelAIEngine\Services\QueuedAIProcessor;

$processor = app(QueuedAIProcessor::class);

// Queue a simple AI request
$jobId = $processor->queueRequest(
    request: $aiRequest,
    callbackUrl: 'https://example.com/callback',
    queue: 'ai-processing',
    userId: 'user-123' // For user-specific rate limiting
);

echo "Job queued with ID: {$jobId}";
```

### Batch Processing

Process multiple AI requests efficiently in batches:

```php
// Queue a batch of requests
$batchId = $processor->queueBatch(
    requests: [$request1, $request2, $request3],
    callbackUrl: 'https://example.com/batch-callback',
    stopOnError: false, // Continue processing even if some requests fail
    queue: 'batch-processing',
    userId: 'user-123'
);

// Check batch status
$status = $processor->getJobStatus($batchId);
echo "Batch progress: {$status['progress_percentage']}%";
```

### Long-Running Tasks

Handle expensive operations like video generation with progress tracking:

```php
// Queue a long-running task
$jobId = $processor->queueLongRunningTask(
    request: $videoRequest,
    taskType: 'video_generation',
    callbackUrl: 'https://example.com/video-callback',
    progressCallbacks: ['https://example.com/progress'],
    queue: 'video-processing',
    userId: 'user-123'
);

// Monitor progress
$progress = $processor->getJobProgress($jobId);
echo "Progress: {$progress['percentage']}% - {$progress['message']}";
```

### Job Status Tracking

Monitor job status and progress in real-time:

```php
use MagicAI\LaravelAIEngine\Services\JobStatusTracker;

$tracker = app(JobStatusTracker::class);

// Get job status
$status = $tracker->getStatus($jobId);
echo "Status: {$status['status']}"; // queued, processing, completed, failed, rate_limited

// Check if job is running
if ($tracker->isRunning($jobId)) {
    $progress = $tracker->getProgress($jobId);
    echo "Progress: {$progress['percentage']}%";
}

// Get job statistics
$stats = $tracker->getStatistics($userId);
echo "Total jobs: {$stats['total_jobs']}";
echo "Completed: {$stats['completed_jobs']}";
```

## Intelligent Rate Limiting

The package includes sophisticated rate limiting that integrates seamlessly with the job queue system.

### Queue-Based Rate Limiting

Jobs automatically check rate limits before processing and are intelligently delayed when limits are exceeded:

```php
// Rate limiting is automatically applied to all queued jobs
$jobId = $processor->queueRequest($request, userId: 'user-123');

// If rate limited, the job will be automatically delayed and retried
// You can monitor this via job status
$status = $tracker->getStatus($jobId);
if ($status['status'] === 'rate_limited') {
    echo "Job delayed due to rate limiting";
    echo "Will retry at: {$status['retry_at']}";
}
```

### User-Specific Rate Limiting

Support for per-user rate limiting in multi-tenant applications:

```php
// Each user has their own rate limit counters
$jobId1 = $processor->queueRequest($request, userId: 'user-123');
$jobId2 = $processor->queueRequest($request, userId: 'user-456');

// Users don't affect each other's rate limits
```

### Batch Rate Limiting

Intelligent handling of rate limits in batch operations:

```php
// When processing batches, the system automatically splits requests
// Some requests process immediately, others are delayed
$batchId = $processor->queueBatch($requests, userId: 'user-123');

$status = $tracker->getStatus($batchId);
echo "Processable requests: {$status['processable_requests']}";
echo "Delayed requests: {$status['delayed_requests']}";
```

### Rate Limiting Configuration

Configure rate limiting behavior:

```php
// In config/ai-engine.php
'rate_limiting' => [
    'enabled' => true,
    'apply_to_jobs' => true, // Enable rate limiting for queued jobs
    'per_engine' => [
        'openai' => ['requests' => 100, 'per_minute' => 1],
        'anthropic' => ['requests' => 50, 'per_minute' => 1],
    ],
],
```

## Synchronous Batch Processing

For immediate batch processing (non-queued):

```php
$batch = AIEngine::batch()
    ->add('openai', 'gpt-4o', 'First prompt')
    ->add('anthropic', 'claude-3-5-sonnet', 'Second prompt')
    ->add('gemini', 'gemini-1.5-pro', 'Third prompt');

$results = $batch->process();

foreach ($results as $index => $response) {
    echo "Result {$index}: " . $response->content . "\n";
}
```

## Webhook Notifications

The package includes reliable webhook delivery with automatic retries and exponential backoff:

### Queued Webhook Delivery

```php
use MagicAI\LaravelAIEngine\Services\WebhookManager;

$webhookManager = app(WebhookManager::class);

// Send webhook notification with automatic retry
$webhookManager->sendWebhookQueued(
    url: 'https://example.com/webhook',
    data: [
        'job_id' => $jobId,
        'status' => 'completed',
        'result' => $response->toArray()
    ],
    maxRetries: 3,
    queue: 'webhooks'
);
```

### Webhook Configuration

Configure webhook behavior in your job callbacks:

```php
// When queueing jobs, provide callback URLs
$jobId = $processor->queueRequest(
    request: $aiRequest,
    callbackUrl: 'https://example.com/ai-callback',
    userId: 'user-123'
);

// The system will automatically send webhooks when jobs complete
```

## Database Setup

Run the migration to create the job status tracking table:

```bash
php artisan migrate
```

This creates the `ai_job_statuses` table for persistent job tracking with the following structure:

```php
Schema::create('ai_job_statuses', function (Blueprint $table) {
    $table->string('job_id')->primary();
    $table->string('user_id')->nullable()->index();
    $table->enum('status', ['queued', 'processing', 'completed', 'failed', 'rate_limited']);
    $table->json('data')->nullable();
    $table->integer('progress_percentage')->default(0);
    $table->string('progress_message')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
    
    $table->index(['user_id', 'status']);
    $table->index('created_at');
});
```

## Configuration Reference

### Environment Variables

```bash
# Core AI Engine
AI_DEFAULT_ENGINE=openai
AI_DEFAULT_MODEL=gpt-4o
AI_TIMEOUT=30

# OpenAI
OPENAI_API_KEY=your_openai_key

# Anthropic
ANTHROPIC_API_KEY=your_anthropic_key

# Google Gemini
GOOGLE_AI_API_KEY=your_google_key

# Credit System
AI_CREDITS_ENABLED=true
AI_DEFAULT_CREDITS=100.0

# Caching
AI_CACHE_ENABLED=true
AI_CACHE_TTL=3600

# Rate Limiting
AI_RATE_LIMITING_ENABLED=true
AI_RATE_LIMITING_APPLY_TO_JOBS=true
AI_RATE_LIMIT_DRIVER=redis

# Job Queue
QUEUE_CONNECTION=redis
```

### Advanced Configuration

```php
// config/ai-engine.php
return [
    'job_queue' => [
        'default_queue' => 'ai-processing',
        'long_running_queue' => 'ai-long-running',
        'webhook_queue' => 'webhooks',
        'status_cache_ttl' => 3600,
        'cleanup_completed_jobs_after' => 86400, // 24 hours
    ],
    
    'rate_limiting' => [
        'enabled' => env('AI_RATE_LIMITING_ENABLED', true),
        'apply_to_jobs' => env('AI_RATE_LIMITING_APPLY_TO_JOBS', true),
        'driver' => env('AI_RATE_LIMIT_DRIVER', 'cache'),
        'per_engine' => [
            'openai' => ['requests' => 100, 'per_minute' => 1],
            'anthropic' => ['requests' => 50, 'per_minute' => 1],
            'gemini' => ['requests' => 60, 'per_minute' => 1],
        ],
    ],
    
    'webhooks' => [
        'max_retries' => 3,
        'retry_delay' => 60, // seconds
        'timeout' => 30,
    ],
];
```

### Cost Estimation

```php
// Estimate cost before making request
$estimate = AIEngine::engine('openai')
    ->model('gpt-4o')
    ->estimateCost('Long prompt that will use many tokens...');

echo "Estimated credits: " . $estimate['credits'];

// Estimate cost for multiple operations
$estimate = AIEngine::estimateCost([
    ['engine' => 'openai', 'model' => 'gpt-4o', 'prompt' => 'Text generation'],
    ['engine' => 'openai', 'model' => 'dall-e-3', 'prompt' => 'Image generation', 'parameters' => ['image_count' => 2]],
]);

echo "Total estimated credits: " . $estimate['total_credits'];
```

## Credit Management

### User Credits

```php
use MagicAI\LaravelAIEngine\Services\CreditManager;

$creditManager = app(CreditManager::class);

// Check user credits
$credits = $creditManager->getUserCredits($userId, EngineEnum::OPENAI, EntityEnum::GPT_4O);
echo "Balance: " . $credits['balance'];

// Add credits
$creditManager->addCredits($userId, EngineEnum::OPENAI, EntityEnum::GPT_4O, 100.0);

// Set unlimited credits
$creditManager->setUnlimitedCredits($userId, EngineEnum::OPENAI, EntityEnum::GPT_4O);

// Check if user has low credits
if ($creditManager->hasLowCredits($userId)) {
    // Send low credit notification
}
```

### Usage Statistics

```php
$stats = $creditManager->getUsageStats($userId);
echo "Total requests: " . $stats['total_requests'];
echo "Total credits used: " . $stats['total_credits_used'];
```

## Analytics

```php
use MagicAI\LaravelAIEngine\Services\AnalyticsManager;

$analytics = app(AnalyticsManager::class);

// Get usage statistics
$stats = $analytics->getUsageStats([
    'user_id' => $userId,
    'engine' => 'openai',
    'from_date' => now()->subDays(30),
]);

// Get cost analysis
$costs = $analytics->getCostAnalysis([
    'user_id' => $userId,
    'from_date' => now()->subMonth(),
]);

// Get performance metrics
$performance = $analytics->getPerformanceMetrics([
    'engine' => 'openai',
    'from_date' => now()->subWeek(),
]);
```

## Artisan Commands

```bash
# Test all configured engines
php artisan ai:test-engines

# Sync latest models from providers
php artisan ai:sync-models

# Generate usage report
php artisan ai:usage-report --user=123 --days=30

# Clear AI response cache
php artisan ai:clear-cache --engine=openai
```

## Events

The package dispatches several events you can listen to:

```php
// Listen for AI request completion
Event::listen(\MagicAI\LaravelAIEngine\Events\AIRequestCompleted::class, function ($event) {
    // Handle completion
    Log::info('AI request completed', [
        'user_id' => $event->request->userId,
        'engine' => $event->request->engine->value,
        'credits_used' => $event->response->creditsUsed,
    ]);
});

// Listen for errors
Event::listen(\MagicAI\LaravelAIEngine\Events\AIRequestFailed::class, function ($event) {
    // Handle error
    Log::error('AI request failed', [
        'error' => $event->exception->getMessage(),
        'engine' => $event->request->engine->value,
    ]);
});
```

## Extending the Package

### Adding Custom Engines

1. Create a new engine driver:

```php
use MagicAI\LaravelAIEngine\Drivers\BaseEngineDriver;

class CustomEngineDriver extends BaseEngineDriver
{
    public function generateText(AIRequest $request): AIResponse
    {
        // Implement your custom engine logic
    }
    
    // Implement other required methods...
}
```

2. Add to the EngineEnum:

```php
case CUSTOM_ENGINE = 'custom_engine';

public function driverClass(): string
{
    return match ($this) {
        // ... existing cases
        self::CUSTOM_ENGINE => CustomEngineDriver::class,
    };
}
```

### Custom Content Filters

```php
AIEngine::addContentFilter(function($input, $output) {
    // Return false to block the content
    return !str_contains($output, 'inappropriate_content');
});
```

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email security@magicai.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.
