# Drivers Property Access Audit

## Issue
Many drivers were using direct property access (e.g., `$request->prompt`) instead of getter methods (e.g., `$request->getPrompt()`). Since AIRequest properties are private, this caused errors.

## Status: ✅ COMPLETED

All drivers have been fixed to use proper getter methods instead of direct property access.

### ✅ Fixed Drivers (All 16)

1. **AnthropicEngineDriver** ✅
2. **AzureEngineDriver** ✅ (10 instances fixed)
3. **BaseEngineDriver** ✅
4. **DeepSeekEngineDriver** ✅
5. **ElevenLabsEngineDriver** ✅
6. **FalAIEngineDriver** ✅
7. **GeminiEngineDriver** ✅
8. **MidjourneyEngineDriver** ✅
9. **OllamaEngineDriver** ✅
10. **OpenAIEngineDriver** ✅
11. **OpenRouterEngineDriver** ✅
12. **PerplexityEngineDriver** ✅
13. **PlagiarismCheckEngineDriver** ✅ (7 instances fixed)
14. **SerperEngineDriver** ✅
15. **StableDiffusionEngineDriver** ✅
16. **UnsplashEngineDriver** ✅

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

## Commits
- **73ea38b** - Fixed DeepSeek driver immutable pattern
- **80b09c5** - Fixed OpenRouter driver + created audit
- **c93595b** - Fixed getMessages() method usage
- **e6d46c5** - Fixed abstract method visibility
- **56d4421** - Fixed all 16 drivers systematically

## Total Instances Fixed
- `request->prompt`: ~40 instances → `request->getPrompt()`
- `request->systemPrompt`: ~10 instances → `request->getSystemPrompt()`
- `request->maxTokens`: ~5 instances → `request->getMaxTokens()`
- `request->temperature`: ~5 instances → `request->getTemperature()`
- `request->parameters`: ~15 instances → `request->getParameters()`
- `request->model`: Multiple instances → `request->getModel()`
- `request->engine`: Multiple instances → `request->getEngine()`
- `request->userId`: Multiple instances → `request->getUserId()`
- `request->files`: Multiple instances → `request->getFiles()`

**Total: ~75+ instances fixed across 16 drivers**
