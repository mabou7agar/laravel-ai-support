# RAG Performance Optimization Guide

## Current Performance Bottlenecks

### Typical Response Time Breakdown:
```
Total: 8-15 seconds
├── Query Analysis (AI): 2-5 seconds (40%)
├── Vector Search: 1-3 seconds (20%)
├── AI Generation: 3-10 seconds (35%)
└── Other: 0.5-1 second (5%)
```

## Quick Wins (Immediate Impact)

### 1. Disable Query Analysis (Save 2-5 seconds)
```env
# .env
INTELLIGENT_RAG_ENABLED=false
```

Or in code:
```php
$options['intelligent'] = false;
```

**Impact:** Reduces response time by 30-40%
**Trade-off:** Always searches vector DB (but that's usually what you want anyway)

### 2. Use Faster AI Models (Save 2-5 seconds)
```env
# .env
INTELLIGENT_RAG_ANALYSIS_MODEL=gpt-4o-mini  # For query analysis
AI_ENGINE_DEFAULT_MODEL=gpt-4o-mini          # For responses
```

**Impact:** 2-3x faster responses
**Trade-off:** Slightly lower quality (but often imperceptible)

### 3. Reduce Context Size (Save 1-2 seconds)
```env
# .env
INTELLIGENT_RAG_MAX_CONTEXT=3     # Instead of 5
INTELLIGENT_RAG_MIN_SCORE=0.2     # Lower threshold for faster search
```

**Impact:** Faster vector search and smaller prompts
**Trade-off:** Less context (but 3 sources is usually enough)

### 4. Enable Response Streaming (Better UX)
Already implemented! Make sure you're using the streaming endpoint.

**Impact:** User sees response immediately (perceived performance)
**Trade-off:** None

## Medium-Term Optimizations

### 5. Cache Query Analysis Results
```php
// In IntelligentRAGService::analyzeQuery()
$cacheKey = 'query_analysis:' . md5($query . json_encode($availableCollections));
$analysis = Cache::remember($cacheKey, 300, function() use ($query, $conversationHistory, $availableCollections) {
    // Existing analysis logic
});
```

**Impact:** Instant analysis for repeated queries
**Effort:** Low (5 minutes)

### 6. Optimize Vector Search
```sql
-- Add indexes for faster vector search
CREATE INDEX CONCURRENTLY idx_vector_embeddings_embedding 
ON vector_embeddings USING ivfflat (embedding vector_cosine_ops);

-- For pgvector with HNSW (faster but more memory)
CREATE INDEX CONCURRENTLY idx_vector_embeddings_hnsw 
ON vector_embeddings USING hnsw (embedding vector_cosine_ops);
```

**Impact:** 50-70% faster vector search
**Effort:** Medium (database migration)

### 7. Parallel Collection Search
```php
// Search multiple collections in parallel
$promises = [];
foreach ($collections as $collection) {
    $promises[] = async(fn() => $this->vectorSearch->search($collection, $query, ...));
}
$results = await($promises);
```

**Impact:** 2-3x faster when searching multiple collections
**Effort:** Medium (requires async library)

## Advanced Optimizations

### 8. Pre-compute Embeddings
```php
// Queue job to pre-compute embeddings for common queries
Cache::put('embedding:' . md5($query), $embedding, 3600);
```

### 9. Use Redis for Vector Search
```env
VECTOR_DRIVER=redis  # Instead of database
```

**Impact:** 3-5x faster search
**Effort:** High (requires Redis with RediSearch module)

### 10. Implement Query Result Caching
```php
$cacheKey = 'rag_response:' . md5($query . $userId);
return Cache::remember($cacheKey, 300, function() {
    // Full RAG pipeline
});
```

**Impact:** Instant responses for repeated queries
**Trade-off:** Stale data for 5 minutes

## Recommended Configuration for Speed

```env
# .env - Optimized for speed
INTELLIGENT_RAG_ENABLED=false                # Skip analysis
AI_ENGINE_DEFAULT_MODEL=gpt-4o-mini          # Fast model
INTELLIGENT_RAG_MAX_CONTEXT=3                # Fewer results
INTELLIGENT_RAG_MIN_SCORE=0.2                # Lower threshold
INTELLIGENT_RAG_FALLBACK_THRESHOLD=0.0       # Always return something
```

## Expected Results

| Configuration | Response Time | Quality |
|--------------|---------------|---------|
| **Default (Current)** | 8-15 seconds | Excellent |
| **Quick Wins Applied** | 3-6 seconds | Very Good |
| **All Optimizations** | 1-3 seconds | Good |
| **With Caching** | <1 second | Good |

## Monitoring Performance

Add this to your code to track bottlenecks:

```php
$start = microtime(true);

// Query Analysis
$analysisStart = microtime(true);
$analysis = $this->analyzeQuery(...);
Log::info('Query Analysis Time', ['ms' => (microtime(true) - $analysisStart) * 1000]);

// Vector Search
$searchStart = microtime(true);
$context = $this->retrieveRelevantContext(...);
Log::info('Vector Search Time', ['ms' => (microtime(true) - $searchStart) * 1000]);

// AI Generation
$genStart = microtime(true);
$response = $this->generateResponse(...);
Log::info('AI Generation Time', ['ms' => (microtime(true) - $genStart) * 1000]);

Log::info('Total RAG Time', ['ms' => (microtime(true) - $start) * 1000]);
```

## Testing Performance

```bash
# Enable debug logging
echo "AI_ENGINE_DEBUG=true" >> .env

# Check logs
tail -f storage/logs/ai-engine.log | grep "Time"
```
