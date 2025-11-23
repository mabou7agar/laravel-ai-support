# Laravel AI Engine - Laravel 9 Compatible Version

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-9.x-red.svg)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A comprehensive Laravel package for multi-AI engine integration with credit management, streaming, interactive chat components, and advanced features - **optimized for Laravel 9**.

## 🎯 Laravel 9 Compatibility

This version has been specifically refactored to support Laravel 9 and PHP 8.0:

- ✅ **PHP 8.0+** compatible (no PHP 8.1 features)
- ✅ **Laravel 9.x** compatible
- ✅ Class-based enums instead of native enums
- ✅ Traditional properties with getters instead of readonly
- ✅ All core features fully functional

## 📦 Installation

```bash
composer require m-tech-stack/laravel-ai-engine:^2.1-laravel9
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=ai-engine-config
```

Run the migrations:

```bash
php artisan migrate
```

## 🔄 Key Differences from Laravel 10+ Version

### 1. Enum Usage

**Laravel 10+ Version:**
```php
use LaravelAIEngine\Enums\EngineEnum;

$engine = EngineEnum::OPENAI; // Native enum
```

**Laravel 9 Version:**
```php
use LaravelAIEngine\Enums\EngineEnum;

$engine = new EngineEnum(EngineEnum::OPENAI); // Class-based enum
// or
$engine = EngineEnum::from('openai');
```

### 2. DTO Property Access

**Laravel 10+ Version:**
```php
echo $request->prompt; // Direct readonly property access
echo $request->engine->value;
```

**Laravel 9 Version:**
```php
// Option 1: Use getters (recommended)
echo $request->getPrompt();
echo $request->getEngine()->value;

// Option 2: Magic getter (backward compatible)
echo $request->prompt; // Works via __get()
echo $request->engine->value;
```

### 3. Creating Requests

Both versions support the same fluent API:

```php
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

$response = Engine::engine(new EngineEnum(EngineEnum::OPENAI))
    ->model(new EntityEnum(EntityEnum::GPT_4O))
    ->temperature(0.8)
    ->maxTokens(1000)
    ->send([
        ['role' => 'user', 'content' => 'Hello!']
    ]);
```

## 🚀 Quick Start

### Basic Usage

```php
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Enums\EngineEnum;

// Simple chat completion
$response = Engine::send([
    ['role' => 'user', 'content' => 'Hello, how are you?']
]);

echo $response->getContent(); // Using getter
// or
echo $response->content; // Magic getter (backward compatible)
```

### With Specific Engine

```php
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

$engine = EngineEnum::from('openai');
$model = EntityEnum::from('gpt-4o');

$response = Engine::engine($engine)
    ->model($model)
    ->temperature(0.7)
    ->send([
        ['role' => 'user', 'content' => 'Explain Laravel']
    ]);
```

### Streaming Responses

```php
$stream = Engine::stream([
    ['role' => 'user', 'content' => 'Tell me a story']
]);

foreach ($stream as $chunk) {
    echo $chunk;
}
```

## 📚 Full Documentation

All features from the main version are supported:

- ✅ Multi-Engine Support (OpenAI, Anthropic, Gemini, etc.)
- ✅ Streaming Responses
- ✅ Credit Management
- ✅ Conversation Memory
- ✅ Interactive Actions
- ✅ Job Queue System
- ✅ Rate Limiting
- ✅ Automatic Failover
- ✅ WebSocket Streaming
- ✅ Analytics & Monitoring

See the main [README.md](README.md) for complete feature documentation.

## 🔧 Configuration

The configuration is identical to the Laravel 10+ version:

```env
# OpenAI
OPENAI_API_KEY=your_openai_api_key

# Anthropic
ANTHROPIC_API_KEY=your_anthropic_api_key

# Gemini
GEMINI_API_KEY=your_gemini_api_key

# Default engine
AI_ENGINE_DEFAULT=openai

# Credit system
AI_CREDITS_ENABLED=true
AI_DEFAULT_CREDITS=100.0
```

## 🧪 Testing

```bash
# Run tests
composer test

# Test AI engines
php artisan ai-engine:test

# Check system health
php artisan ai-engine:health
```

## 📊 Migration from Laravel 10+ Version

If you're migrating from the Laravel 10+ version:

1. **Update composer.json:**
   ```bash
   composer require m-tech-stack/laravel-ai-engine:^2.1-laravel9
   ```

2. **Update enum usage:**
   ```php
   // Before
   $engine = EngineEnum::OPENAI;
   
   // After
   $engine = new EngineEnum(EngineEnum::OPENAI);
   // or
   $engine = EngineEnum::from('openai');
   ```

3. **Update DTO property access (optional):**
   ```php
   // Before
   echo $request->prompt;
   
   // After (recommended)
   echo $request->getPrompt();
   
   // Or keep using magic getter (backward compatible)
   echo $request->prompt; // Still works!
   ```

See [LARAVEL9-MIGRATION.md](LARAVEL9-MIGRATION.md) for detailed migration guide.

## 🆚 Version Comparison

| Feature | Laravel 10+ | Laravel 9 |
|---------|-------------|-----------|
| PHP Version | 8.1+ | 8.0+ |
| Laravel Version | 10.x, 11.x, 12.x | 9.x |
| Native Enums | ✅ | ❌ (Class-based) |
| Readonly Properties | ✅ | ❌ (Getters) |
| Match Expressions | ✅ | ✅ |
| All AI Features | ✅ | ✅ |
| Performance | Excellent | Excellent |
| Backward Compatible | N/A | ✅ |

## 💡 Tips for Laravel 9 Users

### 1. Use Static Factory Methods

```php
// Cleaner enum creation
$engine = EngineEnum::from('openai');
$model = EntityEnum::from('gpt-4o');
```

### 2. Leverage Magic Getters

The magic `__get()` method provides backward compatibility:

```php
// Both work identically
echo $request->getPrompt(); // Explicit getter
echo $request->prompt;      // Magic getter
```

### 3. Type Hints Still Work

```php
function processRequest(AIRequest $request): AIResponse
{
    // Type safety is maintained
    return Engine::send($request);
}
```

## 🐛 Known Limitations

1. **Enum Comparison**: Use value comparison instead of identity
   ```php
   // Don't use ===
   if ($engine === EngineEnum::OPENAI) // ❌ Won't work
   
   // Use value comparison
   if ($engine->value === EngineEnum::OPENAI) // ✅ Works
   ```

2. **Enum in Arrays**: Create instances explicitly
   ```php
   // Don't use enum cases directly
   $engines = [EngineEnum::OPENAI, EngineEnum::ANTHROPIC]; // ❌
   
   // Create instances
   $engines = [
       EngineEnum::from('openai'),
       EngineEnum::from('anthropic')
   ]; // ✅
   ```

## 📝 License

MIT License - see [LICENSE](LICENSE) file for details.

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 📧 Support

- **Issues**: [GitHub Issues](https://github.com/mabou7agar/laravel-ai-engine/issues)
- **Email**: m.abou7agar@gmail.com
- **Tag**: Use `laravel-9` label for Laravel 9 specific issues

## 🙏 Credits

- **Author**: Mohamed Abou Hagar
- **Laravel 9 Compatibility**: Refactored for PHP 8.0 compatibility

---

**Note**: This is the Laravel 9 compatible version. For Laravel 10+, use version `^2.1` instead of `^2.1-laravel9`.
