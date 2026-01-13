# AI Model Recommendation System

The AI Engine includes an intelligent model recommendation system that automatically selects the best model for your task based on requirements, with automatic offline fallback to Ollama.

## Features

✅ **Task-Based Selection** - Automatically choose optimal model for vision, coding, reasoning, etc.
✅ **Offline Support** - Automatic fallback to Ollama when no internet connection
✅ **Database-Driven** - Uses `ai_models` table for dynamic model management
✅ **Cost Optimization** - Recommends cheapest model that meets requirements
✅ **Provider Filtering** - Optionally restrict to specific provider

## Supported Task Types

| Task | Description | Selection Criteria |
|------|-------------|-------------------|
| `vision` | Image analysis, OCR | Vision support + lowest cost |
| `coding` | Code generation, debugging | Coding capability |
| `reasoning` | Complex logic, math | Reasoning capability |
| `fast` | Quick responses | Lowest latency/cost |
| `cheap` | Cost-effective | Lowest price per token |
| `quality` | Best results | Largest context window |
| `default` | General chat | Chat support + lowest cost |

## Usage

### Basic Usage

```php
use LaravelAIEngine\Services\AIModelRegistry;

$registry = app(AIModelRegistry::class);

// Get best model for coding
$model = $registry->getRecommendedModel('coding');

// Get best vision model from OpenAI
$model = $registry->getRecommendedModel('vision', 'openai');

// Force offline mode (use Ollama)
$model = $registry->getRecommendedModel('coding', null, true);
```

### Automatic Offline Fallback

The system automatically detects internet connectivity and falls back to Ollama:

```php
// Automatically uses Ollama if offline
$model = $registry->getRecommendedModel('coding');

if ($model->provider === 'ollama') {
    echo "Using offline model: {$model->name}";
}
```

### Using Recommended Model in Chat

```php
$registry = app(AIModelRegistry::class);
$chatService = app(\LaravelAIEngine\Services\ChatService::class);

// Get best coding model
$model = $registry->getRecommendedModel('coding');

// Use it in chat
$response = $chatService->processMessage(
    message: 'Write a function to sort an array',
    sessionId: 'session-123',
    engine: $model->provider,
    model: $model->model_id
);
```

### API Endpoint

You can also get recommendations via API:

```bash
# Get recommended model for task
GET /api/v1/ai-engine/models/recommend?task=coding

# Get recommended model from specific provider
GET /api/v1/ai-engine/models/recommend?task=vision&provider=openai

# Force offline mode
GET /api/v1/ai-engine/models/recommend?task=coding&offline=true
```

## How It Works

### 1. Online Mode (Default)

```
User Request → Check Internet → Query ai_models table → Filter by task → Return best match
```

### 2. Offline Mode (Auto-Detected)

```
User Request → No Internet Detected → Query Ollama models → Filter by task → Return best Ollama model
```

### 3. Fallback Chain

```
1. Try online models (OpenAI, Anthropic, Google, etc.)
2. If no internet or no API key → Try Ollama
3. If no Ollama models → Return null
```

## Internet Detection

The system checks internet connectivity by:

1. **API Key Check** - If no OpenAI API key configured, assume offline
2. **Quick Ping** - HEAD request to `https://api.openai.com` with 2s timeout
3. **Graceful Fallback** - Any failure = offline mode

## Ollama Model Selection

When using Ollama (offline mode), the system selects models based on:

| Task | Selection Logic |
|------|----------------|
| `vision` | First Ollama model with vision support |
| `coding` | First Ollama model with coding capability |
| `reasoning` | Largest context window |
| `fast` | Smallest model (by name) |
| `cheap` | Any Ollama model (all free) |
| `quality` | Largest context window |

## Configuration

### Enable/Disable Offline Fallback

```php
// In config/ai-engine.php
'model_recommendation' => [
    'offline_fallback' => env('AI_OFFLINE_FALLBACK', true),
    'internet_check_timeout' => env('AI_INTERNET_CHECK_TIMEOUT', 2),
],
```

### Add Ollama Models

```bash
# Add Ollama models to database
php artisan ai-engine:add-model llama3 \
    --provider=ollama \
    --capabilities=coding,reasoning \
    --context-window=8192
```

## Examples

### Example 1: Smart Model Selection

```php
$registry = app(AIModelRegistry::class);

// Get best model for each task
$tasks = ['vision', 'coding', 'reasoning', 'fast', 'cheap', 'quality'];

foreach ($tasks as $task) {
    $model = $registry->getRecommendedModel($task);
    echo "{$task}: {$model->name} ({$model->provider})\n";
}

// Output:
// vision: GPT-4 Vision (openai)
// coding: Claude 3.5 Sonnet (anthropic)
// reasoning: O1 (openai)
// fast: GPT-3.5 Turbo (openai)
// cheap: GPT-3.5 Turbo (openai)
// quality: Claude 3.5 Sonnet (anthropic)
```

### Example 2: Offline Development

```php
// Simulate offline mode
$model = $registry->getRecommendedModel('coding', null, true);

echo "Offline model: {$model->name}\n";
// Output: Offline model: Llama 3 (ollama)

// Use in chat
$response = $chatService->processMessage(
    message: 'Write a hello world function',
    sessionId: 'offline-session',
    engine: 'ollama',
    model: $model->model_id
);
```

### Example 3: Provider-Specific Recommendation

```php
// Get best OpenAI model for coding
$openaiModel = $registry->getRecommendedModel('coding', 'openai');

// Get best Anthropic model for reasoning
$anthropicModel = $registry->getRecommendedModel('reasoning', 'anthropic');

// Get best Ollama model for fast responses
$ollamaModel = $registry->getRecommendedModel('fast', 'ollama');
```

## Testing

### Test Online Mode

```bash
php artisan tinker
```

```php
$registry = app(\LaravelAIEngine\Services\AIModelRegistry::class);

// Test online recommendation
$model = $registry->getRecommendedModel('coding');
echo $model->name; // Should return online model
```

### Test Offline Mode

```bash
# Disable internet or remove API keys
php artisan tinker
```

```php
$registry = app(\LaravelAIEngine\Services\AIModelRegistry::class);

// Test offline fallback
$model = $registry->getRecommendedModel('coding');
echo $model->provider; // Should return 'ollama'
```

### Test Force Offline

```php
// Force offline mode
$model = $registry->getRecommendedModel('coding', null, true);
echo $model->provider; // Always 'ollama'
```

## Best Practices

1. **Let the System Choose** - Use recommendations instead of hardcoding models
2. **Handle Null Returns** - Always check if model is null
3. **Cache Results** - Model recommendations are cached for 24 hours
4. **Test Offline** - Ensure Ollama models are configured for offline scenarios
5. **Monitor Costs** - Use 'cheap' task for cost-sensitive operations

## Troubleshooting

**Issue: Always returns null**
```bash
# Check if models exist in database
php artisan ai-engine:list-models

# Sync models from providers
php artisan ai-engine:sync-models
```

**Issue: Not falling back to Ollama**
```bash
# Check Ollama models
php artisan ai-engine:list-models --provider=ollama

# Add Ollama models
php artisan ai-engine:add-model llama3 --provider=ollama
```

**Issue: Internet check too slow**
```bash
# Reduce timeout in .env
AI_INTERNET_CHECK_TIMEOUT=1
```

## Related Documentation

- [Dynamic Model Registry](DYNAMIC_MODEL_REGISTRY.md)
- [Ollama Integration](../README.md#ollama-support)
- [Model Management](../README.md#model-management)
