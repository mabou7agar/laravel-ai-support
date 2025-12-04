# Chunking Strategies: Split vs Truncate

The Laravel AI Engine supports two strategies for handling large content: **Split** and **Truncate**.

---

## Strategies Overview

### 1. Split Strategy (Recommended for RAG) ✅

**What it does:**
- Splits large content into multiple chunks
- Creates separate embeddings for each chunk
- Maintains context with overlapping chunks
- No information loss

**Best for:**
- RAG (Retrieval-Augmented Generation)
- Long documents
- Comprehensive search
- Maximum accuracy

### 2. Truncate Strategy (Faster)

**What it does:**
- Truncates content to fit token limit
- Creates single embedding
- Faster processing
- Some information loss

**Best for:**
- Simple use cases
- Short content
- Performance-critical scenarios
- Backward compatibility

---

## Configuration

### Enable Split Strategy (Default)

```env
AI_ENGINE_VECTORIZATION_STRATEGY=split
```

```php
'vectorization' => [
    'strategy' => 'split',  // or 'truncate'
    'chunk_size' => null,   // Auto-calculated
    'chunk_overlap' => 200, // Characters overlap
],
```

---

## How Split Strategy Works

### 1. Content Collection

```php
$email = Email::create([
    'subject' => 'Important Document',
    'body' => str_repeat('Long content...', 10000), // 100KB
]);
```

### 2. Chunking Process

```
Total content: 100,000 chars
Token limit: 8,191 tokens
Chunk size: 9,543 chars (90% of limit with buffer)
Overlap: 200 chars

Chunk 1: chars 0-9,543
Chunk 2: chars 9,343-18,886 (200 char overlap)
Chunk 3: chars 18,686-28,229
...
Chunk 11: chars 90,457-100,000

Result: 11 chunks
```

### 3. Multiple Embeddings

```php
$chunks = $email->getVectorContentChunks();
// Returns: ['chunk1', 'chunk2', 'chunk3', ...]

// Each chunk gets its own embedding
foreach ($chunks as $index => $chunk) {
    $embedding = $embeddingService->embed($chunk);
    // Store with chunk_index for retrieval
}
```

### 4. Search & Retrieval

```php
// User searches: "important document details"
// Vector search finds relevant chunks:
// - Chunk 3: High similarity (contains "important")
// - Chunk 7: Medium similarity (contains "document")
// - Chunk 9: High similarity (contains "details")

// Return all relevant chunks for context
```

---

## Usage

### Get All Chunks

```php
use LaravelAIEngine\Traits\VectorizableWithMedia;

class Document extends Model
{
    use VectorizableWithMedia;
}

$document = Document::find(1);

// Get all chunks
$chunks = $document->getVectorContentChunks();

// Process each chunk
foreach ($chunks as $index => $chunk) {
    echo "Chunk {$index}: " . strlen($chunk) . " chars\n";
}
```

### Get Single Chunk (Backward Compatible)

```php
// Returns first chunk only
$content = $document->getVectorContent();
```

---

## Comparison

### Split Strategy

**Pros:**
- ✅ No information loss
- ✅ Better search accuracy
- ✅ Handles unlimited content size
- ✅ Context maintained with overlap
- ✅ Multiple relevant results per document

**Cons:**
- ❌ More API calls
- ❌ Higher cost
- ❌ More storage needed
- ❌ Slightly slower

**Example:**
```
Document: 100KB
Chunks: 11
Embeddings: 11
Cost: 11x single embedding
Storage: 11x vectors
Search: Returns multiple relevant chunks
```

### Truncate Strategy

**Pros:**
- ✅ Single API call
- ✅ Lower cost
- ✅ Less storage
- ✅ Faster processing

**Cons:**
- ❌ Information loss
- ❌ Limited to token size
- ❌ May miss relevant content
- ❌ Single result per document

**Example:**
```
Document: 100KB
Truncated: 10KB (90KB lost)
Embeddings: 1
Cost: 1x embedding
Storage: 1x vector
Search: Returns single result (may miss content)
```

---

## Real-World Examples

### Example 1: Email with Large Attachment

**Split Strategy:**
```php
$email = Email::create([
    'subject' => 'Q4 Report',
    'body' => 'See attached',
    'attachment_url' => 'https://example.com/report.pdf', // 50 pages
]);

$chunks = $email->getVectorContentChunks();
// Chunk 1: Subject + body + pages 1-5
// Chunk 2: Pages 4-10 (overlap)
// Chunk 3: Pages 9-15
// ...
// Chunk 10: Pages 45-50

// Search: "revenue growth Q4"
// Finds: Chunk 7 (contains revenue section)
```

**Truncate Strategy:**
```php
$content = $email->getVectorContent();
// Only: Subject + body + pages 1-5
// Lost: Pages 6-50 (including revenue section!)

// Search: "revenue growth Q4"
// Finds: Nothing (content was truncated)
```

### Example 2: Long Article

**Split Strategy:**
```php
$article = Article::create([
    'title' => 'Complete Guide to Laravel',
    'content' => str_repeat('...', 50000), // 50KB article
]);

$chunks = $article->getVectorContentChunks();
// Chunk 1: Title + intro + section 1
// Chunk 2: Section 1-2 (overlap)
// Chunk 3: Section 2-3
// Chunk 4: Section 3-4
// Chunk 5: Section 4 + conclusion

// Search: "advanced routing techniques"
// Finds: Chunk 3 (routing section)
```

**Truncate Strategy:**
```php
$content = $article->getVectorContent();
// Only: Title + intro + section 1
// Lost: Sections 2-4 + conclusion

// Search: "advanced routing techniques"
// Finds: Nothing (routing section was truncated)
```

---

## Chunk Overlap

### Why Overlap?

Overlap ensures context isn't lost at chunk boundaries:

```
Without Overlap:
Chunk 1: "...the database connection is"
Chunk 2: "established using the config..."
❌ Context broken!

With Overlap (200 chars):
Chunk 1: "...the database connection is established using..."
Chunk 2: "...connection is established using the config..."
✅ Context preserved!
```

### Configuration

```env
AI_ENGINE_CHUNK_OVERLAP=200  # Characters
```

```php
'chunk_overlap' => 200,  // Recommended: 100-500
```

---

## Performance Considerations

### Split Strategy

**API Calls:**
```
Document size: 100KB
Chunk size: 10KB
Chunks: 10
API calls: 10
Cost: 10x
```

**Storage:**
```
Vectors per document: 10
Storage needed: 10x
Query performance: Slightly slower (more vectors to search)
```

### Truncate Strategy

**API Calls:**
```
Document size: 100KB
Truncated: 10KB
Chunks: 1
API calls: 1
Cost: 1x
```

**Storage:**
```
Vectors per document: 1
Storage needed: 1x
Query performance: Faster (fewer vectors)
```

---

## Migration

### From Truncate to Split

```php
// Before (truncate)
'strategy' => 'truncate',

// After (split)
'strategy' => 'split',

// Re-index existing content
php artisan ai-engine:reindex --all
```

### Backward Compatibility

```php
// Old code still works
$content = $model->getVectorContent();  // Returns first chunk

// New code for full chunks
$chunks = $model->getVectorContentChunks();  // Returns all chunks
```

---

## Recommendations

### Use Split Strategy When:
- ✅ Content > 10KB
- ✅ RAG is important
- ✅ Search accuracy matters
- ✅ Budget allows
- ✅ Long documents (emails, PDFs, articles)

### Use Truncate Strategy When:
- ✅ Content < 5KB
- ✅ Simple search
- ✅ Cost-sensitive
- ✅ Performance critical
- ✅ Short content (titles, descriptions)

---

## Configuration Examples

### Maximum Accuracy (Split)

```env
AI_ENGINE_VECTORIZATION_STRATEGY=split
AI_ENGINE_CHUNK_SIZE=9000
AI_ENGINE_CHUNK_OVERLAP=500
```

### Balanced (Split with smaller chunks)

```env
AI_ENGINE_VECTORIZATION_STRATEGY=split
AI_ENGINE_CHUNK_SIZE=5000
AI_ENGINE_CHUNK_OVERLAP=200
```

### Performance (Truncate)

```env
AI_ENGINE_VECTORIZATION_STRATEGY=truncate
AI_ENGINE_MAX_CONTENT_LENGTH=6000
```

---

## Logging

### Split Strategy Logs

```json
{
  "message": "Content split into chunks",
  "total_length": 100000,
  "chunk_count": 11,
  "chunk_size": 9543,
  "overlap": 200,
  "chunk_lengths": [9543, 9543, 9543, ..., 5234]
}
```

### Truncate Strategy Logs

```json
{
  "message": "Vector content generated",
  "content_length": 100000,
  "truncated_length": 9543,
  "was_truncated": true
}
```

---

## Summary

| Feature | Split | Truncate |
|---------|-------|----------|
| Information Loss | ❌ None | ✅ Yes |
| API Calls | Multiple | Single |
| Cost | Higher | Lower |
| Accuracy | Higher | Lower |
| Storage | More | Less |
| Best For | RAG, Long Docs | Simple, Short |

**Recommendation: Use Split for production RAG systems** ✅

---

## Status

✅ **Production Ready**  
✅ **Fully Tested**  
✅ **Backward Compatible**  
✅ **Configurable**  

**Use split strategy for best results!** 🚀
