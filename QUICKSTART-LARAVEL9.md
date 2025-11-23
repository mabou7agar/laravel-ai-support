# Laravel 9 Version - Quick Start Guide

## 🎯 Current Status

✅ **Branch Created**: `laravel-9-support`  
✅ **Dependencies Updated**: `composer.json` configured for Laravel 9  
✅ **Documentation Ready**: Migration guide, README, and TODO list created  
⚠️ **Code Conversion**: In progress (see TODO-LARAVEL9.md)

## 📦 What's Been Done

### 1. Branch & Dependencies
- Created `laravel-9-support` branch
- Updated `composer.json`:
  - PHP: `^8.0` (was `^8.1`)
  - Laravel: `^9.0` (was `^10.0|^11.0|^12.0`)
  - Symfony: `^5.4|^6.0` (was `^6.0|^7.0`)
  - PHPUnit: `^9.5` (was `^10.0`)
  - Orchestra Testbench: `^7.0` (was `^8.0|^9.0`)

### 2. Documentation Created
- **LARAVEL9-MIGRATION.md**: Complete migration guide
- **README-LARAVEL9.md**: Laravel 9 specific README
- **TODO-LARAVEL9.md**: Detailed conversion checklist
- **AIRequest.laravel9.php**: Example Laravel 9 compatible DTO

### 3. Helper Scripts
- **convert-to-laravel9.sh**: Conversion helper script
- **scripts/convert-enums.php**: Enum conversion automation

## 🚀 Next Steps

### Option 1: Manual Conversion (Recommended for Learning)

Follow the TODO list in `TODO-LARAVEL9.md`:

1. **Convert Enums** (3 files):
   ```bash
   # Edit these files:
   src/Enums/EngineEnum.php
   src/Enums/EntityEnum.php
   src/Enums/ActionTypeEnum.php
   ```

2. **Convert DTOs** (4 files):
   ```bash
   # Edit these files:
   src/DTOs/AIRequest.php      # Use AIRequest.laravel9.php as reference
   src/DTOs/AIResponse.php
   src/DTOs/InteractiveAction.php
   src/DTOs/ActionResponse.php
   ```

3. **Test**:
   ```bash
   composer update
   composer test
   ```

### Option 2: Automated Conversion (Faster)

Use the conversion scripts:

```bash
# Run enum converter
php scripts/convert-enums.php

# Review and test
composer update
composer test
```

### Option 3: Use Pre-converted Files

If you have access to a fully converted version, simply:

```bash
git checkout laravel-9-support
composer update
php artisan vendor:publish --tag=ai-engine-config
php artisan migrate
```

## 📋 Key Changes Required

### 1. Enum Pattern

**Before (PHP 8.1):**
```php
enum EngineEnum: string
{
    case OPENAI = 'openai';
}
```

**After (PHP 8.0):**
```php
class EngineEnum
{
    public const OPENAI = 'openai';
    public string $value;
    
    public function __construct(string $value)
    {
        $this->value = $value;
    }
    
    public static function from(string $value): self
    {
        return new self($value);
    }
}
```

### 2. DTO Pattern

**Before (PHP 8.1):**
```php
class AIRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly EngineEnum $engine,
    ) {}
}
```

**After (PHP 8.0):**
```php
class AIRequest
{
    private string $prompt;
    private EngineEnum $engine;
    
    public function __construct(
        string $prompt,
        EngineEnum $engine
    ) {
        $this->prompt = $prompt;
        $this->engine = $engine;
    }
    
    public function getPrompt(): string
    {
        return $this->prompt;
    }
    
    // Magic getter for backward compatibility
    public function __get(string $name)
    {
        $getter = 'get' . ucfirst($name);
        if (method_exists($this, $getter)) {
            return $this->$getter();
        }
        throw new \InvalidArgumentException("Property {$name} does not exist");
    }
}
```

## 🧪 Testing Checklist

After conversion, test these features:

```bash
# 1. Install dependencies
composer update

# 2. Run PHPUnit tests
composer test

# 3. Test AI engines
php artisan ai-engine:test

# 4. Test basic generation
php artisan tinker
>>> use LaravelAIEngine\Facades\Engine;
>>> $response = Engine::send([['role' => 'user', 'content' => 'Hello']]);
>>> echo $response->content;

# 5. Test streaming
>>> $stream = Engine::stream([['role' => 'user', 'content' => 'Tell me a story']]);
>>> foreach ($stream as $chunk) { echo $chunk; }
```

## 📊 Progress Tracking

| Component | Status | Files |
|-----------|--------|-------|
| Dependencies | ✅ Complete | composer.json |
| Documentation | ✅ Complete | 4 files |
| Enums | ⚠️ In Progress | 3 files |
| DTOs | ⚠️ In Progress | 4 files |
| Services | ⏳ Pending | Multiple |
| Tests | ⏳ Pending | Multiple |

## 🔗 Useful Commands

```bash
# Check current branch
git branch

# View recent commits
git log --oneline -5

# Find files needing conversion
grep -r "^enum " src/ --include="*.php"
grep -r "readonly " src/ --include="*.php"

# Count conversions needed
echo "Enums: $(grep -r "^enum " src/ --include="*.php" | wc -l)"
echo "Readonly: $(grep -r "readonly " src/ --include="*.php" | wc -l)"

# Test after conversion
composer update && composer test
```

## 📚 Documentation Reference

- **LARAVEL9-MIGRATION.md**: Detailed migration guide with code examples
- **README-LARAVEL9.md**: Laravel 9 specific README and usage guide
- **TODO-LARAVEL9.md**: Complete conversion checklist with estimates
- **AIRequest.laravel9.php**: Reference implementation for DTOs

## 💡 Tips

1. **Start with Enums**: They're used everywhere, so convert them first
2. **Use AIRequest.laravel9.php**: It's a complete reference for DTO conversion
3. **Test Incrementally**: Test after each major conversion
4. **Keep Backward Compatibility**: Use magic `__get()` methods
5. **Match Expressions**: They work in PHP 8.0, no need to change them

## 🆘 Need Help?

1. Check `TODO-LARAVEL9.md` for detailed steps
2. Review `LARAVEL9-MIGRATION.md` for code patterns
3. Use `AIRequest.laravel9.php` as a reference
4. Run `php scripts/convert-enums.php` for automation

## 🎯 Success Criteria

- [ ] All tests pass
- [ ] No PHP 8.1+ features in code
- [ ] `composer update` succeeds
- [ ] Package works in Laravel 9 project
- [ ] All core features functional
- [ ] Documentation accurate

---

**Estimated Time**: 8-12 hours for complete conversion  
**Current Branch**: `laravel-9-support`  
**Target Version**: `2.1.1-laravel9`
