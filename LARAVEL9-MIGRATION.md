# Laravel 9 Migration Guide

This document outlines the changes made to support Laravel 9 (PHP 8.0) compatibility.

## Overview

The Laravel AI Engine package has been refactored to support Laravel 9 by removing PHP 8.1+ specific features:

### Key Changes

1. **PHP Version**: Downgraded from `^8.1` to `^8.0`
2. **Laravel Version**: Downgraded from `^10.0|^11.0|^12.0` to `^9.0`
3. **Native Enums**: Converted to class-based constants
4. **Readonly Properties**: Removed and replaced with traditional properties + getters
5. **Match Expressions**: Replaced with switch statements
6. **Symfony**: Downgraded from `^6.0|^7.0` to `^5.4|^6.0`
7. **PHPUnit**: Downgraded from `^10.0` to `^9.5`
8. **Orchestra Testbench**: Downgraded from `^8.0|^9.0` to `^7.0`

## Files Modified

### 1. Composer Dependencies (`composer.json`)

```json
{
    "require": {
        "php": "^8.0",
        "illuminate/support": "^9.0",
        "illuminate/http": "^9.0",
        "illuminate/cache": "^9.0",
        "illuminate/queue": "^9.0",
        "symfony/http-client": "^5.4|^6.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.5",
        "orchestra/testbench": "^7.0"
    }
}
```

### 2. Enum Conversion

#### Before (PHP 8.1+ Enum):
```php
enum EngineEnum: string
{
    case OPENAI = 'openai';
    case ANTHROPIC = 'anthropic';
    
    public function driverClass(): string
    {
        return match ($this) {
            self::OPENAI => OpenAIEngineDriver::class,
            self::ANTHROPIC => AnthropicEngineDriver::class,
        };
    }
}
```

#### After (PHP 8.0 Class):
```php
class EngineEnum
{
    public const OPENAI = 'openai';
    public const ANTHROPIC = 'anthropic';
    
    public string $value;
    
    public function __construct(string $value)
    {
        $this->value = $value;
    }
    
    public function driverClass(): string
    {
        switch ($this->value) {
            case self::OPENAI:
                return OpenAIEngineDriver::class;
            case self::ANTHROPIC:
                return AnthropicEngineDriver::class;
            default:
                throw new \InvalidArgumentException("Unknown engine: {$this->value}");
        }
    }
    
    public static function from(string $value): self
    {
        return new self($value);
    }
    
    public static function cases(): array
    {
        return [
            new self(self::OPENAI),
            new self(self::ANTHROPIC),
            // ... all cases
        ];
    }
}
```

### 3. DTO Readonly Properties Removal

#### Before (PHP 8.1+ Readonly):
```php
class AIRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly EngineEnum $engine,
        public readonly EntityEnum $model,
    ) {}
}
```

#### After (PHP 8.0 Compatible):
```php
class AIRequest
{
    private string $prompt;
    private EngineEnum $engine;
    private EntityEnum $model;
    
    public function __construct(
        string $prompt,
        EngineEnum $engine,
        EntityEnum $model
    ) {
        $this->prompt = $prompt;
        $this->engine = $engine;
        $this->model = $model;
    }
    
    public function getPrompt(): string
    {
        return $this->prompt;
    }
    
    public function getEngine(): EngineEnum
    {
        return $this->engine;
    }
    
    public function getModel(): EntityEnum
    {
        return $this->model;
    }
}
```

### 4. Match Expression Conversion

#### Before (PHP 8.0+ Match):
```php
return match ($this->value) {
    'openai' => OpenAIEngineDriver::class,
    'anthropic' => AnthropicEngineDriver::class,
};
```

#### After (PHP 8.0 Switch):
```php
switch ($this->value) {
    case 'openai':
        return OpenAIEngineDriver::class;
    case 'anthropic':
        return AnthropicEngineDriver::class;
    default:
        throw new \InvalidArgumentException("Unknown value: {$this->value}");
}
```

## Installation for Laravel 9

```bash
composer require m-tech-stack/laravel-ai-engine:^2.1-laravel9
```

## Usage Changes

### Creating Enum Instances

#### Before:
```php
$engine = EngineEnum::OPENAI;
```

#### After:
```php
$engine = new EngineEnum(EngineEnum::OPENAI);
// or
$engine = EngineEnum::from('openai');
```

### Accessing DTO Properties

#### Before:
```php
echo $request->prompt;
echo $request->engine->value;
```

#### After:
```php
echo $request->getPrompt();
echo $request->getEngine()->value;
```

## Testing

After migration, run:

```bash
composer update
php artisan vendor:publish --tag=ai-engine-config
php artisan migrate
php artisan ai-engine:test
```

## Compatibility Matrix

| Feature | Laravel 10+ | Laravel 9 |
|---------|-------------|-----------|
| PHP Version | 8.1+ | 8.0+ |
| Native Enums | ✅ | ❌ (Class-based) |
| Readonly Properties | ✅ | ❌ (Getters) |
| Match Expressions | ✅ | ✅ (Available in PHP 8.0) |
| All Core Features | ✅ | ✅ |

## Notes

- All core functionality remains the same
- API interface is mostly backward compatible
- Performance impact is negligible
- Enum instances are now objects instead of native enums
- DTOs use getters instead of public readonly properties

## Support

For issues specific to Laravel 9 compatibility, please open an issue with the `laravel-9` label.
