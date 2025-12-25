# Performance Optimization Guide

Complete guide to optimizing Laravel AI Engine for speed and cost efficiency.

## Table of Contents

- [Model Selection](#model-selection)
- [RAG Performance](#rag-performance)
- [Vector Search Optimization](#vector-search-optimization)
- [Caching Strategies](#caching-strategies)
- [Benchmarks](#benchmarks)

## Model Selection

### Analysis vs Response Models

The Intelligent RAG system uses two separate AI calls:
1. **Query Analysis** - Classifies the query and determines search strategy
2. **Response Generation** - Generates the final response with context

Configure different models for each task:

```env
# .env
INTELLIGENT_RAG_ANALYSIS_MODEL=gpt-4o-mini    # Fast, cheap
INTELLIGENT_RAG_RESPONSE_MODEL=gpt-5-mini     # Quality responses
```

### Model Recommendations

| Task | Recommended | Alternative | Avoid |
|------|-------------|-------------|-------|
| Query Analysis | `gpt-4o-mini` | `gemini-2.0-flash` | GPT-5 (slow) |
| Response Generation | `gpt-5-mini` | `claude-4-sonnet` | `gpt-5.1` (expensive) |
| Complex Reasoning | `gpt-5.1` | `claude-4.5-sonnet` | `gpt-4o-mini` (limited) |
| High Throughput | `gpt-4o-mini` | `gemini-1.5-flash` | Any GPT-5 |
| Best Quality | `claude-4.5-sonnet` | `gpt-5.1` | Fast models |

### Latest Models by Provider

**OpenAI (December 2025):**
- `gpt-5.1` - Flagship, best for complex reasoning
- `gpt-5-mini` - Balanced quality/cost
- `o3` / `o3-mini` - Advanced reasoning

**Anthropic (December 2025):**
- `claude-4.5-sonnet` - Latest, best quality
- `claude-4-opus` - Most capable
- `claude-4-sonnet` - Balanced

**Google (December 2025):**
- `gemini-3-pro-preview` - Latest flagship, best multimodal
- `gemini-3-pro-image` - Native image generation
- `gemini-2.5-pro` - Complex reasoning
- `gemini-2.0-flash-thinking` - Reasoning tasks

**DeepSeek (December 2025):**
- `deepseek-v3` - Latest, advanced reasoning, very affordable
- `deepseek-r1` - Reasoning-focused model
- `deepseek-coder` - Specialized for code generation

### GPT-5 Family Considerations

GPT-5 models include internal reasoning, which adds latency:

| Model | Reasoning Overhead | Best For |
|-------|-------------------|----------|
| `gpt-5-nano` | ~3-4s | Simple classification |
| `gpt-5-mini` | ~4-5s | Quality responses |
| `gpt-5` | ~6-8s | Complex tasks |
| `gpt-5.1` | ~8-10s | Most complex reasoning |

**Tip:** For query analysis (simple classification), `gpt-4o-mini` is faster than `gpt-5-nano` because it has no reasoning overhead.

## RAG Performance

### Context Truncation

Large context items slow down API calls. Truncate content:

```env
# Max characters per context item (default: 2000)
VECTOR_RAG_MAX_ITEM_LENGTH=2000

# Max context items to retrieve (default: 5)
INTELLIGENT_RAG_MAX_CONTEXT=5
```

### Query Analysis Optimization

The system uses smart query analysis to minimize unnecessary searches:

```php
// config/ai-engine.php
'intelligent_rag' => [
    'analysis_model' => 'gpt-4o-mini',  // Fast classification
    'response_model' => 'gpt-5-mini',   // Quality response
    'max_context_items' => 5,
    'min_relevance_score' => 0.3,       // Lower = more results
],
```

### Skip Analysis for Simple Queries

The AI automatically uses exact phrases for title-like queries:

```
Query: "Undelivered Mail Returned to Sender"
→ Uses exact phrase (1 search query)
→ NOT expanded to multiple queries

Query: "what emails are important"
→ Expanded to: ["urgent", "priority", "deadline", ...]
→ Multiple search queries
```

## Vector Search Optimization

### Payload Indexes

Ensure proper indexes for filtered searches:

```bash
# Force recreate collections with fresh indexes
php artisan ai-engine:vector-index --force
```

This automatically:
1. Detects `belongsTo` relationships
2. Creates indexes for foreign keys
3. Uses correct field types from database schema

### Configure Index Fields

```php
// config/ai-engine.php
'vector' => [
    'payload_index_fields' => [
        'user_id',
        'tenant_id',
        'workspace_id',
        'status',
    ],
],
```

### Embedding Model Selection

| Model | Dimensions | Speed | Quality |
|-------|-----------|-------|---------|
| `text-embedding-3-small` | 1536 | Fast | Good |
| `text-embedding-3-large` | 3072 | Slower | Best |
| `text-embedding-ada-002` | 1536 | Fast | Good |

```env
VECTOR_EMBEDDING_MODEL=text-embedding-3-small
VECTOR_EMBEDDING_DIMENSIONS=1536
```

## Caching Strategies

### Embedding Cache

```php
'embeddings' => [
    'cache_enabled' => true,
    'cache_ttl' => 86400, // 24 hours
],
```

### Query Cache

```php
'intelligent_rag' => [
    'discovery_cache_ttl' => 3600, // 1 hour
],
```

### User Context Cache

```php
// User lookups are cached for 5 minutes
// No configuration needed - automatic
```

## Benchmarks

### RAG Response Times

| Configuration | Analysis | Search | Response | Total |
|--------------|----------|--------|----------|-------|
| gpt-4o-mini + gpt-4o-mini | ~1-2s | ~0.5s | ~2s | **~3-4s** |
| gpt-4o-mini + gpt-5-mini | ~1-2s | ~0.5s | ~3s | **~5-6s** |
| gpt-5-nano + gpt-5-mini | ~5-6s | ~0.5s | ~5s | **~11-12s** |
| gpt-5-nano + gpt-5.1 | ~5-6s | ~0.5s | ~8s | **~14-15s** |

### Recommendations by Use Case

| Use Case | Analysis Model | Response Model | Expected Time |
|----------|---------------|----------------|---------------|
| **Fast Chat** | gpt-4o-mini | gpt-4o-mini | ~3-4s |
| **Quality Chat** | gpt-4o-mini | gpt-5-mini | ~5-6s |
| **Complex Tasks** | gpt-4o-mini | gpt-5.1 | ~10-12s |
| **High Volume** | gpt-4o-mini | gpt-4o-mini | ~3-4s |

### Cost Optimization

| Model | Input Cost | Output Cost | Relative |
|-------|-----------|-------------|----------|
| gpt-4o-mini | $0.15/1M | $0.60/1M | 1x |
| gpt-4o | $2.50/1M | $10.00/1M | ~17x |
| gpt-5-mini | $0.30/1M | $1.20/1M | ~2x |
| gpt-5.1 | $5.00/1M | $20.00/1M | ~33x |

**Tip:** Use `gpt-4o-mini` for analysis (cheap, fast) and `gpt-5-mini` for responses (quality, reasonable cost).

## Quick Start

For optimal performance out of the box:

```env
# .env
INTELLIGENT_RAG_ANALYSIS_MODEL=gpt-4o-mini
INTELLIGENT_RAG_RESPONSE_MODEL=gpt-5-mini
VECTOR_RAG_MAX_ITEM_LENGTH=2000
INTELLIGENT_RAG_MAX_CONTEXT=5
INTELLIGENT_RAG_MIN_SCORE=0.3
```

Expected performance: **~5-6 seconds** per RAG request with good quality responses.
