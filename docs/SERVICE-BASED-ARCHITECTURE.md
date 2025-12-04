# Service-Based Architecture for Vectorization

## Overview

The vectorization system has been refactored into dedicated services for better maintainability, testability, and separation of concerns.

---

## Architecture

### Before (Fat Trait)

```php
trait Vectorizable
{
    // ❌ 500+ lines
    // ❌ Multiple responsibilities
    // ❌ Hard to test
    // ❌ Hard to maintain
    
    public function getVectorContent() { /* 100 lines */ }
    public function autoDetectFields() { /* 80 lines */ }
    public function chunkContent() { /* 60 lines */ }
    public function calculateTokens() { /* 40 lines */ }
    // ... many more methods
}
```

### After (Service-Based)

```php
trait Vectorizable
{
    // ✅ ~50 lines
    // ✅ Single responsibility
    // ✅ Easy to test
    // ✅ Easy to maintain
    
    public function getVectorContent(): string
    {
        return app(VectorContentBuilder::class)->build($this);
    }
    
    public function getVectorContentChunks(): array
    {
        return app(VectorContentBuilder::class)->buildChunks($this);
    }
}
```

---

## Services

### 1. VectorizableFieldDetector

**Responsibility:** Detect which fields should be vectorized

**Methods:**
- `detect(Model $model): array` - Auto-detect fields
- `clearCache(Model $model): void` - Clear cache

**Example:**
```php
$detector = app(VectorizableFieldDetector::class);
$result = $detector->detect($email);

// Returns:
[
    'fields' => ['subject', 'body'],
    'source' => 'auto-detected'
]
```

---

### 2. ContentExtractor

**Responsibility:** Extract content from model fields

**Methods:**
- `extract(Model $model, array $fields): array` - Extract content

**Example:**
```php
$extractor = app(ContentExtractor::class);
$result = $extractor->extract($email, ['subject', 'body']);

// Returns:
[
    'content' => ['Important Email', 'See details'],
    'chunked_fields' => []
]
```

---

### 3. TokenCalculator

**Responsibility:** Calculate token limits for models

**Methods:**
- `getLimit(string $model): int` - Get token limit
- `estimate(string $text): int` - Estimate tokens

**Example:**
```php
$calculator = app(TokenCalculator::class);
$limit = $calculator->getLimit('text-embedding-3-large');
// Returns: 8191

$tokens = $calculator->estimate('Hello world');
// Returns: 8
```

---

### 4. ContentChunker

**Responsibility:** Chunk or truncate content

**Methods:**
- `split(string $content, string $modelClass, ?int $modelId): array` - Split into chunks
- `truncate(string $content, string $embeddingModel): string` - Truncate content

**Example:**
```php
$chunker = app(ContentChunker::class);

// Split strategy
$chunks = $chunker->split($longContent, Email::class, 123);
// Returns: ['chunk1', 'chunk2', 'chunk3']

// Truncate strategy
$truncated = $chunker->truncate($longContent, 'text-embedding-3-large');
// Returns: 'truncated content...'
```

---

### 5. VectorContentBuilder (Orchestrator)

**Responsibility:** Orchestrate all services

**Methods:**
- `build(Model $model): string` - Build single content
- `buildChunks(Model $model): array` - Build chunks
- `buildFullContent(Model $model): string` - Build full content

**Example:**
```php
$builder = app(VectorContentBuilder::class);

// Single content
$content = $builder->build($email);

// Multiple chunks
$chunks = $builder->buildChunks($email);
```

---

## Benefits

### 1. Single Responsibility ✅

Each service has one clear purpose:
- `VectorizableFieldDetector` → Detect fields
- `ContentExtractor` → Extract content
- `TokenCalculator` → Calculate tokens
- `ContentChunker` → Chunk content
- `VectorContentBuilder` → Orchestrate

### 2. Testability ✅

Easy to test in isolation:

```php
class ContentExtractorTest extends TestCase
{
    public function test_extracts_content()
    {
        $extractor = new ContentExtractor();
        $model = new Email(['subject' => 'Test', 'body' => 'Content']);
        
        $result = $extractor->extract($model, ['subject', 'body']);
        
        $this->assertEquals(['Test', 'Content'], $result['content']);
    }
}
```

### 3. Maintainability ✅

Changes are isolated:
- Need to change chunking? → Edit `ContentChunker`
- Need to change detection? → Edit `VectorizableFieldDetector`
- No risk of breaking other parts

### 4. Reusability ✅

Services can be used independently:

```php
// Use field detector elsewhere
$detector = app(VectorizableFieldDetector::class);
$fields = $detector->detect($anyModel);

// Use token calculator elsewhere
$calculator = app(TokenCalculator::class);
$limit = $calculator->getLimit($anyModel);
```

### 5. Dependency Injection ✅

Easy to mock and swap:

```php
// Test with mock
$this->mock(ContentChunker::class, function ($mock) {
    $mock->shouldReceive('split')
         ->andReturn(['mocked chunk']);
});

// Swap implementation
app()->bind(TokenCalculator::class, CustomTokenCalculator::class);
```

---

## Service Registration

Services are automatically registered in `AIEngineServiceProvider`:

```php
public function register(): void
{
    // Vectorization services
    $this->app->singleton(VectorizableFieldDetector::class);
    $this->app->singleton(ContentExtractor::class);
    $this->app->singleton(TokenCalculator::class);
    $this->app->singleton(ContentChunker::class);
    $this->app->singleton(VectorContentBuilder::class);
}
```

---

## Usage in Trait

The `Vectorizable` trait becomes a thin wrapper:

```php
trait Vectorizable
{
    public function getVectorContent(): string
    {
        return app(VectorContentBuilder::class)->build($this);
    }
    
    public function getVectorContentChunks(): array
    {
        return app(VectorContentBuilder::class)->buildChunks($this);
    }
}
```

**Benefits:**
- ✅ Trait stays small (~50 lines)
- ✅ All logic in services
- ✅ Easy to test
- ✅ Easy to extend

---

## Testing

### Unit Tests

Test each service independently:

```php
// Test field detector
class VectorizableFieldDetectorTest extends TestCase
{
    public function test_detects_fields_from_schema()
    {
        $detector = new VectorizableFieldDetector();
        $model = new Email();
        
        $result = $detector->detect($model);
        
        $this->assertArrayHasKey('fields', $result);
        $this->assertArrayHasKey('source', $result);
    }
}

// Test content extractor
class ContentExtractorTest extends TestCase
{
    public function test_extracts_content_from_fields()
    {
        $extractor = new ContentExtractor();
        $model = new Email(['subject' => 'Test']);
        
        $result = $extractor->extract($model, ['subject']);
        
        $this->assertEquals(['Test'], $result['content']);
    }
}
```

### Integration Tests

Test the full flow:

```php
class VectorContentBuilderTest extends TestCase
{
    public function test_builds_vector_content()
    {
        $builder = app(VectorContentBuilder::class);
        $email = Email::create([
            'subject' => 'Test',
            'body' => 'Content',
        ]);
        
        $content = $builder->build($email);
        
        $this->assertStringContainsString('Test', $content);
        $this->assertStringContainsString('Content', $content);
    }
}
```

---

## Extending

### Add Custom Field Detector

```php
class CustomFieldDetector extends VectorizableFieldDetector
{
    protected function selectBestFields(array $textColumns, array $columnTypes): array
    {
        // Custom logic
        return ['custom_field_1', 'custom_field_2'];
    }
}

// Register
app()->bind(VectorizableFieldDetector::class, CustomFieldDetector::class);
```

### Add Custom Chunker

```php
class CustomChunker extends ContentChunker
{
    public function split(string $content, string $modelClass, ?int $modelId = null): array
    {
        // Custom chunking logic
        return str_split($content, 1000);
    }
}

// Register
app()->bind(ContentChunker::class, CustomChunker::class);
```

---

## Comparison

### Before (Fat Trait)

```
Vectorizable.php: 500+ lines
├── Field detection (80 lines)
├── Content extraction (100 lines)
├── Token calculation (60 lines)
├── Chunking (80 lines)
├── Truncation (40 lines)
├── Media integration (60 lines)
└── Logging (80 lines)

❌ Hard to test
❌ Hard to maintain
❌ Violates SRP
❌ Tight coupling
```

### After (Service-Based)

```
Vectorizable.php: ~50 lines (thin wrapper)

Services/Vectorization/
├── VectorizableFieldDetector.php (170 lines)
├── ContentExtractor.php (80 lines)
├── TokenCalculator.php (110 lines)
├── ContentChunker.php (120 lines)
└── VectorContentBuilder.php (140 lines)

✅ Easy to test
✅ Easy to maintain
✅ Follows SRP
✅ Loose coupling
```

---

## Summary

**Advantages:**
- ✅ Single Responsibility Principle
- ✅ Easy to test
- ✅ Easy to maintain
- ✅ Reusable services
- ✅ Dependency injection
- ✅ Easy to extend
- ✅ Better code organization

**Migration:**
- ✅ Backward compatible
- ✅ No breaking changes
- ✅ Gradual adoption possible

**Status: Recommended Architecture** ✅
