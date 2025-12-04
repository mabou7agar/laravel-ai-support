# Configuration Guide

Complete configuration reference for Laravel AI Engine.

## Table of Contents

- [Basic Configuration](#basic-configuration)
- [AI Engines](#ai-engines)
- [Vector Search](#vector-search)
- [Credit Management](#credit-management)
- [Caching](#caching)
- [Rate Limiting](#rate-limiting)
- [Analytics](#analytics)

## Basic Configuration

### Default Engine

```php
'default_engine' => env('AI_DEFAULT_ENGINE', 'openai'),
'default_model' => env('AI_DEFAULT_MODEL', 'gpt-4o-mini'),
```

Environment variables:

```env
AI_DEFAULT_ENGINE=openai
AI_DEFAULT_MODEL=gpt-4o-mini
```

## AI Engines

### OpenAI

```php
'engines' => [
    'openai' => [
        'enabled' => true,
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'models' => [
            'chat' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
            'completion' => env('OPENAI_COMPLETION_MODEL', 'gpt-3.5-turbo-instruct'),
            'embedding' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
            'image' => env('OPENAI_IMAGE_MODEL', 'dall-e-3'),
        ],
        'timeout' => 120,
        'max_retries' => 3,
    ],
],
```

Environment:

```env
OPENAI_API_KEY=sk-...
OPENAI_ORGANIZATION=org-...
OPENAI_CHAT_MODEL=gpt-4o
OPENAI_EMBEDDING_MODEL=text-embedding-3-large
```

### Anthropic (Claude)

```php
'anthropic' => [
    'enabled' => true,
    'api_key' => env('ANTHROPIC_API_KEY'),
    'models' => [
        'chat' => env('ANTHROPIC_CHAT_MODEL', 'claude-3-5-sonnet-20241022'),
    ],
    'timeout' => 120,
    'max_retries' => 3,
],
```

Environment:

```env
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_CHAT_MODEL=claude-3-5-sonnet-20241022
```

### Google Gemini

```php
'gemini' => [
    'enabled' => true,
    'api_key' => env('GEMINI_API_KEY'),
    'models' => [
        'chat' => env('GEMINI_CHAT_MODEL', 'gemini-1.5-flash'),
    ],
    'timeout' => 120,
],
```

Environment:

```env
GEMINI_API_KEY=...
GEMINI_CHAT_MODEL=gemini-1.5-pro
```

### OpenRouter

```php
'openrouter' => [
    'enabled' => true,
    'api_key' => env('OPENROUTER_API_KEY'),
    'models' => [
        'chat' => env('OPENROUTER_CHAT_MODEL', 'anthropic/claude-3.5-sonnet'),
    ],
    'site_url' => env('APP_URL'),
    'site_name' => env('APP_NAME'),
],
```

Environment:

```env
OPENROUTER_API_KEY=sk-or-v1-...
OPENROUTER_CHAT_MODEL=anthropic/claude-3.5-sonnet
```

## Vector Search

### Driver Configuration

```php
'vector' => [
    'enabled' => env('VECTOR_ENABLED', true),
    'driver' => env('VECTOR_DB_DRIVER', 'qdrant'),
    
    'drivers' => [
        'qdrant' => [
            'host' => env('QDRANT_HOST', 'http://localhost:6333'),
            'api_key' => env('QDRANT_API_KEY'),
            'timeout' => 30,
            'collection_prefix' => env('QDRANT_COLLECTION_PREFIX', ''),
        ],
        
        'pinecone' => [
            'api_key' => env('PINECONE_API_KEY'),
            'environment' => env('PINECONE_ENVIRONMENT', 'us-west1-gcp'),
            'index' => env('PINECONE_INDEX'),
            'namespace' => env('PINECONE_NAMESPACE', ''),
        ],
    ],
],
```

Environment:

```env
VECTOR_ENABLED=true
VECTOR_DB_DRIVER=qdrant

# Qdrant
QDRANT_HOST=http://localhost:6333
QDRANT_API_KEY=
QDRANT_COLLECTION_PREFIX=prod_

# Pinecone
PINECONE_API_KEY=
PINECONE_ENVIRONMENT=us-west1-gcp
PINECONE_INDEX=my-index
```

### Embeddings

```php
'embeddings' => [
    'model' => env('VECTOR_EMBEDDING_MODEL', 'text-embedding-3-large'),
    'dimensions' => (int) env('VECTOR_EMBEDDING_DIMENSIONS', 3072),
    'batch_size' => 100,
    'cache_enabled' => true,
    'cache_ttl' => 86400, // 24 hours
],
```

Environment:

```env
VECTOR_EMBEDDING_MODEL=text-embedding-3-large
VECTOR_EMBEDDING_DIMENSIONS=3072
```

### RAG Configuration

```php
'rag' => [
    'enabled' => env('VECTOR_RAG_ENABLED', true),
    'max_context_items' => env('VECTOR_RAG_MAX_CONTEXT', 5),
    'include_sources' => env('VECTOR_RAG_INCLUDE_SOURCES', true),
    'min_relevance_score' => env('VECTOR_RAG_MIN_SCORE', 0.5),
],
```

Environment:

```env
VECTOR_RAG_ENABLED=true
VECTOR_RAG_MAX_CONTEXT=5
VECTOR_RAG_INCLUDE_SOURCES=true
VECTOR_RAG_MIN_SCORE=0.5
```

### Authorization

```php
'authorization' => [
    'enabled' => env('VECTOR_AUTHORIZATION_ENABLED', true),
    'default_allow' => env('VECTOR_AUTH_DEFAULT_ALLOW', true),
    'filter_by_user' => env('VECTOR_AUTH_FILTER_BY_USER', false),
    'filter_by_visibility' => env('VECTOR_AUTH_FILTER_BY_VISIBILITY', true),
    'filter_by_status' => env('VECTOR_AUTH_FILTER_BY_STATUS', true),
    
    'row_level_security' => [
        // ['field' => 'user_id', 'operator' => '==', 'value' => '{user_id}']
    ],
],
```

### Auto-Indexing

```php
'auto_index' => [
    'enabled' => env('VECTOR_AUTO_INDEX', false),
    'queue' => env('VECTOR_AUTO_INDEX_QUEUE', true),
    'on_create' => true,
    'on_update' => true,
    'on_delete' => true,
],
```

Environment:

```env
VECTOR_AUTO_INDEX=true
VECTOR_AUTO_INDEX_QUEUE=true
```

## Credit Management

```php
'credits' => [
    'enabled' => env('AI_CREDITS_ENABLED', true),
    'default_balance' => env('AI_DEFAULT_CREDITS', 1000),
    'track_usage' => true,
    
    'costs' => [
        'openai' => [
            'gpt-4o' => ['input' => 5.0, 'output' => 15.0],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.6],
            'text-embedding-3-large' => 0.13,
        ],
        'anthropic' => [
            'claude-3-5-sonnet-20241022' => ['input' => 3.0, 'output' => 15.0],
        ],
    ],
],
```

Environment:

```env
AI_CREDITS_ENABLED=true
AI_DEFAULT_CREDITS=1000
```

## Caching

```php
'cache' => [
    'enabled' => env('AI_CACHE_ENABLED', true),
    'driver' => env('AI_CACHE_DRIVER', 'redis'),
    'ttl' => env('AI_CACHE_TTL', 3600),
    'prefix' => 'ai_engine:',
    
    'cache_responses' => true,
    'cache_embeddings' => true,
    'cache_analytics' => true,
],
```

Environment:

```env
AI_CACHE_ENABLED=true
AI_CACHE_DRIVER=redis
AI_CACHE_TTL=3600
```

## Rate Limiting

```php
'rate_limiting' => [
    'enabled' => env('AI_RATE_LIMITING_ENABLED', true),
    'apply_to_jobs' => env('AI_RATE_LIMITING_APPLY_TO_JOBS', true),
    
    'limits' => [
        'global' => [
            'requests' => 100,
            'per_minutes' => 1,
        ],
        'per_user' => [
            'requests' => 20,
            'per_minutes' => 1,
        ],
        'per_ip' => [
            'requests' => 10,
            'per_minutes' => 1,
        ],
    ],
],
```

Environment:

```env
AI_RATE_LIMITING_ENABLED=true
AI_RATE_LIMITING_APPLY_TO_JOBS=true
```

## Analytics

```php
'analytics' => [
    'enabled' => env('AI_ANALYTICS_ENABLED', true),
    'track_requests' => true,
    'track_errors' => true,
    'track_performance' => true,
    'retention_days' => 90,
],
```

Environment:

```env
AI_ANALYTICS_ENABLED=true
```

## Streaming

```php
'streaming' => [
    'enabled' => env('AI_STREAMING_ENABLED', true),
    'websocket_url' => env('AI_WEBSOCKET_URL', 'ws://localhost:6001'),
    'chunk_size' => 1024,
],
```

Environment:

```env
AI_STREAMING_ENABLED=true
AI_WEBSOCKET_URL=ws://localhost:6001
```

## Media Processing

```php
'media' => [
    'vision' => [
        'enabled' => true,
        'max_file_size' => 20 * 1024 * 1024, // 20MB
        'allowed_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],
    
    'audio' => [
        'enabled' => true,
        'max_file_size' => 25 * 1024 * 1024, // 25MB
        'allowed_formats' => ['mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm'],
    ],
    
    'video' => [
        'enabled' => true,
        'max_file_size' => 100 * 1024 * 1024, // 100MB
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
        'frame_interval' => 30, // Extract frame every 30 seconds
    ],
    
    'documents' => [
        'enabled' => true,
        'max_file_size' => 50 * 1024 * 1024, // 50MB
        'allowed_formats' => ['pdf', 'docx', 'txt', 'md'],
        'pdftotext_path' => env('PDFTOTEXT_PATH', 'pdftotext'),
    ],
],
```

## Failover

```php
'failover' => [
    'enabled' => env('AI_FAILOVER_ENABLED', true),
    'engines' => ['openai', 'anthropic', 'gemini'],
    'retry_delay' => 1000, // milliseconds
    'max_attempts' => 3,
],
```

Environment:

```env
AI_FAILOVER_ENABLED=true
```

## Environment Variables Reference

### Core Settings

```env
AI_DEFAULT_ENGINE=openai
AI_DEFAULT_MODEL=gpt-4o-mini
```

### API Keys

```env
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
OPENROUTER_API_KEY=sk-or-v1-...
```

### Vector Search

```env
VECTOR_ENABLED=true
VECTOR_DB_DRIVER=qdrant
QDRANT_HOST=http://localhost:6333
QDRANT_API_KEY=
VECTOR_EMBEDDING_MODEL=text-embedding-3-large
VECTOR_EMBEDDING_DIMENSIONS=3072
```

### Features

```env
AI_CREDITS_ENABLED=true
AI_CACHE_ENABLED=true
AI_RATE_LIMITING_ENABLED=true
AI_ANALYTICS_ENABLED=true
AI_STREAMING_ENABLED=true
AI_FAILOVER_ENABLED=true
```

### RAG

```env
VECTOR_RAG_ENABLED=true
VECTOR_RAG_MAX_CONTEXT=5
VECTOR_RAG_MIN_SCORE=0.5
```

### Auto-Indexing

```env
VECTOR_AUTO_INDEX=true
VECTOR_AUTO_INDEX_QUEUE=true
```

## Production Recommendations

### Performance

```env
AI_CACHE_ENABLED=true
AI_CACHE_DRIVER=redis
AI_CACHE_TTL=3600
VECTOR_AUTO_INDEX_QUEUE=true
```

### Security

```env
AI_RATE_LIMITING_ENABLED=true
VECTOR_AUTHORIZATION_ENABLED=true
VECTOR_AUTH_FILTER_BY_USER=true
```

### Cost Optimization

```env
AI_DEFAULT_MODEL=gpt-4o-mini
VECTOR_EMBEDDING_MODEL=text-embedding-3-small
AI_CACHE_ENABLED=true
```

### Reliability

```env
AI_FAILOVER_ENABLED=true
AI_ANALYTICS_ENABLED=true
```

## Next Steps

- [Installation Guide](installation.md)
- [Quick Start](quickstart.md)
- [Vector Search](vector-search.md)
