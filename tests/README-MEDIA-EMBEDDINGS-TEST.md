# Testing HasMediaEmbeddings Trait

This guide explains how to test the `HasMediaEmbeddings` trait to ensure it's working correctly.

## Test Files Created

### 1. Unit Test: `tests/Unit/Traits/HasMediaEmbeddingsTest.php`
Tests the trait in isolation with mocked dependencies.

### 2. Feature Test: `tests/Feature/MediaEmbeddingsIntegrationTest.php`
Tests the trait with real database integration.

## Running the Tests

### Run All Media Embedding Tests
```bash
cd packages/laravel-ai-engine
vendor/bin/phpunit tests/Unit/Traits/HasMediaEmbeddingsTest.php
vendor/bin/phpunit tests/Feature/MediaEmbeddingsIntegrationTest.php
```

### Run Specific Test
```bash
vendor/bin/phpunit --filter it_can_get_media_vector_content
vendor/bin/phpunit --filter it_integrates_with_vectorizable_trait
```

### Run with Verbose Output
```bash
vendor/bin/phpunit tests/Unit/Traits/HasMediaEmbeddingsTest.php --testdox
```

## What the Tests Cover

### Unit Tests (`HasMediaEmbeddingsTest`)

✅ **it_can_get_media_vector_content**
- Tests basic media content extraction
- Verifies MediaEmbeddingService is called correctly

✅ **it_returns_empty_string_when_no_media_fields**
- Tests behavior with no media configured

✅ **it_handles_multiple_media_fields**
- Tests multiple media types (image, audio, video)
- Verifies all media content is combined

✅ **it_skips_missing_media_fields**
- Tests graceful handling of missing files

✅ **it_integrates_with_vectorizable_trait**
- Tests both traits working together
- Verifies text + media content combination

✅ **it_logs_vector_content_generation_with_media**
- Tests debug logging functionality
- Verifies correct log context

✅ **it_handles_media_service_errors_gracefully**
- Tests error handling
- Ensures no exceptions thrown

### Feature Tests (`MediaEmbeddingsIntegrationTest`)

✅ **it_generates_vector_content_with_text_and_media**
- Full integration test with database
- Tests real model creation and retrieval

✅ **it_works_without_media_fields**
- Tests models with only text fields

✅ **it_handles_multiple_media_types**
- Tests all media types together

✅ **it_can_be_saved_and_retrieved**
- Tests database persistence

✅ **it_generates_different_content_for_different_models**
- Verifies unique content per model

✅ **it_logs_debug_information_when_enabled**
- Tests logging in real scenario

## Manual Testing

### 1. Create a Test Model

```php
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Traits\HasMediaEmbeddings;

class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public array $vectorizable = ['title', 'content'];
    
    public array $mediaFields = [
        'image' => 'featured_image',
        'audio' => 'podcast_file',
    ];
}
```

### 2. Test in Tinker

```bash
php artisan tinker
```

```php
// Enable debug logging
config(['ai-engine.debug' => true]);

// Create a post with media
$post = Post::create([
    'title' => 'Test Post',
    'content' => 'Test content',
    'featured_image' => '/path/to/image.jpg',
    'podcast_file' => '/path/to/audio.mp3',
]);

// Get vector content
$content = $post->getVectorContent();
echo $content;

// Check logs
tail -f storage/logs/ai-engine.log
```

### 3. Check Logs

Look for these log entries:

```
[debug] Vector content generated
  model: App\Models\Post
  id: 1
  source: explicit $vectorizable property
  fields_used: ["title", "content"]
  has_media: true
  content_length: 1234
  truncated_length: 1234
  was_truncated: false
```

## Expected Behavior

### ✅ Success Indicators

1. **No trait collision errors**
   - Both traits can be used together
   - No "method collision" warnings

2. **Vector content includes text**
   - Title and content are in vector
   - Text fields are properly extracted

3. **Vector content includes media**
   - `has_media: true` in logs
   - Media descriptions added to vector

4. **Proper logging**
   - Debug logs show field detection
   - Source is correctly identified
   - Media inclusion is tracked

### ❌ Failure Indicators

1. **Trait collision error**
   - "Trait method 'getVectorContent' collision"
   - Fix: Ensure using v2.2.14+

2. **Empty vector content**
   - Check `$vectorizable` property is set
   - Check fields exist in database
   - Enable debug logging to see what's detected

3. **Media not included**
   - Check `$mediaFields` property is set
   - Verify MediaEmbeddingService is registered
   - Check file paths are valid

4. **No logs appearing**
   - Set `AI_ENGINE_DEBUG=true` in .env
   - Check ai-engine log channel exists
   - Verify logging configuration

## Troubleshooting

### Issue: Tests fail with "Class not found"
**Solution:** Run `composer dump-autoload` in the package directory

### Issue: Database errors in feature tests
**Solution:** Ensure test database is configured in phpunit.xml

### Issue: Mock expectations not met
**Solution:** Check MediaEmbeddingService is properly mocked in tests

### Issue: Logs not appearing
**Solution:** 
```php
// In test setUp()
config(['ai-engine.debug' => true]);
```

## CI/CD Integration

Add to your CI pipeline:

```yaml
# .github/workflows/tests.yml
- name: Run Media Embeddings Tests
  run: |
    cd packages/laravel-ai-engine
    vendor/bin/phpunit tests/Unit/Traits/HasMediaEmbeddingsTest.php
    vendor/bin/phpunit tests/Feature/MediaEmbeddingsIntegrationTest.php
```

## Performance Testing

Test with large content:

```php
$post = Post::create([
    'title' => str_repeat('Long title ', 1000),
    'content' => str_repeat('Long content ', 10000),
    'featured_image' => '/image.jpg',
]);

$start = microtime(true);
$content = $post->getVectorContent();
$duration = microtime(true) - $start;

echo "Generated in: {$duration}s\n";
echo "Content length: " . strlen($content) . "\n";
```

## Coverage

Run with coverage:

```bash
vendor/bin/phpunit --coverage-html coverage tests/Unit/Traits/HasMediaEmbeddingsTest.php
```

Open `coverage/index.html` to see coverage report.

## Next Steps

After tests pass:

1. ✅ Verify in production environment
2. ✅ Monitor logs for issues
3. ✅ Test with real media files
4. ✅ Benchmark performance
5. ✅ Add to CI/CD pipeline
