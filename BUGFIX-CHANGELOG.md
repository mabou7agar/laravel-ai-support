# Bug Fix Changelog

## [2.1.1] - 2025-11-26

### Fixed

#### Missing Event Listeners - FULLY IMPLEMENTED ✅

**Issue**: 
```
Undefined class 'AnalyticsTrackingListener'
```

**Root Cause**:
- Service provider was registering event listeners for classes that didn't exist
- Referenced events that hadn't been implemented yet

**Solution Implemented**:

**1. Created Missing Event Classes** (7 new events):
- ✅ `AIResponseComplete` - Fired when AI response is fully completed
- ✅ `AIActionTriggered` - Fired when interactive action is triggered
- ✅ `AIStreamingError` - Fired when streaming encounters an error
- ✅ `AISessionStarted` - Fired when new AI session begins
- ✅ `AISessionEnded` - Fired when AI session ends
- ✅ `AIFailoverTriggered` - Fired when failover to backup engine occurs
- ✅ `AIProviderHealthChanged` - Fired when provider health status changes

**2. Created Listener Classes** (3 new listeners):

**AnalyticsTrackingListener**:
- Tracks all AI events for analytics and monitoring
- Integrates with AnalyticsManager for comprehensive tracking
- Methods: `handleResponseChunk`, `handleResponseComplete`, `handleActionTriggered`, `handleStreamingError`, `handleSessionStarted`, `handleSessionEnded`

**StreamingLoggingListener**:
- Logs streaming-related events for debugging
- Uses Laravel's Log facade with appropriate log levels
- Methods: `handleStreamingError`, `handleFailoverTriggered`, `handleProviderHealthChanged`

**StreamingNotificationListener**:
- Sends notifications for critical streaming events
- Filters critical errors and health degradation
- Methods: `handleStreamingError`, `handleFailoverTriggered`, `handleProviderHealthChanged`
- Includes helper methods: `isCriticalError()`, `isHealthDegraded()`

**3. Enabled Event Listener Registration**:
- Uncommented all event listener registrations in service provider
- All 12 event listeners now active and working

**Files Created**:
- `src/Events/AIResponseComplete.php`
- `src/Events/AIActionTriggered.php`
- `src/Events/AIStreamingError.php`
- `src/Events/AISessionStarted.php`
- `src/Events/AISessionEnded.php`
- `src/Events/AIFailoverTriggered.php`
- `src/Events/AIProviderHealthChanged.php`
- `src/Listeners/AnalyticsTrackingListener.php`
- `src/Listeners/StreamingLoggingListener.php`
- `src/Listeners/StreamingNotificationListener.php`

**Files Modified**:
- `src/AIEngineServiceProvider.php`
  - Uncommented all event listener registrations
  - Removed TODO comments

**Impact**:
- ✅ Package loads without errors
- ✅ Event-driven analytics fully operational
- ✅ Streaming error logging enabled
- ✅ Critical event notifications active
- ✅ Complete enterprise-grade event system
- ✅ 12 event listeners registered and working

---

#### Missing Views Publishing Configuration

**Issue**:
```
No publishable resources for tag [ai-engine-views]
```

**Solution**:
- Added views publish configuration with tag `ai-engine-views`
- Moved assets publish inside `runningInConsole()` check
- Removed duplicate assets publish configuration

**Available Tags**:
- `ai-engine-config` → `config/ai-engine.php`
- `ai-engine-migrations` → `database/migrations/`
- `ai-engine-views` → `resources/views/vendor/ai-engine/`
- `ai-engine-assets` → `public/vendor/ai-engine/js/`

---

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
