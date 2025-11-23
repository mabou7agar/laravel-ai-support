# Laravel 9 Conversion TODO List

## ✅ Completed

- [x] Create `laravel-9-support` branch
- [x] Update `composer.json` dependencies
  - [x] PHP: `^8.1` → `^8.0`
  - [x] Laravel packages: `^10.0|^11.0|^12.0` → `^9.0`
  - [x] Symfony: `^6.0|^7.0` → `^5.4|^6.0`
  - [x] PHPUnit: `^10.0` → `^9.5`
  - [x] Orchestra Testbench: `^8.0|^9.0` → `^7.0`
- [x] Create migration documentation (`LARAVEL9-MIGRATION.md`)
- [x] Create Laravel 9 README (`README-LARAVEL9.md`)
- [x] Create conversion helper scripts
- [x] Create example Laravel 9 compatible DTO (`AIRequest.laravel9.php`)

## 🔄 In Progress

### Enum Conversion (3 files)

#### 1. `src/Enums/EngineEnum.php`
- [ ] Replace `enum EngineEnum: string` with `class EngineEnum`
- [ ] Convert all `case NAME = 'value'` to `public const NAME = 'value'`
- [ ] Add constructor: `public function __construct(public string $value) {}`
- [ ] Add `public static function from(string $value): self`
- [ ] Add `public static function cases(): array`
- [ ] Replace all `match($this)` with `switch($this->value)`
- [ ] Update `all()` method to work with class-based enum

#### 2. `src/Enums/EntityEnum.php`
- [ ] Same conversion steps as EngineEnum
- [ ] This is a large file (~500+ lines) with many model constants
- [ ] Pay special attention to `creditIndex()` and `contentType()` methods

#### 3. `src/Enums/ActionTypeEnum.php`
- [ ] Same conversion steps as EngineEnum
- [ ] Smaller file, should be straightforward

### DTO Conversion (4 files)

#### 1. `src/DTOs/AIRequest.php`
- [ ] Replace file with `AIRequest.laravel9.php` content
- [ ] Remove all `readonly` keywords from properties
- [ ] Make properties private
- [ ] Add getter methods for all properties
- [ ] Add `__get()` magic method for backward compatibility
- [ ] Update constructor to use traditional parameter assignment
- [ ] Test all `with*()` methods work correctly

#### 2. `src/DTOs/AIResponse.php`
- [ ] Remove all `readonly` keywords
- [ ] Add getter methods
- [ ] Add `__get()` magic method
- [ ] Update all methods that create new instances

#### 3. `src/DTOs/InteractiveAction.php`
- [ ] Remove all `readonly` keywords
- [ ] Add getter methods
- [ ] Add `__get()` magic method
- [ ] Update factory methods

#### 4. `src/DTOs/ActionResponse.php`
- [ ] Remove all `readonly` keywords
- [ ] Add getter methods
- [ ] Add `__get()` magic method

## 📋 Pending

### Code Updates

- [ ] Search and replace all `match()` expressions with `switch()` statements
  ```bash
  grep -r "return match" src/
  ```

- [ ] Update all enum instantiations throughout codebase
  ```php
  # Find: EngineEnum::OPENAI
  # Replace with: new EngineEnum(EngineEnum::OPENAI)
  # Or: EngineEnum::from('openai')
  ```

- [ ] Update service providers if needed
  - [ ] Check `AIEngineServiceProvider.php`
  - [ ] Check `LaravelAIEngineServiceProvider.php`

- [ ] Update facades if needed
  - [ ] Check `Facades/AIEngine.php`
  - [ ] Check `Facades/Engine.php`

### Testing

- [ ] Run `composer update` to install Laravel 9 dependencies
- [ ] Fix any dependency conflicts
- [ ] Run PHPUnit tests: `composer test`
- [ ] Test basic AI generation
- [ ] Test streaming
- [ ] Test conversation memory
- [ ] Test interactive actions
- [ ] Test job queue system
- [ ] Test rate limiting
- [ ] Test failover

### Documentation

- [ ] Update main README.md to mention Laravel 9 version
- [ ] Add installation instructions for both versions
- [ ] Create CHANGELOG entry for Laravel 9 version
- [ ] Update package version to `2.1.1-laravel9`

### Publishing

- [ ] Tag release: `git tag v2.1.1-laravel9`
- [ ] Push to GitHub: `git push origin laravel-9-support --tags`
- [ ] Update Packagist (auto-updates via webhook)
- [ ] Test installation: `composer require m-tech-stack/laravel-ai-engine:^2.1-laravel9`

## 🔍 Files to Review

### High Priority (Core functionality)
1. `src/Enums/EngineEnum.php` - 213 lines
2. `src/Enums/EntityEnum.php` - ~500+ lines
3. `src/DTOs/AIRequest.php` - 362 lines
4. `src/DTOs/AIResponse.php` - 468 lines

### Medium Priority (Features)
5. `src/DTOs/InteractiveAction.php`
6. `src/DTOs/ActionResponse.php`
7. `src/Services/AIEngineService.php`
8. `src/Services/UnifiedEngineManager.php`

### Low Priority (Supporting)
9. All driver files in `src/Drivers/`
10. All service files in `src/Services/`
11. All command files in `src/Console/Commands/`

## 🛠️ Automated Conversion Script

Run this to find all files that need conversion:

```bash
# Find all enum declarations
grep -r "^enum " src/ --include="*.php"

# Find all readonly properties
grep -r "readonly " src/ --include="*.php"

# Find all match expressions
grep -r "match (" src/ --include="*.php"

# Count occurrences
echo "Enums: $(grep -r "^enum " src/ --include="*.php" | wc -l)"
echo "Readonly: $(grep -r "readonly " src/ --include="*.php" | wc -l)"
echo "Match: $(grep -r "match (" src/ --include="*.php" | wc -l)"
```

## 📊 Estimated Effort

| Task | Estimated Time | Complexity |
|------|----------------|------------|
| Enum Conversion | 2-3 hours | Medium |
| DTO Conversion | 2-3 hours | Medium |
| Match Expression Replacement | 1-2 hours | Low |
| Testing | 2-3 hours | Medium |
| Documentation | 1 hour | Low |
| **Total** | **8-12 hours** | **Medium** |

## 🎯 Success Criteria

- [ ] All tests pass with Laravel 9 dependencies
- [ ] No PHP 8.1+ features in codebase
- [ ] All core features work identically to Laravel 10+ version
- [ ] Backward compatible API (magic getters work)
- [ ] Documentation is complete and accurate
- [ ] Package installs successfully via Composer
- [ ] Example application works with Laravel 9

## 📝 Notes

- Keep backward compatibility in mind - use magic `__get()` for DTOs
- Enum instances should support both `new EngineEnum()` and `EngineEnum::from()`
- All `match()` expressions can stay (they're PHP 8.0 compatible)
- Focus on enums and readonly properties - those are the main blockers
- Test thoroughly with a fresh Laravel 9 installation

## 🚀 Quick Start Commands

```bash
# Switch to Laravel 9 branch
git checkout laravel-9-support

# Install dependencies (will fail until conversion is complete)
composer update

# Run conversion helper
php scripts/convert-enums.php

# Test after conversion
composer test
php artisan ai-engine:test
```
