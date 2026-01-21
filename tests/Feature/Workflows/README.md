# Workflow Tests

This directory contains comprehensive test cases for the Laravel AI Engine workflow functionality.

## Test Coverage

### 1. PriceDisplayTest.php
Tests the fix for product prices showing as $0 in confirmation messages.

**What it tests:**
- Complete product data (including `sale_price` and `purchase_price`) is fetched from database after subflow creation
- Confirmation messages display correct prices and totals
- Existing products include price information
- No $0 prices appear in messages

**Key scenarios:**
- Product creation via subflow
- Multiple products with different prices
- Line totals and grand totals calculation

### 2. FriendlyNameTest.php
Tests the `friendlyName` support in prompt generation.

**What it tests:**
- AI uses "category name" instead of "category_id" in prompts
- Custom prompts take highest priority
- Validation hints are included in prompts
- Examples are included when available
- Fallback to field name when no friendly name provided

**Key scenarios:**
- Entity field with friendly name (e.g., category_id → "category name")
- Custom prompt override
- Validation requirements in prompts

### 3. AIValidationTest.php
Tests AI-driven validation instead of programmatic validation.

**What it tests:**
- Validation requirements are passed to AI for contextual understanding
- Data is collected without programmatic validation blocking the flow
- Invalid data is extracted for AI to handle conversationally
- Validation rules are properly formatted for AI
- Multiple validation rules (email, numeric, min, max, url)

**Key scenarios:**
- Email validation
- Numeric validation with min/max
- URL validation
- AI handles validation naturally through conversation

### 4. EntityResolutionTest.php
Tests entity resolution with subflows.

**What it tests:**
- Existing entities are resolved by name
- Subflows are triggered for missing entities
- Multiple entities are resolved correctly
- Complete entity data is stored after subflow completion
- Filters are applied during resolution
- Mixed existing and new entities are handled

**Key scenarios:**
- Single entity resolution
- Multiple entity resolution
- Subflow triggering
- Entity data completeness
- Workspace filtering

## Running the Tests

### Run all workflow tests:
```bash
cd /Volumes/M.2/Work/laravel-ai-demo/packages/laravel-ai-engine
./vendor/bin/phpunit tests/Feature/Workflows
```

### Run specific test file:
```bash
./vendor/bin/phpunit tests/Feature/Workflows/PriceDisplayTest.php
./vendor/bin/phpunit tests/Feature/Workflows/FriendlyNameTest.php
./vendor/bin/phpunit tests/Feature/Workflows/AIValidationTest.php
./vendor/bin/phpunit tests/Feature/Workflows/EntityResolutionTest.php
```

### Run specific test method:
```bash
./vendor/bin/phpunit --filter it_fetches_complete_product_data_after_subflow_creation
./vendor/bin/phpunit --filter it_uses_friendly_name_in_prompt_generation
./vendor/bin/phpunit --filter it_includes_validation_requirements_in_extraction_prompt
./vendor/bin/phpunit --filter it_resolves_existing_entity_by_name
```

### Run with verbose output:
```bash
./vendor/bin/phpunit tests/Feature/Workflows --testdox
```

### Run with coverage (requires Xdebug):
```bash
./vendor/bin/phpunit tests/Feature/Workflows --coverage-html coverage
```

## Test Structure

All tests extend `LaravelAIEngine\Tests\TestCase` which provides:
- In-memory SQLite database
- Mock AI services
- Helper methods for creating test data
- Automatic cleanup after each test

## What Was Fixed

### Price Display Issue
**Problem:** Products showed $0 in confirmation messages after subflow creation.

**Solution:** Modified `GenericEntityResolver` to fetch complete product entity from database after subflow creation, ensuring all fields (including `sale_price` and `purchase_price`) are included in the validated data.

**Files changed:**
- `src/Services/GenericEntityResolver.php` (lines 307-338)
- `app/AI/Workflows/DeclarativeInvoiceWorkflow.php` (line 253)

### Category Prompt Issue
**Problem:** AI asked for "category ID" instead of "category name".

**Solution:** 
1. Added `friendlyName` property to `EntityFieldConfig`
2. Modified `IntelligentPromptGenerator` to use `friendlyName` when generating prompts
3. Updated `ProductService` model to set `friendlyName('category name')` for category field

**Files changed:**
- `src/Services/IntelligentPromptGenerator.php` (lines 169, 193-231)
- `packages/workdo/ProductService/src/Entities/ProductService.php` (line 312)

### Validation Approach
**Problem:** Rigid programmatic validation was breaking conversational flow.

**Solution:** Removed programmatic validation checks and enhanced AI prompts with validation awareness, allowing AI to handle validation naturally through conversation.

**Files changed:**
- `src/Services/Agent/WorkflowDataCollector.php` (removed lines 132-162, added lines 272-300)
- `src/Services/IntelligentPromptGenerator.php` (lines 193-231)

## Continuous Integration

These tests should be run:
- Before committing changes
- In CI/CD pipeline
- Before releasing new versions
- After updating dependencies

## Contributing

When adding new workflow features:
1. Add corresponding test cases
2. Follow existing test structure
3. Mock external dependencies (AI services, HTTP clients)
4. Test both success and failure scenarios
5. Update this README with new test coverage
