# AI Engine Error Handling

This document explains how to configure and handle AI engine errors gracefully.

## Configuration

All error handling settings are configured in `config/ai-engine.php` under the `error_handling` section.

### Environment Variables

Add these to your `.env` file:

```env
# Show detailed error messages (useful for debugging)
AI_ENGINE_SHOW_DETAILED_ERRORS=false

# Show API quota/billing errors to users
AI_ENGINE_SHOW_QUOTA_ERRORS=true

# Fallback message when AI is unavailable
AI_ENGINE_FALLBACK_MESSAGE="AI service is temporarily unavailable. Please try again later."
```

### Configuration Options

```php
'error_handling' => [
    // Show detailed error messages to users (useful for debugging)
    'show_detailed_errors' => env('AI_ENGINE_SHOW_DETAILED_ERRORS', false),
    
    // Show API quota/billing errors to users
    'show_quota_errors' => env('AI_ENGINE_SHOW_QUOTA_ERRORS', true),
    
    // Fallback message when AI is unavailable
    'fallback_message' => env('AI_ENGINE_FALLBACK_MESSAGE', 'AI service is temporarily unavailable. Please try again later.'),
    
    // User-friendly error messages for common errors
    'error_messages' => [
        'quota_exceeded' => 'AI service quota has been exceeded. Please contact support or try again later.',
        'rate_limit' => 'Too many requests. Please wait a moment and try again.',
        'invalid_api_key' => 'AI service configuration error. Please contact support.',
        'network_error' => 'Unable to connect to AI service. Please check your connection and try again.',
        'timeout' => 'AI service request timed out. Please try again.',
        'model_not_found' => 'The requested AI model is not available. Please try a different model.',
    ],
],
```

## Error Types

### 1. Quota Exceeded

**When it occurs:** OpenAI API quota has been exceeded or billing issues.

**Default message:** "AI service quota has been exceeded. Please contact support or try again later."

**How to handle:**
- Check your OpenAI billing dashboard: https://platform.openai.com/account/billing
- Add credits or upgrade your plan
- Verify API key has proper permissions

**Configuration:**
```env
AI_ENGINE_SHOW_QUOTA_ERRORS=true
```

### 2. Rate Limit

**When it occurs:** Too many requests sent to the API in a short time.

**Default message:** "Too many requests. Please wait a moment and try again."

**How to handle:**
- Implement request throttling
- Add delays between requests
- Upgrade to higher rate limits

### 3. Invalid API Key

**When it occurs:** API key is missing, invalid, or lacks permissions.

**Default message:** "AI service configuration error. Please contact support."

**How to handle:**
- Verify `OPENAI_API_KEY` in `.env`
- Check API key permissions in OpenAI dashboard
- Regenerate API key if needed

### 4. Network Error

**When it occurs:** Connection issues to AI service.

**Default message:** "Unable to connect to AI service. Please check your connection and try again."

**How to handle:**
- Check internet connectivity
- Verify firewall settings
- Check if OpenAI API is down: https://status.openai.com/

### 5. Timeout

**When it occurs:** Request takes too long to complete.

**Default message:** "AI service request timed out. Please try again."

**How to handle:**
- Reduce prompt complexity
- Use faster models (e.g., gpt-3.5-turbo instead of gpt-4)
- Increase timeout settings

### 6. Model Not Found

**When it occurs:** Requested AI model doesn't exist or isn't available.

**Default message:** "The requested AI model is not available. Please try a different model."

**How to handle:**
- Verify model name in configuration
- Check if model is deprecated
- Use alternative model

## User Experience

### Production Mode (Recommended)

```env
AI_ENGINE_SHOW_DETAILED_ERRORS=false
AI_ENGINE_SHOW_QUOTA_ERRORS=true
```

Users see friendly messages:
- ⚠️ AI service quota has been exceeded. Please contact support or try again later.

### Development Mode

```env
AI_ENGINE_SHOW_DETAILED_ERRORS=true
AI_ENGINE_SHOW_QUOTA_ERRORS=true
```

Users see detailed error messages:
- ⚠️ You exceeded your current quota, please check your plan and billing details.

## Fallback Behavior

When AI services are unavailable, the system automatically:

1. **Intent Analysis:** Falls back to basic keyword detection
2. **Action Generation:** Uses simplified matching logic
3. **Chat Responses:** Shows configured error message to user
4. **Logging:** Records detailed error in `storage/logs/ai-engine.log`

## Customizing Error Messages

You can customize error messages in the configuration:

```php
'error_messages' => [
    'quota_exceeded' => 'Our AI assistant is temporarily unavailable due to high demand. Please try again in a few minutes.',
    'rate_limit' => 'Please slow down! Wait a moment before sending another message.',
    // ... other messages
],
```

## Monitoring

Check logs for AI errors:

```bash
# View AI engine logs
tail -f storage/logs/ai-engine.log | grep -i "error\|quota\|failed"

# View Laravel logs
tail -f storage/logs/laravel.log | grep -i "openai\|ai error"
```

## Best Practices

1. **Production:** Always set `AI_ENGINE_SHOW_DETAILED_ERRORS=false`
2. **Monitoring:** Set up alerts for quota exceeded errors
3. **Fallback:** Ensure fallback messages are user-friendly
4. **Testing:** Test error scenarios in development
5. **Documentation:** Document error handling for your team

## Example: Testing Error Handling

```php
// Simulate quota exceeded error
config(['ai-engine.error_handling.show_quota_errors' => true]);

// Send message
$response = $chatService->processMessage(
    message: 'Create Product Test',
    sessionId: 'test-session',
    // ... other params
);

// Check for error
if (isset($response->metadata['ai_error'])) {
    echo $response->content; // Shows user-friendly error
}
```

## Troubleshooting

### Error not showing to users

1. Check configuration: `config('ai-engine.error_handling.show_quota_errors')`
2. Clear config cache: `php artisan config:clear`
3. Check logs: `tail -f storage/logs/ai-engine.log`

### Detailed errors showing in production

1. Set `AI_ENGINE_SHOW_DETAILED_ERRORS=false` in `.env`
2. Clear config cache: `php artisan config:clear`
3. Restart application

### Fallback not working

1. Verify fallback message in config
2. Check if error is being caught
3. Review logs for exceptions

## Related Documentation

- [Configuration Guide](CONFIGURATION.md)
- [Debugging Guide](DEBUGGING.md)
- [API Reference](API_REFERENCE.md)
