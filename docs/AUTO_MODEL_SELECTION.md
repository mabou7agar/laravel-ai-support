# Automatic Model Selection

The AI Engine now includes intelligent automatic model selection that chooses the best AI model for your task, with automatic offline fallback to Ollama.

## Features

✅ **Task-Based Selection** - Automatically selects optimal model based on task type
✅ **Offline Fallback** - Seamlessly switches to Ollama when offline
✅ **Cost Optimization** - Chooses cheapest model that meets requirements
✅ **Zero Configuration** - Works out of the box with sensible defaults
✅ **Manual Override** - Can still specify model explicitly when needed

## Usage

### Option 1: Per-Request Auto-Selection

```json
POST /ai-demo/chat/send
{
  "message": "Write a Python function to sort an array",
  "session_id": "session-123",
  "auto_select_model": true,
  "task_type": "coding"
}
```

### Option 2: Global Auto-Selection

Enable in `.env`:
```bash
AI_AUTO_SELECT_MODEL=true
```

Then all requests without explicit model will auto-select:
```json
POST /ai-demo/chat/send
{
  "message": "Analyze this image",
  "session_id": "session-123",
  "task_type": "vision"
}
```

### Option 3: Explicit Model (Override)

```json
POST /ai-demo/chat/send
{
  "message": "Hello",
  "session_id": "session-123",
  "engine": "openai",
  "model": "gpt-4o"
}
```

## Task Types

| Task Type | Use Case | Selected Model |
|-----------|----------|----------------|
| `vision` | Image analysis, OCR | Cheapest vision-capable model |
| `coding` | Code generation, debugging | Model with coding capability |
| `reasoning` | Complex logic, math | Model with reasoning capability |
| `fast` | Quick responses | Fastest/cheapest model |
| `cheap` | Cost-effective | Lowest price per token |
| `quality` | Best results | Largest context window |
| `default` | General chat | Balanced chat model |

## Examples

### Example 1: Coding Task

```bash
curl -X POST https://dash.test/ai-demo/chat/send \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "message": "Write a function to calculate fibonacci",
    "session_id": "session-123",
    "auto_select_model": true,
    "task_type": "coding"
  }'
```

**Result:** Automatically selects best coding model (e.g., Claude 3.5 Sonnet or GPT-4)

### Example 2: Vision Task

```bash
curl -X POST https://dash.test/ai-demo/chat/send \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "message": "What is in this image?",
    "session_id": "session-123",
    "auto_select_model": true,
    "task_type": "vision"
  }'
```

**Result:** Automatically selects cheapest vision model (e.g., GPT-4 Vision)

### Example 3: Offline Mode

```bash
# When no internet connection detected
curl -X POST https://dash.test/ai-demo/chat/send \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "message": "Explain quantum computing",
    "session_id": "session-123",
    "auto_select_model": true,
    "task_type": "reasoning"
  }'
```

**Result:** Automatically falls back to Ollama model (e.g., Llama 3)

## How It Works

```
1. Request received with task_type
2. Check if auto_select_model=true OR global config enabled
3. Query AIModelRegistry for best model for task
4. Check internet connectivity
5. If offline → Select best Ollama model
6. If online → Select best online model
7. Process request with selected model
```

## Configuration

### Enable Global Auto-Selection

```bash
# .env
AI_AUTO_SELECT_MODEL=true
```

### Configure Model Database

Ensure models are synced:
```bash
php artisan ai-engine:sync-models
php artisan ai-engine:list-models
```

### Add Ollama Models for Offline Support

```bash
php artisan ai-engine:add-model llama3 \
  --provider=ollama \
  --capabilities=coding,reasoning \
  --context-window=8192
```

## Response Format

When auto-selection is used, the response includes model info:

```json
{
  "success": true,
  "response": "Here is the code...",
  "model_info": {
    "provider": "openai",
    "model": "gpt-4o-mini",
    "auto_selected": true,
    "task_type": "coding",
    "is_offline": false
  }
}
```

## Benefits

### 1. Cost Optimization
Automatically uses cheapest model that meets requirements

### 2. Offline Support
Seamlessly works offline with Ollama

### 3. Future-Proof
New models automatically available via database

### 4. Task-Optimized
Each task gets the best model for its needs

### 5. Developer-Friendly
No need to track which model is best for what

## Advanced Usage

### Smart Detection

The system can infer task type from message content:

```javascript
// Future enhancement
const taskType = detectTaskType(message);
// "Write code" → coding
// "Analyze image" → vision
// "Solve equation" → reasoning
```

### Provider Preference

```json
{
  "message": "Code review",
  "auto_select_model": true,
  "task_type": "coding",
  "preferred_provider": "anthropic"
}
```

### Cost Limits

```json
{
  "message": "Quick question",
  "auto_select_model": true,
  "task_type": "default",
  "max_cost_per_1k_tokens": 0.001
}
```

## Troubleshooting

**Issue: Always uses same model**
```bash
# Clear model cache
php artisan cache:clear
php artisan ai-engine:sync-models
```

**Issue: Not falling back to Ollama**
```bash
# Check Ollama models exist
php artisan ai-engine:list-models --provider=ollama

# Add Ollama models
php artisan ai-engine:add-model llama3 --provider=ollama
```

**Issue: Wrong model selected**
```bash
# Check model capabilities
php artisan ai-engine:list-models --verbose

# Update model metadata
php artisan ai-engine:sync-models
```

## Integration with Async Workflows

Auto-selection works seamlessly with async mode:

```json
{
  "message": "create invoice",
  "session_id": "session-123",
  "auto_select_model": true,
  "task_type": "default",
  "async": true,
  "actions": true
}
```

## Comparison

| Approach | Pros | Cons |
|----------|------|------|
| **Manual Selection** | Full control | Need to track models |
| **Auto Selection** | Optimal choice | Less control |
| **Hybrid** | Best of both | More complex |

## Best Practices

1. ✅ Use auto-selection for prototyping
2. ✅ Use explicit models for production critical paths
3. ✅ Enable offline fallback with Ollama
4. ✅ Monitor costs and adjust task types
5. ✅ Keep model database synced

## Related Documentation

- [Model Recommendation API](MODEL_RECOMMENDATION.md)
- [Dynamic Model Registry](DYNAMIC_MODEL_REGISTRY.md)
- [Async Workflows](ASYNC_WORKFLOWS.md)
