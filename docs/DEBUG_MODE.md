# AI Engine Debug Mode

Debug mode provides detailed logging of all AI requests, including full prompts sent to AI models and execution timing.

## Enabling Debug Mode

### Method 1: Environment Variable (Recommended)

Add to your `.env` file:

```env
AI_ENGINE_DEBUG=true
```

### Method 2: Config File

Edit `config/ai-engine.php`:

```php
'debug' => true,
```

### Method 3: Per-Request (Programmatic)

Pass debug flag in request metadata:

```php
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

$request = new AIRequest(
    prompt: 'Your prompt here',
    engine: EngineEnum::from('openai'),
    model: EntityEnum::from('gpt-4o'),
    metadata: ['debug' => true]  // Enable debug for this request only
);

$response = app(\LaravelAIEngine\Services\AIEngineService::class)->generate($request);
```

## What Gets Logged

When debug mode is enabled, the following information is logged to the `ai-engine` log channel:

### Before AI Request (🔍 AI Request Debug)
- **Request ID**: Unique identifier for tracking
- **Engine**: AI engine being used (openai, anthropic, etc.)
- **Model**: Specific model (gpt-4o, gpt-4o-mini, etc.)
- **Prompt Length**: Character count of the prompt
- **Full Prompt**: Complete prompt sent to AI
- **System Prompt**: System instructions (if any)
- **Temperature**: Creativity setting
- **Max Tokens**: Token limit

### After AI Response (✅ AI Response Debug)
- **Request ID**: Matches the request for correlation
- **Execution Time**: How long the AI took to respond (in seconds)
- **Response Length**: Character count of the response
- **Response Preview**: First 200 characters of the response
- **Success**: Whether the request succeeded
- **Tokens Used**: Token usage statistics

## Viewing Debug Logs

### Real-time Monitoring

```bash
# Watch all AI engine logs
tail -f storage/logs/laravel.log | grep "ai-engine"

# Watch only debug logs
tail -f storage/logs/laravel.log | grep "AI Request Debug\|AI Response Debug"
```

### Example Debug Output

```
[2026-01-07 13:48:31] local.INFO: 🔍 AI Request Debug {
    "request_id": "ai_req_695e642f7aaa8",
    "engine": "openai",
    "model": "gpt-4o-mini",
    "prompt_length": 1925,
    "prompt": "Analyze the user's message to extract a value for a specific field...",
    "system_prompt": null,
    "temperature": 0,
    "max_tokens": 200
}

[2026-01-07 13:48:33] local.INFO: ✅ AI Response Debug {
    "request_id": "ai_req_695e642f7aaa8",
    "execution_time": "1.846s",
    "response_length": 250,
    "response_preview": "{\"intent\":\"provide_value\",\"confidence\":0.95,\"extracted_value\":\"Laravel Basics\",\"reasoning\":\"User clearly provided a course name\"}",
    "success": true,
    "tokens_used": {
        "prompt_tokens": 450,
        "completion_tokens": 50,
        "total_tokens": 500
    }
}
```

## Use Cases

### 1. Debugging Hallucination Issues

Enable debug mode to see exactly what prompts are being sent to the AI and verify they contain the correct context and instructions.

```bash
AI_ENGINE_DEBUG=true php artisan ai:test-data-collector --preset=course
```

### 2. Performance Optimization

Monitor execution times to identify slow AI requests:

```bash
tail -f storage/logs/laravel.log | grep "execution_time"
```

### 3. Prompt Engineering

Review actual prompts sent to AI to refine and improve them:

```bash
tail -f storage/logs/laravel.log | grep "prompt\":" | jq .prompt
```

### 4. Token Usage Tracking

Monitor token consumption for cost optimization:

```bash
tail -f storage/logs/laravel.log | grep "tokens_used"
```

## Testing Commands with Debug

All test commands support debug mode via environment variable:

```bash
# DataCollector tests
AI_ENGINE_DEBUG=true php artisan ai:test-data-collector --preset=course
AI_ENGINE_DEBUG=true php artisan ai:test-hallucination

# Chat tests
AI_ENGINE_DEBUG=true php artisan ai:test-chat
AI_ENGINE_DEBUG=true php artisan ai:test-intent

# Other tests
AI_ENGINE_DEBUG=true php artisan ai:test-dynamic-actions
```

## Performance Impact

Debug mode adds minimal overhead:
- **Logging**: ~1-5ms per request
- **Storage**: ~1-5KB per request in logs

**Recommendation**: Only enable in development/staging environments. Disable in production unless actively debugging.

## Security Considerations

⚠️ **Warning**: Debug logs contain full prompts and responses, which may include:
- User input
- Sensitive data
- API responses
- Business logic

**Best Practices**:
1. Never commit debug logs to version control
2. Rotate logs frequently in development
3. Disable debug mode in production
4. Review logs before sharing with third parties
5. Add `storage/logs/*.log` to `.gitignore`

## Troubleshooting

### Debug logs not appearing

1. **Clear config cache**:
   ```bash
   php artisan config:clear
   ```

2. **Verify log channel exists** in `config/logging.php`:
   ```php
   'ai-engine' => [
       'driver' => 'single',
       'path' => storage_path('logs/laravel.log'),
       'level' => 'debug',
   ],
   ```

3. **Check file permissions**:
   ```bash
   chmod -R 775 storage/logs
   ```

### Too many logs

Filter by specific request ID:
```bash
tail -f storage/logs/laravel.log | grep "ai_req_695e642f7aaa8"
```

### Log file too large

Rotate logs manually:
```bash
mv storage/logs/laravel.log storage/logs/laravel-$(date +%Y%m%d).log
touch storage/logs/laravel.log
chmod 664 storage/logs/laravel.log
```

## Related Documentation

- [Data Collector Guide](./guides/DATA_COLLECTOR_CHAT_GUIDE.md)
- [AI Engine Configuration](../config/ai-engine.php)
- [Testing Guide](./TESTING.md)
