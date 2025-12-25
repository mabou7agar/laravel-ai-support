# Installation Guide

## Requirements

- PHP 8.1 or higher
- Laravel 9.x, 10.x, 11.x, or 12.x
- Composer

## Installation Steps

### 1. Install via Composer

```bash
composer require m-tech-stack/laravel-ai-engine
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=ai-engine-config
```

This will create `config/ai-engine.php` with all configuration options.

### 3. Run Migrations

```bash
php artisan migrate
```

This will create the following tables:
- `conversations` - Store AI conversations
- `conversation_messages` - Store conversation messages
- `ai_usage_logs` - Track AI API usage
- `vector_embeddings` - Store vector embeddings (if using vector search)
- `vector_search_logs` - Track vector search analytics

### 4. Configure Environment Variables

Add the following to your `.env` file:

```env
# AI Engine Configuration
AI_DEFAULT_ENGINE=openai
AI_DEFAULT_MODEL=gpt-4o-mini

# OpenAI
OPENAI_API_KEY=sk-...

# Anthropic (optional)
ANTHROPIC_API_KEY=sk-ant-...

# Google Gemini (optional)
GEMINI_API_KEY=...

# OpenRouter (optional)
OPENROUTER_API_KEY=sk-or-v1-...

# Vector Search (optional)
VECTOR_DB_DRIVER=qdrant
QDRANT_HOST=http://localhost:6333
QDRANT_API_KEY=

# Or use Pinecone
VECTOR_DB_DRIVER=pinecone
PINECONE_API_KEY=
PINECONE_ENVIRONMENT=us-west1-gcp
PINECONE_INDEX=my-index
```

## Optional: Publish Assets

### Publish Migrations

```bash
php artisan vendor:publish --tag=ai-engine-migrations
```

### Publish Views (if using UI components)

```bash
php artisan vendor:publish --tag=ai-engine-views
```

## Verify Installation

Test your installation:

```bash
php artisan ai-engine:test
```

This will verify:
- ✅ Configuration is valid
- ✅ API keys are working
- ✅ Database tables exist
- ✅ Services are registered

## Next Steps

- [Quick Start Guide](quickstart.md)
- [Configuration Guide](configuration.md)
- [Vector Search Setup](vector-search.md)
