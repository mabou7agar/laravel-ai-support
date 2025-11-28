# 🚀 Dynamic AI Model Registry

## 🎯 Overview

The Laravel AI Engine now features a **future-proof dynamic model registry** that automatically supports new AI models like GPT-5, GPT-5.1, Claude 4, and beyond without requiring code changes.

---

## 🌟 Key Features

### ✅ Future-Proof Design
- **Database-Driven** - All models stored in database
- **Auto-Discovery** - Automatically detect new models from APIs
- **Zero Code Changes** - Add GPT-5, GPT-6, etc. without touching code
- **Version Tracking** - Track model versions and deprecations

### 🔄 Auto-Sync
- **OpenAI API Integration** - Auto-discover new OpenAI models
- **Manual Registration** - Add models from any provider
- **Bulk Updates** - Sync all providers at once
- **Smart Caching** - 24-hour cache for performance

### 📊 Rich Metadata
- **Capabilities** - Vision, function calling, reasoning, etc.
- **Pricing** - Input/output token costs
- **Context Windows** - Max input/output tokens
- **Features** - Streaming, JSON mode, etc.

---

## 🏗️ Architecture

### Database Schema

```sql
CREATE TABLE ai_models (
    id BIGINT PRIMARY KEY,
    provider VARCHAR,              -- openai, anthropic, google
    model_id VARCHAR UNIQUE,       -- gpt-5, claude-4, gemini-2
    name VARCHAR,                  -- GPT-5, Claude 4
    version VARCHAR,               -- 2025-01-15, v2.0
    description TEXT,
    capabilities JSON,             -- ['chat', 'vision', 'reasoning']
    context_window JSON,           -- {'input': 200000, 'output': 8192}
    pricing JSON,                  -- {'input': 0.01, 'output': 0.03}
    max_tokens INT,
    supports_streaming BOOLEAN,
    supports_vision BOOLEAN,
    supports_function_calling BOOLEAN,
    supports_json_mode BOOLEAN,
    is_active BOOLEAN,
    is_deprecated BOOLEAN,
    released_at TIMESTAMP,
    deprecated_at TIMESTAMP,
    metadata JSON,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP
);
```

### Components

1. **AIModel** - Eloquent model with rich methods
2. **AIModelRegistry** - Service for model management
3. **Commands** - Artisan commands for CLI management
4. **Seeder** - Initial model data

---

## 📝 Usage

### 1. Initial Setup

```bash
# Run migrations
php artisan migrate

# Seed initial models
php artisan db:seed --class=LaravelAIEngine\\Database\\Seeders\\AIModelsSeeder

# Verify models
php artisan ai-engine:list-models
```

### 2. Auto-Sync New Models

```bash
# Sync all providers (OpenAI, OpenRouter, Anthropic)
php artisan ai-engine:sync-models

# Sync specific provider
php artisan ai-engine:sync-models --provider=openai
php artisan ai-engine:sync-models --provider=openrouter
php artisan ai-engine:sync-models --provider=anthropic

# Auto-discover new models
php artisan ai-engine:sync-models --auto-discover
```

**Example Output:**
```
🔄 Syncing AI Models...

📡 Syncing OpenAI models...
✅ Synced 15 OpenAI models
🆕 Discovered 2 new models:
   - gpt-5
   - gpt-5-turbo

📡 Syncing OpenRouter models...
✅ Synced 150+ OpenRouter models
🆕 Discovered 120 new models
   (Showing first 10 of 120 new models)
   - openai/gpt-5
   - anthropic/claude-4-opus
   - meta-llama/llama-3.3-70b-instruct
   - google/gemini-2.5-pro
   - deepseek/deepseek-r1
   - mistralai/mistral-large-2
   - qwen/qwen-2.5-72b-instruct
   - cohere/command-r-plus
   - x-ai/grok-2
   - perplexity/llama-3.1-sonar-large-128k-online

📊 Model Statistics:
┌─────────────────────┬───────┐
│ Metric              │ Count │
├─────────────────────┼───────┤
│ Total Models        │ 25    │
│ Active Models       │ 23    │
│ Deprecated Models   │ 2     │
│ Vision Models       │ 12    │
│ Function Calling    │ 18    │
└─────────────────────┴───────┘
```

### 3. List Available Models

```bash
# List all models
php artisan ai-engine:list-models

# Filter by provider
php artisan ai-engine:list-models --provider=openai

# Show only vision models
php artisan ai-engine:list-models --vision

# Export as JSON
php artisan ai-engine:list-models --json > models.json
```

**Example Output:**
```
📋 Available AI Models (25)

🤖 openai (15 models)
────────────────────────────────────────────────────────────────────────────────
┌──────────────┬─────────────┬──────────────┬────────────────────┬──────────┐
│ Model ID     │ Name        │ Capabilities │ Pricing (per 1M)   │ Status   │
├──────────────┼─────────────┼──────────────┼────────────────────┼──────────┤
│ gpt-5        │ GPT-5       │ 👁️ ⚙️ 🧠    │ $5.00 / $15.00     │ ✅ Active│
│ gpt-4o       │ GPT-4o      │ 👁️ ⚙️       │ $2.50 / $10.00     │ ✅ Active│
│ gpt-4o-mini  │ GPT-4o Mini │ 👁️ ⚙️       │ $0.15 / $0.60      │ ✅ Active│
└──────────────┴─────────────┴──────────────┴────────────────────┴──────────┘

💡 Recommendations:
   💰 Cheapest: GPT-4o Mini (gpt-4o-mini)
   👁️  Vision: GPT-5 (gpt-5)
   💻 Coding: O1 Mini (o1-mini)
```

### 4. Add New Model Manually

```bash
# Interactive mode
php artisan ai-engine:add-model gpt-5 --interactive

# Quick add
php artisan ai-engine:add-model gpt-5 \
    --provider=openai \
    --name="GPT-5" \
    --description="Next generation model"
```

**Interactive Example:**
```
🤖 Adding new AI model: gpt-5

Select provider:
  [0] openai
  [1] anthropic
  [2] google
 > 0

Display name [Gpt 5]: GPT-5

Description (optional): Next generation flagship model

Version (optional): 2025-01-15

Select capabilities (comma-separated):
  [0] chat
  [1] vision
  [2] function_calling
  [3] reasoning
 > 0,1,2,3

Max input tokens [128000]: 200000
Max output tokens [4096]: 8192
Input price per 1M tokens ($) [0.001]: 5.00
Output price per 1M tokens ($) [0.003]: 15.00
Supports streaming? (yes/no) [yes]: yes

✅ Model 'GPT-5' added successfully!
```

### 5. Use in Code

```php
use LaravelAIEngine\Services\AIModelRegistry;
use LaravelAIEngine\Models\AIModel;

$registry = app(AIModelRegistry::class);

// Get all active models
$models = $registry->getAllModels();

// Get specific model
$gpt5 = $registry->getModel('gpt-5');

// Check if model is available
if ($registry->isModelAvailable('gpt-5')) {
    // Use GPT-5
}

// Get recommended model for task
$visionModel = $registry->getRecommendedModel('vision');
$codingModel = $registry->getRecommendedModel('coding');
$cheapModel = $registry->getRecommendedModel('cheap');

// Get models by provider
$openaiModels = $registry->getModelsByProvider('openai');

// Search models
$results = $registry->search('gpt');

// Get cheapest model
$cheapest = $registry->getCheapestModel();
$cheapestOpenAI = $registry->getCheapestModel('openai');

// Get most capable model
$best = $registry->getMostCapableModel();
```

### 6. Model Methods

```php
$model = AIModel::findByModelId('gpt-5');

// Check capabilities
if ($model->supports('vision')) {
    // Use vision features
}

if ($model->isVisionModel()) {
    // Vision-capable
}

if ($model->supportsFunctionCalling()) {
    // Function calling available
}

// Get pricing
$inputPrice = $model->getInputPrice();
$outputPrice = $model->getOutputPrice();

// Estimate cost
$cost = $model->estimateCost(
    inputTokens: 1000,
    outputTokens: 500
);

// Get context window
$maxInput = $model->getContextWindowSize();
$maxOutput = $model->getMaxOutputTokens();

// Display name
echo $model->display_name; // "GPT-5 (2025-01-15)"
```

### 7. Scopes and Queries

```php
// Get chat models
$chatModels = AIModel::active()->chat()->get();

// Get vision models
$visionModels = AIModel::active()->vision()->get();

// Get function calling models
$functionModels = AIModel::active()->functionCalling()->get();

// Get by provider
$anthropicModels = AIModel::byProvider('anthropic')->get();

// Complex query
$models = AIModel::active()
    ->where('provider', 'openai')
    ->vision()
    ->orderBy('pricing->input')
    ->get();
```

---

## 🌐 OpenRouter Integration

### Access 150+ Models with One API Key!

OpenRouter provides access to models from multiple providers through a single API:

```bash
# Sync all OpenRouter models (150+ models!)
php artisan ai-engine:sync-models --provider=openrouter
```

**Available through OpenRouter:**
- ✅ **OpenAI** - GPT-5, GPT-4o, O1, etc.
- ✅ **Anthropic** - Claude 4, Claude 3.5 Sonnet, etc.
- ✅ **Google** - Gemini 2.5 Pro, Gemini 1.5 Pro, etc.
- ✅ **Meta** - Llama 3.3 70B, Llama 3.1 405B, etc.
- ✅ **Mistral** - Mistral Large 2, Mixtral 8x7B, etc.
- ✅ **DeepSeek** - DeepSeek R1, DeepSeek Chat, etc.
- ✅ **Qwen** - Qwen 2.5 72B, etc.
- ✅ **Cohere** - Command R+, etc.
- ✅ **X.AI** - Grok 2, etc.
- ✅ **Free Models** - 10+ free models available!

### Usage Example

```php
use LaravelAIEngine\Services\AIModelRegistry;

$registry = app(AIModelRegistry::class);

// Get all OpenRouter models
$openrouterModels = $registry->getModelsByProvider('openrouter');

// Use any OpenRouter model
$response = AIEngine::engine('openrouter')
    ->model('anthropic/claude-4-opus')  // ← Works when Claude 4 releases!
    ->chat('Hello Claude 4!');

// Or use GPT-5 through OpenRouter
$response = AIEngine::engine('openrouter')
    ->model('openai/gpt-5')
    ->chat('Hello GPT-5!');

// Get cheapest OpenRouter model
$cheapest = $registry->getCheapestModel('openrouter');

// Get free models
$freeModels = AIModel::where('provider', 'openrouter')
    ->where('model_id', 'like', '%:free')
    ->get();
```

### Benefits of OpenRouter

1. **Single API Key** - Access 150+ models with one key
2. **Auto-Failover** - Automatic provider switching
3. **Best Pricing** - Competitive rates across providers
4. **Free Tier** - 10+ free models available
5. **Latest Models** - New models added immediately
6. **No Vendor Lock-in** - Switch models easily

---

## 🔄 Auto-Discovery Flow

### When GPT-5 is Released:

1. **OpenAI announces GPT-5**
2. **Run sync command:**
   ```bash
   php artisan ai-engine:sync-models --provider=openai
   ```
3. **System auto-discovers GPT-5:**
   - Fetches from OpenAI API
   - Detects capabilities automatically
   - Creates database record
   - Caches for 24 hours
4. **GPT-5 immediately available:**
   ```php
   $response = AIEngine::engine('openai')
       ->model('gpt-5')  // ← Works automatically!
       ->chat('Hello GPT-5!');
   ```

---

## 📊 Model Lifecycle Management

### Deprecation

```php
// Mark model as deprecated
$model = AIModel::findByModelId('gpt-3.5-turbo');
$model->deprecate();

// Query non-deprecated models
$active = AIModel::active()->get(); // Excludes deprecated
```

### Soft Deletes

```php
// Soft delete
$model->delete();

// Restore
$model->restore();

// Force delete
$model->forceDelete();

// Include deleted
$all = AIModel::withTrashed()->get();
```

---

## 🎯 Benefits

### 1. **Future-Proof**
```php
// This code works for GPT-4, GPT-5, GPT-6, GPT-100!
$model = $registry->getModel($modelId);
if ($model && $model->is_active) {
    $response = AIEngine::engine($model->provider)
        ->model($model->model_id)
        ->chat($message);
}
```

### 2. **Cost Optimization**
```php
// Always use cheapest model
$cheapest = $registry->getCheapestModel('openai');

// Estimate before using
$cost = $model->estimateCost(1000, 500);
if ($cost < $budget) {
    // Proceed
}
```

### 3. **Smart Selection**
```php
// Auto-select best model for task
$model = match($task) {
    'vision' => $registry->getRecommendedModel('vision'),
    'coding' => $registry->getRecommendedModel('coding'),
    'reasoning' => $registry->getRecommendedModel('reasoning'),
    default => $registry->getCheapestModel(),
};
```

### 4. **Dynamic UI**
```php
// Populate dropdown with latest models
$models = AIModel::active()
    ->where('provider', 'openai')
    ->orderBy('name')
    ->get();

foreach ($models as $model) {
    echo "<option value='{$model->model_id}'>";
    echo "{$model->name} - \${$model->getInputPrice()}/1M tokens";
    echo "</option>";
}
```

---

## 🔧 Scheduled Sync

### Setup Auto-Sync

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Sync models daily
    $schedule->command('ai-engine:sync-models')
        ->daily()
        ->at('02:00');
        
    // Or every 6 hours
    $schedule->command('ai-engine:sync-models')
        ->everySixHours();
}
```

---

## 📈 Statistics & Monitoring

```php
$stats = $registry->getStatistics();

/*
[
    'total' => 25,
    'active' => 23,
    'deprecated' => 2,
    'by_provider' => [
        'openai' => 15,
        'anthropic' => 5,
        'google' => 3,
        'deepseek' => 1,
        'perplexity' => 1,
    ],
    'with_vision' => 12,
    'with_function_calling' => 18,
]
*/
```

---

## ✅ Summary

**The package is now 100% future-proof!**

- ✅ **GPT-5, GPT-5.1, GPT-6** - Auto-discovered when released
- ✅ **Claude 4, Claude 5** - Add manually or via API
- ✅ **Any new model** - Zero code changes required
- ✅ **Database-driven** - All models in DB
- ✅ **Auto-sync** - Daily updates via cron
- ✅ **Rich metadata** - Pricing, capabilities, limits
- ✅ **Smart caching** - High performance
- ✅ **CLI management** - Easy administration

**When GPT-5 launches, just run:**
```bash
php artisan ai-engine:sync-models
```

**And it's immediately available in your app!** 🎉🚀

---

**Last Updated:** November 28, 2025  
**Status:** Production Ready
