# Bug Fix Changelog

## [2.1.1] - 2025-11-26

### Fixed

#### Critical: AIEngineManager Constructor Dependency Injection Error

**Issue**: 
```
Error: Too few arguments to function LaravelAIEngine\Services\AIEngineManager::__construct(), 
1 passed in AIEngineServiceProvider.php on line 54 and exactly 5 expected
```

**Root Cause**:
- `AIEngineManager` constructor requires 5 dependencies:
  1. `Application $app`
  2. `CreditManager $creditManager`
  3. `CacheManager $cacheManager`
  4. `RateLimitManager $rateLimitManager`
  5. `AnalyticsManager $analyticsManager`

- Service provider was only passing `$app` parameter

**Solution**:
1. Added missing import for `LaravelAIEngine\Services\AnalyticsManager`
2. Reordered service registration to register dependencies first
3. Updated `AIEngineManager` singleton registration to inject all 5 required dependencies

**Files Modified**:
- `src/AIEngineServiceProvider.php`
  - Added `use LaravelAIEngine\Services\AnalyticsManager;` import
  - Moved dependency registrations before `AIEngineManager` registration
  - Updated `AIEngineManager` instantiation to pass all required parameters

**Code Changes**:
```php
// Before (BROKEN)
$this->app->singleton(AIEngineManager::class, function ($app) {
    return new AIEngineManager($app);
});

// After (FIXED)
$this->app->singleton(AnalyticsManager::class, function ($app) {
    return new AnalyticsManager($app);
});

$this->app->singleton(AIEngineManager::class, function ($app) {
    return new AIEngineManager(
        $app,
        $app->make(CreditManager::class),
        $app->make(CacheManager::class),
        $app->make(RateLimitManager::class),
        $app->make(AnalyticsManager::class)
    );
});
```

**Impact**:
- ✅ Fixes package initialization errors across all Laravel versions (9-12)
- ✅ Ensures proper dependency injection for all manager services
- ✅ Maintains backward compatibility

**Testing**:
- ✅ Package discovery successful
- ✅ Service provider registration working
- ✅ All dependencies properly resolved

---

## Previous Fixes

### [2.1.1] - 2025-11-26

#### OpenAI Client Version Compatibility

**Fixed**: Updated `composer.json` to support multiple OpenAI client versions
- Changed: `"openai-php/client": "^0.8"` → `"openai-php/client": "^0.8|^0.9|^0.10"`
- Added support for Laravel 9-12: `"illuminate/*": "^9.0|^10.0|^11.0|^12.0"`
- Added Symfony 7 support: `"symfony/http-client": "^5.4|^6.0|^7.0"`

**Impact**: Package now works with Laravel 12 and latest OpenAI client v0.10.3

---

## Notes

### Two AnalyticsManager Classes
The package contains two `AnalyticsManager` implementations:

1. **`LaravelAIEngine\Services\AnalyticsManager`** (Legacy)
   - Used by `AIEngineManager`
   - Constructor: `__construct(Application $app)`
   - Simple database/log analytics

2. **`LaravelAIEngine\Services\Analytics\AnalyticsManager`** (New)
   - Used by enterprise features
   - Constructor: `__construct(MetricsCollector $metricsCollector)`
   - Advanced analytics with multiple drivers

Both are registered in the service provider:
- Legacy as `AnalyticsManager::class`
- New as `NewAnalyticsManager` alias

This dual-system approach maintains backward compatibility while providing enhanced analytics features.
