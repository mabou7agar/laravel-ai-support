# Laravel AI Engine

[![Latest Version](https://img.shields.io/packagist/v/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![Total Downloads](https://img.shields.io/packagist/dt/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![License](https://img.shields.io/packagist/l/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![PHP Version](https://img.shields.io/packagist/php-v/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)

A comprehensive Laravel package for multi-AI engine integration with advanced job queue processing, intelligent rate limiting, credit management, streaming, analytics, and enterprise-grade features.

**✨ Fully compatible with Laravel 9, 10, 11, and 12**

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Publishing Assets](#publishing-assets)
  - [Run Migrations](#run-migrations)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Usage Examples](#usage-examples)
  - [Basic Text Generation](#basic-text-generation)
  - [Streaming Responses](#streaming-responses)
  - [Conversation Memory](#conversation-memory)
  - [Job Queue Processing](#job-queue-processing)
  - [Batch Operations](#batch-operations)
  - [Interactive Actions](#interactive-actions)
- [Advanced Features](#advanced-features)
  - [Failover System](#failover-system)
  - [WebSocket Streaming](#websocket-streaming)
  - [Analytics & Monitoring](#analytics--monitoring)
- [Version Compatibility](#version-compatibility)
- [Troubleshooting](#troubleshooting)
- [Performance Optimization](#performance-optimization)
- [Artisan Commands](#artisan-commands)
- [Testing](#testing)
- [Security Best Practices](#security-best-practices)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)

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

### 🧠 **Conversation Memory**
- **Persistent Conversations**: Store and manage conversation history across sessions
- **Context-Aware Responses**: AI responses that remember previous conversation context
- **Message Management**: User and assistant message tracking with metadata
- **Conversation Settings**: Configurable conversation parameters (max messages, temperature, etc.)
- **Auto-Title Generation**: Automatic conversation title generation from first user message
- **Message Trimming**: Automatic conversation history trimming to stay within limits

### 💰 **Enterprise Features**
- **Credit System**: Built-in usage tracking and billing management
- **Interactive Actions**: Buttons, forms, quick replies, and other interactive elements in AI responses
- **Automatic Failover**: Circuit breaker pattern with automatic provider switching for reliability
- **WebSocket Streaming**: Real-time AI response streaming with WebSocket support
- **Advanced Analytics**: Comprehensive usage monitoring, cost tracking, and performance analytics
- **Memory Storage**: Multiple storage drivers (Redis, Database, File, MongoDB) for conversation persistence
- **Event System**: Real-time events and listeners for streaming, failover, and analytics
- **Console Commands**: Management commands for monitoring, health checks, and system administration
- **Retry & Fallback**: Automatic retry mechanisms with fallback engines
- **Caching**: Response caching to reduce costs and improve performance
- **Error Handling**: Robust error handling and logging
- **Laravel Integration**: Seamless Laravel integration with Artisan commands

## Requirements

- PHP 8.0 or higher
- Laravel 9.x, 10.x, 11.x, or 12.x
- OpenAI PHP Client ^0.8, ^0.9, or ^0.10

## Installation

Install the package via Composer:

```bash
composer require m-tech-stack/laravel-ai-engine
```

### Publishing Assets

The package provides several publishable resources. You can publish them individually or all at once.

#### Publish All Resources

```bash
php artisan vendor:publish --provider="LaravelAIEngine\AIEngineServiceProvider"
```

#### Publish Individual Resources

**Configuration File** (Required)
```bash
php artisan vendor:publish --tag=ai-engine-config
```
This publishes `config/ai-engine.php` with all configuration options.

**Database Migrations** (Required)
```bash
php artisan vendor:publish --tag=ai-engine-migrations
```
This publishes migration files for credits, conversations, and analytics tables.

**Views** (Optional - for customization)
```bash
php artisan vendor:publish --tag=ai-engine-views
```
This publishes Blade views to `resources/views/vendor/ai-engine/` for customization.

**JavaScript Assets** (Optional - for interactive chat)
```bash
php artisan vendor:publish --tag=ai-engine-assets
```
This publishes JavaScript files to `public/vendor/ai-engine/js/` for the interactive chat component.

### Run Migrations

After publishing the configuration and migrations:

```bash
php artisan migrate
```

This will create the following tables:
- `ai_credits` - User credit management
- `ai_conversations` - Conversation storage
- `ai_conversation_messages` - Message history
- `ai_requests` - Request analytics
- `ai_errors` - Error tracking

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

### Unified Engine Facade (Bupple-Style API)

The unified `Engine` facade provides a simple, elegant API inspired by Bupple's Laravel AI Engine:

```php
use LaravelAIEngine\Facades\Engine;

// Simple chat completion
$response = Engine::send([
    ['role' => 'user', 'content' => 'Hello, how are you?']
]);

echo $response->content; // "Hello! I'm doing well, thank you for asking..."

// Stream responses in real-time
$stream = Engine::stream([
    ['role' => 'user', 'content' => 'Tell me a story about Laravel']
]);

foreach ($stream as $chunk) {
    echo $chunk; // Output each chunk as it arrives
}
```

### Fluent Engine Configuration

```php
// Configure engine, model, and parameters with method chaining
$response = Engine::engine('openai')
    ->model('gpt-4o')
    ->temperature(0.8)
    ->maxTokens(1000)
    ->user('user-123')
    ->send([
        ['role' => 'system', 'content' => 'You are a helpful assistant'],
        ['role' => 'user', 'content' => 'Explain quantum computing']
    ]);

// Stream with configuration
$stream = Engine::engine('anthropic')
    ->model('claude-3-5-sonnet-20240620')
    ->temperature(0.7)
    ->stream([
        ['role' => 'user', 'content' => 'Write a poem about AI']
    ]);
```

### Memory Management (Bupple-Style)

```php
// Add messages to conversation memory
Engine::memory()
    ->conversation('conv-123')
    ->addUserMessage('Hello!')
    ->addAssistantMessage('Hi there! How can I help you?');

// Get conversation context
$messages = Engine::memory()
    ->conversation('conv-123')
    ->getMessages();

// Set parent context like Bupple
Engine::memory()
    ->conversation('conv-123')
    ->setParent('conversation', 'parent-conv-456');

// Send with automatic conversation context
$response = Engine::conversation('conv-123')
    ->send([
        ['role' => 'user', 'content' => 'What did we discuss earlier?']
    ]);
```

### Multiple Memory Storage Drivers

```php
// Use Redis for high-performance storage
Engine::memory('redis')
    ->conversation('conv-123')
    ->addUserMessage('Hello from Redis!');

// Use file storage for simple persistence
Engine::memory('file')
    ->conversation('conv-456')
    ->addUserMessage('Hello from file storage!');

// Use database storage (default)
Engine::memory('database')
    ->conversation('conv-789')
    ->addUserMessage('Hello from database!');
```

### Advanced Memory Operations

```php
// Create conversation with metadata
Engine::memory()
    ->createConversation('conv-123', [
        'title' => 'Customer Support Chat',
        'user_id' => 'user-456',
        'metadata' => ['department' => 'support']
    ]);

// Get conversation statistics
$stats = Engine::memory()
    ->conversation('conv-123')
    ->getStats();

// Clear conversation history
Engine::memory()
    ->conversation('conv-123')
    ->clear();

// Check if conversation exists
$exists = Engine::memory()
    ->conversation('conv-123')
    ->exists();
```

### Interactive Actions

Add interactive buttons, forms, and other UI elements to AI responses:

```php
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\DTOs\InteractiveAction;

// Create interactive buttons
$actions = [
    InteractiveAction::button('approve', 'Approve Request', [
        'action' => ['type' => 'callback', 'callback' => 'handleApproval']
    ]),
    InteractiveAction::button('reject', 'Reject Request', [
        'action' => ['type' => 'callback', 'callback' => 'handleRejection']
    ], 'Reject this request', ['variant' => 'danger'])
];

// Send response with actions
$response = Engine::send([
    ['role' => 'user', 'content' => 'Please review this request']
]);

$responseWithActions = $response->withActions($actions);

// Create quick reply actions
$quickReplies = [
    InteractiveAction::quickReply('yes', 'Yes', 'Yes, I agree'),
    InteractiveAction::quickReply('no', 'No', 'No, I disagree'),
    InteractiveAction::quickReply('maybe', 'Maybe', 'I need more information')
];

// Create form action
$formAction = InteractiveAction::form('feedback', 'Submit Feedback', [
    ['name' => 'rating', 'type' => 'select', 'label' => 'Rating', 'options' => [1,2,3,4,5]],
    ['name' => 'comment', 'type' => 'textarea', 'label' => 'Comment', 'required' => true]
]);

// Create link action
$linkAction = InteractiveAction::link('docs', 'View Documentation', 
    'https://docs.example.com', 'Learn more about this feature', true);

// Create file upload action
$uploadAction = InteractiveAction::fileUpload('upload', 'Upload File', 
    ['image/*', 'application/pdf'], 5242880, false, 'Upload supporting documents');
```

### Advanced Interactive Actions

```php
// Create card with embedded actions
$cardAction = InteractiveAction::card('product', 'Product Recommendation', 
    'Based on your preferences, we recommend this product.', 
    'https://example.com/product-image.jpg',
    [
        InteractiveAction::button('buy', 'Buy Now', [
            'action' => ['type' => 'url', 'url' => 'https://store.example.com/buy']
        ]),
        InteractiveAction::button('details', 'View Details', [
            'action' => ['type' => 'callback', 'callback' => 'showProductDetails']
        ])
    ]
);

// Create confirmation action
$confirmAction = InteractiveAction::confirm('delete', 'Delete Item', 
    'Are you sure you want to delete this item? This action cannot be undone.',
    ['item_id' => 123]
);

// Create menu/dropdown action
$menuAction = InteractiveAction::menu('category', 'Select Category', [
    ['value' => 'tech', 'label' => 'Technology'],
    ['value' => 'business', 'label' => 'Business'],
    ['value' => 'health', 'label' => 'Health']
]);
```

### Executing Interactive Actions

```php
// Execute an action when user interacts
$action = Engine::createAction([
    'id' => 'approve_request',
    'type' => 'button',
    'label' => 'Approve',
    'data' => [
        'action' => [
            'type' => 'callback',
            'callback' => 'handleApproval'
        ]
    ]
]);

$response = Engine::executeAction($action, [
    'request_id' => 123,
    'user_id' => 456
]);

if ($response->success) {
    echo "Action executed successfully: " . $response->message;
} else {
    echo "Action failed: " . $response->message;
}

// Validate action before execution
$errors = Engine::validateAction($action, ['request_id' => 123]);
if (empty($errors)) {
    $response = Engine::executeAction($action, ['request_id' => 123]);
}

// Get supported action types
$supportedTypes = Engine::getSupportedActionTypes();
// Returns: ['button', 'quick_reply', 'form', 'link', 'file_upload', etc.]
```

### Action Event Handling

```php
// Listen for action events in your EventServiceProvider
Event::listen('ai.action.button.clicked', function ($data) {
    $action = $data['action'];
    $payload = $data['payload'];
    
    // Handle button click
    Log::info('Button clicked', ['action_id' => $action->id]);
});

Event::listen('ai.action.form.submit', function ($data) {
    $formId = $data['form_id'];
    $payload = $data['payload'];
    
    // Handle form submission
    // Process form data...
});

Event::listen('ai.action.callback', function ($data) {
    $callback = $data['callback'];
    $action = $data['action'];
    $payload = $data['payload'];
    
    // Handle custom callback
    if ($callback === 'handleApproval') {
        // Process approval logic
        return ['status' => 'approved', 'timestamp' => now()];
    }
});
```

### Custom Action Handlers

```php
use LaravelAIEngine\Contracts\ActionHandlerInterface;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\DTOs\ActionResponse;

class CustomActionHandler implements ActionHandlerInterface
{
    public function handle(InteractiveAction $action, array $payload = []): ActionResponse
    {
        // Custom action handling logic
        return ActionResponse::success(
            $action->id,
            $action->type,
            'Custom action executed successfully',
            $payload
        );
    }

    public function validate(InteractiveAction $action, array $payload = []): array
    {
        // Custom validation logic
        return [];
    }

    public function supports(string $actionType): bool
    {
        return $actionType === 'custom';
    }

    public function priority(): int
    {
        return 50;
    }
}

// Register custom handler
app(ActionManager::class)->registerHandler(new CustomActionHandler());
```

### Basic Text Generation (Traditional API)

```php
use LaravelAIEngine\Facades\AIEngine;

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
use LaravelAIEngine\Services\QueuedAIProcessor;

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
use LaravelAIEngine\Services\JobStatusTracker;

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

## OpenRouter Fallback Integration

The package includes comprehensive OpenRouter support as a fallback engine, providing access to multiple AI models through a unified API with competitive pricing and high availability.

### Using OpenRouter as Fallback

OpenRouter serves as an excellent fallback engine because it provides access to multiple AI providers (OpenAI, Anthropic, Google, Meta, Mistral, etc.) through a single API:

```php
use LaravelAIEngine\Facades\AIEngine;

// Configure OpenRouter as fallback for primary engines
$response = AIEngine::engine('openai')
    ->model('gpt-4o')
    ->fallbackTo('openrouter') // Will use OpenRouter if OpenAI fails
    ->withRetry(3)
    ->generate('Your prompt here');
```

### OpenRouter Configuration

Add OpenRouter configuration to your `.env` file:

```bash
# OpenRouter API Configuration
OPENROUTER_API_KEY=your_openrouter_api_key
OPENROUTER_SITE_URL=https://yourapp.com
OPENROUTER_SITE_NAME="Your App Name"
```

### Available OpenRouter Models

OpenRouter provides access to popular models from multiple providers:

```php
// Use latest GPT-5 models through OpenRouter (August 2025)
$response = AIEngine::engine('openrouter')
    ->model('openai/gpt-5') // GPT-5 - Latest generation AI model
    ->generate('Your prompt');

$response = AIEngine::engine('openrouter')
    ->model('openai/gpt-5-mini') // GPT-5 Mini - Efficient latest model
    ->generate('Your prompt');

// Latest Gemini 2.5 models with thinking capabilities (March 2025)
$response = AIEngine::engine('openrouter')
    ->model('google/gemini-2.5-pro') // Gemini 2.5 Pro - Most intelligent AI with thinking
    ->generate('Your prompt');

$response = AIEngine::engine('openrouter')
    ->model('google/gemini-2.5-pro-experimental') // Gemini 2.5 Pro Experimental
    ->generate('Your prompt');

// Claude 4 models - World's best coding models
$response = AIEngine::engine('openrouter')
    ->model('anthropic/claude-4-opus') // Claude 4 Opus - World's best coding model
    ->generate('Your prompt');

$response = AIEngine::engine('openrouter')
    ->model('anthropic/claude-4-sonnet') // Claude 4 Sonnet - Advanced reasoning
    ->generate('Your prompt');

// Free Models - Perfect for testing and cost-effective usage
$response = AIEngine::engine('openrouter')
    ->model('meta-llama/llama-3.1-8b-instruct:free') // Llama 3.1 8B - Free tier
    ->generate('Your prompt');

$response = AIEngine::engine('openrouter')
    ->model('google/gemma-2-9b-it:free') // Gemma 2 9B - Free tier
    ->generate('Your prompt');

$response = AIEngine::engine('openrouter')
    ->model('mistralai/mistral-7b-instruct:free') // Mistral 7B - Free tier
    ->generate('Your prompt');
```

### Automatic Fallback Configuration

The package automatically configures OpenRouter as the primary fallback for all engines:

```php
// In config/ai-engine.php - automatically configured
'error_handling' => [
    'fallback_engines' => [
        'openai' => ['openrouter', 'anthropic', 'gemini'],
        'anthropic' => ['openrouter', 'openai', 'gemini'],
        'gemini' => ['openrouter', 'openai', 'anthropic'],
        'openrouter' => ['openai', 'anthropic', 'gemini'],
    ],
],
```

### Queue-Based Fallback

OpenRouter fallback works seamlessly with the job queue system:

```php
use LaravelAIEngine\Services\QueuedAIProcessor;

$processor = app(QueuedAIProcessor::class);

// If primary engine fails, jobs automatically fallback to OpenRouter
$jobId = $processor->queueRequest(
    request: $aiRequest,
    callbackUrl: 'https://example.com/callback',
    userId: 'user-123'
);

// Monitor fallback usage in job status
$status = $processor->getJobStatus($jobId);
if (isset($status['fallback_used'])) {
    echo "Used fallback engine: {$status['fallback_engine']}";
}
```

### Cost-Effective Fallback Strategy

OpenRouter often provides competitive pricing and can serve as a cost-effective fallback:

```php
// OpenRouter models with competitive pricing (2025 latest models)
$models = [
    // Free Models (Perfect for Testing & Development)
    'meta-llama/llama-3.1-8b-instruct:free' => 0.0, // Llama 3.1 8B - Free
    'meta-llama/llama-3.2-3b-instruct:free' => 0.0, // Llama 3.2 3B - Free
    'google/gemma-2-9b-it:free' => 0.0, // Gemma 2 9B - Free
    'mistralai/mistral-7b-instruct:free' => 0.0, // Mistral 7B - Free
    'qwen/qwen-2.5-7b-instruct:free' => 0.0, // Qwen 2.5 7B - Free
    'microsoft/phi-3-mini-128k-instruct:free' => 0.0, // Phi-3 Mini - Free
    'openchat/openchat-3.5-1210:free' => 0.0, // OpenChat 3.5 - Free
    
    // GPT-5 Models (Latest Generation - August 2025)
    'openai/gpt-5' => 5.0, // Premium GPT-5 - Latest generation
    'openai/gpt-5-mini' => 2.5, // GPT-5 Mini - Efficient latest model
    'openai/gpt-5-nano' => 1.0, // GPT-5 Nano - Ultra-efficient
    
    // Gemini 2.5 Models (Latest Generation - March 2025)
    'google/gemini-2.5-pro' => 3.0, // Most intelligent AI with thinking
    'google/gemini-2.5-pro-experimental' => 3.2, // Experimental version
    
    // Claude 4 Models (Latest Generation)
    'anthropic/claude-4-opus' => 4.5, // Premium Claude 4 - World's best coding
    'anthropic/claude-4-sonnet' => 3.5, // Claude 4 Sonnet - Advanced reasoning
    
    // Previous Generation Models (Still Competitive)
    'openai/gpt-4o-2024-11-20' => 2.3, // Latest GPT-4o version
    'anthropic/claude-3.5-sonnet-20241022' => 2.1, // Latest Claude 3.5 Sonnet
    'google/gemini-2.0-flash' => 1.9, // Previous Gemini generation
    'meta-llama/llama-3.3-70b-instruct' => 1.3, // Latest Llama 3.3
    'deepseek/deepseek-r1' => 0.4, // Latest DeepSeek R1
];
```

### Benefits of OpenRouter as Fallback

- **High Availability**: Access to multiple providers reduces downtime
- **Model Diversity**: Access to models not available through direct APIs
- **Competitive Pricing**: Often lower costs than direct API access
- **Unified Interface**: Single API for multiple providers
- **Rate Limit Resilience**: Higher rate limits through unified API
- **Geographic Availability**: Better global coverage

## Conversation Memory

The package provides comprehensive conversation memory support for building chat applications and maintaining context across AI interactions.

### Creating and Managing Conversations

```php
use LaravelAIEngine\Services\ConversationManager;

$conversationManager = app(ConversationManager::class);

// Create a new conversation
$conversation = $conversationManager->createConversation(
    userId: 'user-123',
    title: 'Customer Support Chat',
    systemPrompt: 'You are a helpful customer support assistant.',
    settings: [
        'max_messages' => 100,
        'temperature' => 0.7,
        'auto_title' => true
    ]
);

// Get conversation by ID
$conversation = $conversationManager->getConversation($conversationId);

// Get all conversations for a user
$conversations = $conversationManager->getUserConversations('user-123');
```

### Context-Aware AI Generation

```php
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

$aiEngine = app(AIEngineService::class);

// Generate response with conversation context
$response = $aiEngine->generateWithConversation(
    message: 'What was my previous question?',
    conversationId: $conversation->conversation_id,
    engine: EngineEnum::OPENAI,
    model: EntityEnum::GPT_4O,
    userId: 'user-123'
);

// The AI will have access to the full conversation history
echo $response->content; // "Your previous question was about..."
```

### Manual Message Management

```php
// Add user message
$userMessage = $conversationManager->addUserMessage(
    $conversationId,
    'Hello, I need help with my order',
    ['metadata' => 'support_ticket_123']
);

// Add assistant message with AI response
$assistantMessage = $conversationManager->addAssistantMessage(
    $conversationId,
    'I\'d be happy to help with your order!',
    $aiResponse
);
```

### Conversation Settings and Management

```php
// Update conversation settings
$conversationManager->updateConversationSettings($conversationId, [
    'max_messages' => 200,
    'temperature' => 0.8,
    'auto_title' => false
]);

// Get conversation statistics
$stats = $conversationManager->getConversationStats($conversationId);
// Returns: total_messages, user_messages, assistant_messages, created_at, last_activity

// Clear conversation history
$conversationManager->clearConversationHistory($conversationId);

// Delete conversation (marks as inactive)
$conversationManager->deleteConversation($conversationId);
```

### Advanced Conversation Features

#### Auto-Title Generation
```php
// Conversations with auto_title enabled automatically generate titles
$conversation = $conversationManager->createConversation(
    userId: 'user-123',
    settings: ['auto_title' => true]
);

// After first user message, title is auto-generated
$conversationManager->addUserMessage($conversationId, 'What is machine learning?');
// Title becomes: "What is machine learning?"
```

#### Message Trimming
```php
// Conversations automatically trim old messages when limit is reached
$conversation = $conversationManager->createConversation(
    userId: 'user-123',
    settings: ['max_messages' => 50]
);

// When 51st message is added, oldest message is automatically deleted
```

#### Context Retrieval
```php
// Get conversation context for AI requests
$context = $conversationManager->getConversationContext($conversationId);
// Returns array of messages in format: [{'role': 'user', 'content': '...'}, ...]

// Enhanced AI request with context
$enhancedRequest = $conversationManager->enhanceRequestWithContext(
    $baseRequest,
    $conversationId
);
```

### Database Schema

The conversation memory system uses two main tables:

#### ai_conversations
- `conversation_id` (string, unique identifier)
- `user_id` (string, user identifier)
- `title` (string, nullable, conversation title)
- `system_prompt` (text, nullable, system instructions)
- `settings` (json, conversation configuration)
- `is_active` (boolean, soft deletion flag)
- `last_activity_at` (timestamp, last message time)

#### ai_messages
- `conversation_id` (string, foreign key)
- `role` (enum: user, assistant, system)
- `content` (text, message content)
- `metadata` (json, additional message data)
- `tokens_used` (integer, nullable, token count)
- `credits_used` (decimal, nullable, credit cost)
- `sent_at` (timestamp, message timestamp)

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
use LaravelAIEngine\Services\WebhookManager;

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

# OpenRouter
OPENROUTER_API_KEY=your_openrouter_key
OPENROUTER_SITE_URL=https://yourapp.com
OPENROUTER_SITE_NAME="Your App Name"

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
use LaravelAIEngine\Services\CreditManager;

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
use LaravelAIEngine\Services\AnalyticsManager;

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
Event::listen(\LaravelAIEngine\Events\AIRequestCompleted::class, function ($event) {
    // Handle completion
    Log::info('AI request completed', [
        'user_id' => $event->request->userId,
        'engine' => $event->request->engine->value,
        'credits_used' => $event->response->creditsUsed,
    ]);
});

// Listen for errors
Event::listen(\LaravelAIEngine\Events\AIRequestFailed::class, function ($event) {
    // Handle error
    Log::error('AI request failed', [
        'error' => $event->exception->getMessage(),
        'engine' => $event->request->engine->value,
    ]);
});
```

## Enterprise Features

### 🎯 Interactive Actions

Add interactive elements to AI responses for enhanced user engagement:

```php
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;

// Generate AI response with interactive actions
$response = Engine::send('What would you like to do next?');

// Add interactive buttons
$response->addAction(new InteractiveAction(
    id: 'continue_chat',
    type: ActionTypeEnum::BUTTON,
    label: 'Continue Conversation',
    data: ['action' => 'continue']
));

$response->addAction(new InteractiveAction(
    id: 'new_topic',
    type: ActionTypeEnum::BUTTON,
    label: 'New Topic',
    data: ['action' => 'new_topic']
));

// Add quick reply options
$response->addAction(new InteractiveAction(
    id: 'quick_reply',
    type: ActionTypeEnum::QUICK_REPLY,
    label: 'Yes, please!',
    data: ['reply' => 'yes']
));

// Execute action when triggered
$actionResponse = Engine::executeAction($action, $payload);
```

### 🔄 Automatic Failover

Ensure high availability with automatic provider failover:

```php
use LaravelAIEngine\Facades\Engine;

// Execute with automatic failover
$response = Engine::executeWithFailover(
    callback: fn($provider) => Engine::engine($provider)->send('Hello world'),
    providers: ['openai', 'anthropic', 'gemini'],
    strategy: 'priority', // or 'round_robin'
    options: ['timeout' => 30]
);

// Check provider health
$health = Engine::getProviderHealth('openai');
// Returns: ['status' => 'healthy', 'failure_count' => 0, 'last_check' => '...']

// Get system health overview
$systemHealth = Engine::getSystemHealth();
```

### 🌊 WebSocket Streaming

Real-time AI response streaming with WebSocket support:

```php
use LaravelAIEngine\Facades\Engine;

// Start streaming server
Engine::streamResponse(
    sessionId: 'user-session-123',
    generator: function() {
        yield 'Hello ';
        yield 'world ';
        yield 'from AI!';
    },
    options: ['chunk_size' => 10]
);

// Stream with interactive actions
Engine::streamWithActions(
    sessionId: 'user-session-123',
    generator: $responseGenerator,
    actions: [
        ['type' => 'button', 'label' => 'Continue', 'action' => 'continue']
    ]
);

// Get streaming statistics
$stats = Engine::getStreamingStats();
```

### 📊 Advanced Analytics

Comprehensive usage monitoring and analytics:

```php
use LaravelAIEngine\Facades\Engine;

// Track custom events
Engine::trackRequest([
    'engine' => 'openai',
    'model' => 'gpt-4o',
    'tokens' => 150,
    'cost' => 0.003,
    'user_id' => auth()->id()
]);

// Get dashboard data
$dashboard = Engine::getDashboardData([
    'date_from' => '2024-01-01',
    'date_to' => '2024-01-31',
    'engine' => 'openai'
]);

// Get real-time metrics
$metrics = Engine::getRealTimeMetrics();

// Generate reports
$report = Engine::generateReport([
    'type' => 'monthly',
    'format' => 'json',
    'include_charts' => true
]);
```

### 🧠 Memory Storage Drivers

Multiple storage options for conversation persistence:

```php
// Configure in config/ai-engine.php
'memory' => [
    'driver' => 'redis', // redis, database, file, mongodb
    
    'drivers' => [
        'redis' => [
            'connection' => 'default',
            'prefix' => 'ai_memory:',
            'ttl' => 3600,
        ],
        
        'mongodb' => [
            'connection_string' => env('AI_MEMORY_MONGODB_CONNECTION'),
            'database' => env('AI_MEMORY_MONGODB_DATABASE', 'ai_engine'),
            'max_messages' => 1000,
        ],
        
        'database' => [
            'table' => 'ai_conversations',
            'max_messages' => 100,
        ],
    ],
],
```

### 📡 Event System

Real-time events for streaming, failover, and analytics:

```php
use LaravelAIEngine\Events\AIResponseChunk;
use LaravelAIEngine\Events\AIFailoverTriggered;

// Listen to streaming events
Event::listen(AIResponseChunk::class, function ($event) {
    // Handle streaming chunk
    broadcast(new StreamingUpdate($event->sessionId, $event->chunk));
});

// Listen to failover events
Event::listen(AIFailoverTriggered::class, function ($event) {
    // Send alert when failover occurs
    Log::warning("Provider failover: {$event->fromProvider} → {$event->toProvider}");
});
```

## Console Commands

### Analytics Reports

```bash
# Generate analytics report
php artisan ai-engine:analytics-report --period=monthly --format=table

# Export report to file
php artisan ai-engine:analytics-report --export=/path/to/report.json

# Filter by engine
php artisan ai-engine:analytics-report --engine=openai
```

### Failover Management

```bash
# Check failover status
php artisan ai-engine:failover-status

# Check specific provider
php artisan ai-engine:failover-status --provider=openai

# Reset provider health
php artisan ai-engine:failover-status --reset=openai
```

### Streaming Server

```bash
# Start WebSocket server
php artisan ai-engine:streaming-server start --host=0.0.0.0 --port=8080

# Check server status
php artisan ai-engine:streaming-server status

# Get server statistics
php artisan ai-engine:streaming-server stats

# Stop server
php artisan ai-engine:streaming-server stop
```

### System Health

```bash
# Check overall system health
php artisan ai-engine:system-health

# Detailed health information
php artisan ai-engine:system-health --detailed

# JSON output
php artisan ai-engine:system-health --format=json
```

## Configuration

### Enterprise Features Configuration

```php
// config/ai-engine.php

// Automatic Failover
'failover' => [
    'enabled' => env('AI_FAILOVER_ENABLED', true),
    'strategy' => env('AI_FAILOVER_STRATEGY', 'priority'),
    'circuit_breaker' => [
        'failure_threshold' => env('AI_FAILOVER_FAILURE_THRESHOLD', 5),
        'timeout' => env('AI_FAILOVER_TIMEOUT', 60),
        'retry_timeout' => env('AI_FAILOVER_RETRY_TIMEOUT', 300),
    ],
],

// WebSocket Streaming
'streaming' => [
    'enabled' => env('AI_STREAMING_ENABLED', true),
    'websocket' => [
        'host' => env('AI_WEBSOCKET_HOST', '0.0.0.0'),
        'port' => env('AI_WEBSOCKET_PORT', 8080),
        'max_connections' => env('AI_WEBSOCKET_MAX_CONNECTIONS', 1000),
    ],
],

// Analytics
'analytics' => [
    'enabled' => env('AI_ANALYTICS_ENABLED', true),
    'driver' => env('AI_ANALYTICS_DRIVER', 'database'),
    'retention_days' => env('AI_ANALYTICS_RETENTION_DAYS', 90),
    'real_time_metrics' => env('AI_ANALYTICS_REAL_TIME', true),
],

// Interactive Actions
'actions' => [
    'enabled' => env('AI_ACTIONS_ENABLED', true),
    'max_actions_per_response' => env('AI_ACTIONS_MAX_PER_RESPONSE', 10),
    'validation' => [
        'strict_mode' => env('AI_ACTIONS_STRICT_VALIDATION', true),
    ],
],
```

## Extending the Package

### Adding Custom Engines

1. Create a new engine driver:

```php
use LaravelAIEngine\Drivers\BaseEngineDriver;

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

### Custom Action Handlers

```php
use LaravelAIEngine\Services\Actions\Contracts\ActionHandlerInterface;

class CustomActionHandler implements ActionHandlerInterface
{
    public function handle(InteractiveAction $action, array $payload): ActionResponse
    {
        // Implement custom action logic
        return new ActionResponse(
            success: true,
            data: ['result' => 'Custom action executed'],
            message: 'Action completed successfully'
        );
    }
    
    public function supports(string $actionType): bool
    {
        return $actionType === 'custom_action';
    }
}

// Register in service provider
Engine::registerActionHandler(new CustomActionHandler());
```

### Custom Analytics Drivers

```php
use LaravelAIEngine\Services\Analytics\Contracts\AnalyticsDriverInterface;

class CustomAnalyticsDriver implements AnalyticsDriverInterface
{
    public function track(string $type, array $data): bool
    {
        // Implement custom tracking logic
    }
    
    public function query(string $type, array $filters = []): array
    {
        // Implement custom querying logic
    }
}
```

## Version Compatibility

| Laravel Version | PHP Version | Package Version | OpenAI Client |
|----------------|-------------|-----------------|---------------|
| 12.x | 8.2+ | 2.1.1+ | ^0.8\|^0.9\|^0.10 |
| 11.x | 8.2+ | 2.1.1+ | ^0.8\|^0.9\|^0.10 |
| 10.x | 8.1+ | 2.1.1+ | ^0.8\|^0.9\|^0.10 |
| 9.x | 8.0+ | 2.1.1+ | ^0.8\|^0.9\|^0.10 |

### Laravel 9 Support

This package fully supports Laravel 9 with backward-compatible features. See [README-LARAVEL9.md](README-LARAVEL9.md) for Laravel 9 specific documentation.

## Troubleshooting

### Common Issues

#### Issue: "Too few arguments to function AIEngineManager::__construct()"

**Solution**: Update to version 2.1.1+ which includes proper dependency injection fixes.

```bash
composer update m-tech-stack/laravel-ai-engine
php artisan config:clear
php artisan cache:clear
```

#### Issue: "No publishable resources for tag [ai-engine-views]"

**Solution**: Update to version 2.1.1+ which includes views publishing configuration.

```bash
composer update m-tech-stack/laravel-ai-engine
php artisan vendor:publish --tag=ai-engine-views
```

#### Issue: "Class 'OpenAI\Client' not found"

**Solution**: Ensure OpenAI PHP client is installed:

```bash
composer require openai-php/client
```

#### Issue: Rate Limiting Not Working

**Solution**: Ensure rate limiting is enabled in config and cache driver is configured:

```env
AI_RATE_LIMITING_ENABLED=true
CACHE_DRIVER=redis  # or database
```

#### Issue: Conversation Memory Not Persisting

**Solution**: Run migrations and check memory driver configuration:

```bash
php artisan migrate
php artisan config:cache
```

### Debug Mode

Enable debug logging for troubleshooting:

```env
AI_DEBUG_MODE=true
LOG_LEVEL=debug
```

Check logs at `storage/logs/laravel.log` for detailed error information.

## Performance Optimization

### Caching

Enable response caching to reduce API calls and costs:

```env
AI_CACHE_ENABLED=true
AI_CACHE_TTL=3600  # 1 hour
CACHE_DRIVER=redis  # Recommended for production
```

### Queue Workers

For optimal performance with job queues, run multiple workers:

```bash
# Run 3 queue workers for AI jobs
php artisan queue:work --queue=ai-requests --tries=3 --timeout=300 &
php artisan queue:work --queue=ai-requests --tries=3 --timeout=300 &
php artisan queue:work --queue=ai-requests --tries=3 --timeout=300 &
```

### Database Optimization

Add indexes for better query performance:

```sql
-- Add indexes to conversation tables
CREATE INDEX idx_conversations_user_id ON ai_conversations(user_id);
CREATE INDEX idx_messages_conversation_id ON ai_conversation_messages(conversation_id);
CREATE INDEX idx_requests_user_id ON ai_requests(user_id);
CREATE INDEX idx_requests_created_at ON ai_requests(created_at);
```

## Artisan Commands

The package includes several helpful Artisan commands:

### Test AI Engines

Test connectivity and configuration for all engines:

```bash
php artisan ai-engine:test
```

### Usage Reports

Generate usage and cost reports:

```bash
php artisan ai-engine:usage-report
php artisan ai-engine:usage-report --user=123
php artisan ai-engine:usage-report --from=2024-01-01 --to=2024-01-31
```

### Analytics Reports

View analytics and performance metrics:

```bash
php artisan ai-engine:analytics-report
php artisan ai-engine:analytics-report --format=json
```

### System Health Check

Check system health and provider status:

```bash
php artisan ai-engine:health
```

### Failover Status

View failover configuration and circuit breaker status:

```bash
php artisan ai-engine:failover-status
```

### Clear Cache

Clear AI response cache:

```bash
php artisan ai-engine:clear-cache
php artisan ai-engine:clear-cache --user=123
```

### Sync Models

Sync available models from AI providers:

```bash
php artisan ai-engine:sync-models
```

## Testing

Run the test suite:

```bash
composer test
```

Run specific test suites:

```bash
# Unit tests only
composer test -- --testsuite=Unit

# Feature tests only
composer test -- --testsuite=Feature

# With coverage
composer test -- --coverage
```

## Security Best Practices

### API Key Management

- **Never commit API keys** to version control
- Use environment variables for all sensitive credentials
- Rotate API keys regularly
- Use different keys for development and production

### Rate Limiting

- Enable rate limiting to prevent abuse
- Set appropriate limits per user/IP
- Monitor rate limit violations

### Credit System

- Implement credit limits per user
- Monitor credit usage patterns
- Set up alerts for unusual activity

### Input Validation

- Validate and sanitize all user inputs
- Implement content filtering for sensitive content
- Set maximum token limits

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

### Development Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Copy `.env.example` to `.env` and configure
4. Run tests: `composer test`

## Security Vulnerabilities

If you discover any security related issues, please email m.abou7agar@gmail.com instead of using the issue tracker.

## Credits

- [Mohamed Abou Hagar](https://github.com/mabou7agar)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) and [BUGFIX-CHANGELOG.md](BUGFIX-CHANGELOG.md) for more information on what has changed recently.

## Support

- 📧 Email: m.abou7agar@gmail.com
- 🐛 Issues: [GitHub Issues](https://github.com/mabou7agar/laravel-ai-engine/issues)
- 📖 Documentation: [Full Documentation](https://github.com/mabou7agar/laravel-ai-engine/wiki)

---

**Made with ❤️ for the Laravel community**
