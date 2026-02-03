---
name: laravel-intelligent-rag
description: Search Laravel documentation, codebase, and related resources using AI-powered context retrieval with citations. Use this when the user asks "how to" questions about Laravel, needs documentation, or wants to find code examples.
---

# Laravel Intelligent RAG Search

This skill helps you search Laravel documentation, package documentation, and code examples using AI-powered Retrieval Augmented Generation (RAG).

## When to Use This Skill

- User asks "How to..." questions about Laravel
- User needs to find Laravel documentation
- User wants code examples or best practices
- User asks about Laravel features or APIs
- User needs to understand Laravel patterns

## How It Works

The Laravel AI Engine package provides intelligent RAG search capabilities that:

1. **Search Multiple Sources**: Laravel docs, package documentation, code examples, Stack Overflow, GitHub repositories
2. **Context-Aware**: Understands the context of your question
3. **Provides Citations**: Returns sources with confidence scores
4. **Semantic Search**: Uses vector embeddings for intelligent matching

## Usage Examples

### Basic Search
```php
use LaravelAIEngine\Facades\Chat;

$response = Chat::processMessage(
    message: 'How to implement vector search in Laravel?',
    sessionId: 'user-123',
    useIntelligentRAG: true,
    ragCollections: ['laravel_docs', 'package_documentation']
);
```

### Search with Context
```php
$response = Chat::processMessage(
    message: 'Show me examples of middleware implementation',
    sessionId: 'user-123',
    useIntelligentRAG: true,
    context: 'middleware'
);
```

## API Endpoints

- `POST /api/v1/chat/message` - Send message with RAG enabled
- `GET /api/v1/rag/search` - Direct RAG search endpoint

## Configuration

Set up in `config/ai-engine.php`:

```php
'rag' => [
    'enabled' => true,
    'sources' => ['laravel_docs', 'package_documentation', 'code_examples'],
    'threshold' => 0.3,
    'max_results' => 10,
],
```

## Tips

- Be specific in your questions for better results
- Mention the Laravel version if relevant
- Use context parameter to narrow down search scope
- Check confidence scores in the response
