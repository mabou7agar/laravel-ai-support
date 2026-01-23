# Drivers Property Access Audit

## Issue
Many drivers are using direct property access (e.g., `$request->prompt`) instead of getter methods (e.g., `$request->getPrompt()`). Since AIRequest properties are private, this will cause errors.

## Status

### ✅ Fixed Drivers
1. **DeepSeekEngineDriver** - Fixed to use `withSystemPrompt()` for immutable modification
2. **OpenRouterEngineDriver** - Fixed to use getter methods

### ❌ Drivers Needing Fixes

#### High Priority (Heavy Usage)
1. **GeminiEngineDriver** - 2 instances of `request->prompt`
2. **AzureEngineDriver** - 10 instances of `request->prompt`
3. **FalAIEngineDriver** - 3 instances of `request->prompt`
4. **PlagiarismCheckEngineDriver** - 7 instances of `request->prompt`
5. **MidjourneyEngineDriver** - 3 instances of `request->prompt`
6. **OpenAIEngineDriver** - 4 instances of `request->prompt`

#### Medium Priority
7. **ElevenLabsEngineDriver** - 3 instances of `request->prompt`
8. **PerplexityEngineDriver** - 2 instances of `request->prompt`
9. **UnsplashEngineDriver** - 3 instances of `request->prompt`
10. **StableDiffusionEngineDriver** - 2 instances of `request->prompt`
11. **SerperEngineDriver** - 2 instances of `request->prompt`
12. **BaseEngineDriver** - 2 instances of `request->prompt`

## Required Changes

### Pattern to Replace
```php
// ❌ Wrong - Direct property access
$request->prompt
$request->systemPrompt
$request->maxTokens
$request->temperature
$request->parameters
$request->conversationHistory

// ✅ Correct - Use getter methods
$request->getPrompt()
$request->getSystemPrompt()
$request->getMaxTokens()
$request->getTemperature()
$request->getParameters()
$request->getConversationHistory()
```

### For Modifications
```php
// ❌ Wrong - Mutation
$request->systemPrompt = "new value";

// ✅ Correct - Immutable
$modifiedRequest = $request->withSystemPrompt("new value");
```

## Next Steps
1. Fix all drivers systematically
2. Add tests to prevent regression
3. Consider adding deprecation warnings for direct access
4. Update driver documentation

## Total Instances Found
- `request->prompt`: ~40 instances across 12 drivers
- `request->systemPrompt`: ~10 instances
- `request->maxTokens`: ~5 instances
- `request->temperature`: ~5 instances
- `request->parameters`: ~15 instances
