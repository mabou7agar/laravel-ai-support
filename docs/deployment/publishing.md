# 📦 Publishing Package Assets

Laravel AI Engine provides several publishable assets that you can customize for your application.

## Table of Contents

- [Overview](#overview)
- [Configuration](#configuration)
- [Migrations](#migrations)
- [Views](#views)
- [Components](#components)
- [Assets](#assets)
- [Routes](#routes)
- [Publishing All](#publishing-all)

---

## Overview

All package assets can be published using Laravel's `vendor:publish` command with specific tags.

```bash
# List all available tags
php artisan vendor:publish --provider="LaravelAIEngine\AIEngineServiceProvider"
```

---

## Configuration

Publish the configuration file to customize AI engines, models, and package settings:

```bash
php artisan vendor:publish --tag=ai-engine-config
```

**Published to:** `config/ai-engine.php`

### What You Can Configure

- AI engine credentials (OpenAI, Anthropic, Google)
- Default models and parameters
- RAG settings
- Node configuration
- Cache settings
- Rate limiting
- Multi-tenancy options

---

## Migrations

Publish database migrations for conversations, analytics, and vector storage:

```bash
php artisan vendor:publish --tag=ai-engine-migrations
```

**Published to:** `database/migrations/`

After publishing, run migrations:

```bash
php artisan migrate
```

---

## Views

Publish Blade views to customize the UI components:

```bash
php artisan vendor:publish --tag=ai-engine-views
```

**Published to:** `resources/views/vendor/ai-engine/`

### Available Views

- Chat interface
- Data collector component
- RAG search interface
- Action execution UI
- Analytics dashboards

### View Loading Behavior

Laravel automatically checks for published views first:
1. `resources/views/vendor/ai-engine/` (published - takes precedence)
2. `packages/laravel-ai-engine/resources/views/` (package - fallback)

**No code changes needed** - just publish and customize!

---

## Components

Publish Blade components for manual registration (Laravel 8):

```bash
php artisan vendor:publish --tag=ai-engine-components
```

**Published to:** `resources/views/components/ai-engine/`

### Available Components

- `<x-ai-engine::data-collector />` - Conversational form component
- `<x-ai-engine::chat />` - Chat interface
- `<x-ai-engine::rag-search />` - RAG search interface

---

## Assets

Publish JavaScript and CSS assets:

```bash
php artisan vendor:publish --tag=ai-engine-assets
```

**Published to:** `public/vendor/ai-engine/js/`

### Included Assets

- Data collector JavaScript
- Chat interface scripts
- WebSocket client
- Styling utilities

---

## Routes

Publish API routes to customize endpoints:

```bash
# Publish main API routes (Data Collector, RAG, Actions)
php artisan vendor:publish --tag=ai-engine-routes

# Publish node API routes
php artisan vendor:publish --tag=ai-engine-node-routes
```

**Published to:**
- `routes/ai-engine-api.php` - Main API routes
- `routes/ai-engine-node-api.php` - Node management routes

### Route Loading Behavior

The package uses **conditional loading** with automatic fallback:

```php
// In AIEngineServiceProvider
$publishedApiRoutes = base_path('routes/ai-engine-api.php');
if (file_exists($publishedApiRoutes)) {
    $this->loadRoutesFrom($publishedApiRoutes);
} else {
    $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
}
```

**Behavior:**
- ✅ Routes load automatically from package by default
- ✅ Published routes take precedence when they exist
- ✅ Delete published file to revert to package routes
- ✅ No code changes needed - automatic fallback

### Available Routes

**Main API Routes (`ai-engine-routes`):**
- `/api/v1/data-collector/*` - Data Collector endpoints
- `/api/v1/rag/*` - RAG Chat API
- `/api/v1/modules/*` - Module management
- `/api/v1/actions/*` - Action execution

**Node API Routes (`ai-engine-node-routes`):**
- `/api/v1/nodes/*` - Node registration and management
- `/api/v1/nodes/health` - Health checks
- `/api/v1/nodes/search` - Federated search

### Customizing Routes

After publishing, you can:

```php
// routes/ai-engine-api.php

// Change route prefix
Route::prefix('api/v2/ai')  // Instead of api/v1
    ->middleware(['api', 'auth'])  // Add auth middleware
    ->group(function () {
        // Your customized routes
    });

// Add custom middleware
Route::middleware(['api', 'throttle:60,1'])
    ->group(function () {
        // Rate-limited routes
    });

// Change endpoint names
Route::post('/collect/start', [DataCollectorController::class, 'start'])
    ->name('custom.collector.start');
```

---

## Publishing All

Publish all assets at once:

```bash
php artisan vendor:publish --provider="LaravelAIEngine\AIEngineServiceProvider"
```

Or publish specific combinations:

```bash
# Essential files only
php artisan vendor:publish --tag=ai-engine-config
php artisan vendor:publish --tag=ai-engine-migrations

# Frontend customization
php artisan vendor:publish --tag=ai-engine-views
php artisan vendor:publish --tag=ai-engine-components
php artisan vendor:publish --tag=ai-engine-assets

# API customization
php artisan vendor:publish --tag=ai-engine-routes
php artisan vendor:publish --tag=ai-engine-node-routes
```

---

## Best Practices

### 1. Publish Only What You Need

Don't publish everything - only publish assets you plan to customize:

```bash
# ✅ Good - publish only config
php artisan vendor:publish --tag=ai-engine-config

# ❌ Avoid - publishing everything unnecessarily
php artisan vendor:publish --provider="LaravelAIEngine\AIEngineServiceProvider"
```

### 2. Version Control

Add published files to version control:

```gitignore
# .gitignore

# Keep published config
!config/ai-engine.php

# Keep customized views
!resources/views/vendor/ai-engine/

# Keep customized routes
!routes/ai-engine-api.php
```

### 3. Update Strategy

When updating the package:

1. **Review changes** in package files
2. **Merge updates** into your published files
3. **Test thoroughly** before deploying

```bash
# Compare package vs published
diff packages/laravel-ai-engine/config/ai-engine.php config/ai-engine.php
```

### 4. Route Customization

If you customize routes, document the changes:

```php
// routes/ai-engine-api.php

/**
 * Custom AI Engine Routes
 * 
 * Changes from package default:
 * - Added authentication middleware
 * - Changed prefix from v1 to v2
 * - Added rate limiting
 * 
 * Last synced with package: 2024-12-25
 */
```

---

## Reverting Published Assets

To revert to package defaults:

### Delete Published Files

```bash
# Remove published config
rm config/ai-engine.php

# Remove published views
rm -rf resources/views/vendor/ai-engine/

# Remove published routes
rm routes/ai-engine-api.php
rm routes/ai-engine-node-api.php
```

### Clear Caches

```bash
php artisan config:clear
php artisan view:clear
php artisan route:clear
```

The package will automatically use its default files.

---

## Summary Table

| Tag | Command | Output | Auto-Fallback |
|-----|---------|--------|---------------|
| `ai-engine-config` | `vendor:publish --tag=ai-engine-config` | `config/ai-engine.php` | ✅ Yes |
| `ai-engine-migrations` | `vendor:publish --tag=ai-engine-migrations` | `database/migrations/*` | N/A |
| `ai-engine-views` | `vendor:publish --tag=ai-engine-views` | `resources/views/vendor/ai-engine/*` | ✅ Yes |
| `ai-engine-components` | `vendor:publish --tag=ai-engine-components` | `resources/views/components/ai-engine/*` | ✅ Yes |
| `ai-engine-assets` | `vendor:publish --tag=ai-engine-assets` | `public/vendor/ai-engine/js/*` | N/A |
| `ai-engine-routes` | `vendor:publish --tag=ai-engine-routes` | `routes/ai-engine-api.php` | ✅ Yes |
| `ai-engine-node-routes` | `vendor:publish --tag=ai-engine-node-routes` | `routes/ai-engine-node-api.php` | ✅ Yes |

---

## Related Documentation

- [Installation Guide](installation.md)
- [Configuration](configuration.md)
- [Data Collector](data-collector.md)
- [API Reference](../README.md#api-endpoints)

---

## Support

- **Issues**: [GitHub Issues](https://github.com/mabou7agar/laravel-ai-support/issues)
- **Discussions**: [GitHub Discussions](https://github.com/mabou7agar/laravel-ai-support/discussions)
- **Email**: support@m-tech-stack.com
