<div align="center">

# 🚀 Laravel AI Engine

### Enterprise-Grade Multi-AI Integration with Federated RAG

[![Latest Version](https://img.shields.io/packagist/v/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![Total Downloads](https://img.shields.io/packagist/dt/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![License](https://img.shields.io/packagist/l/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![PHP Version](https://img.shields.io/packagist/php-v/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![Laravel](https://img.shields.io/badge/Laravel-9%20%7C%2010%20%7C%2011%20%7C%2012-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)

**The most advanced Laravel AI package with Federated RAG, Intelligent Context Retrieval, Dynamic Model Registry, and Enterprise-Grade Distributed Search.**

**🎯 100% Future-Proof** - Automatically supports GPT-5, GPT-6, Claude 4, and all future AI models!

[Quick Start](#-quick-start) • [Features](#-key-features) • [Federated RAG](#-federated-rag-distributed-knowledge-base) • [Documentation](docs/) • [Examples](#-usage-examples)

</div>

---

## 🎯 What Makes This Package Unique?

<table>
<tr>
<td width="33%" align="center">

### 🌐 Federated RAG
**Distribute knowledge across nodes:**
```php
// Master searches child nodes automatically
$response = $rag->processMessage(
    'Show me Laravel tutorials',
    collections: ['App\Models\Post']
);
// Searches local + remote nodes!
```

</td>
<td width="33%" align="center">

### 🤖 Intelligent RAG
**AI decides when to search:**
```php
// AI auto-analyzes and searches
$response = $chat->processMessage(
    'What emails do I have?',
    useIntelligentRAG: true
);
```

</td>
<td width="33%" align="center">

### 🚀 Future-Proof
**GPT-5 ready today:**
```bash
php artisan ai-engine:sync-models
```
**Done!** No code changes needed.

</td>
</tr>
</table>

---

## ✨ Key Features

### 🌐 Federated RAG (Distributed Knowledge Base)
- **Multi-Node Architecture**: Distribute collections across multiple servers
- **Automatic Discovery**: Master node auto-discovers collections from child nodes
- **Transparent Search**: Search local + remote collections seamlessly
- **Smart Routing**: AI-powered query routing to relevant nodes
- **Health Monitoring**: Automatic health checks and circuit breakers
- **JWT Authentication**: Secure node-to-node communication

### 🧠 Intelligent RAG (AI-Powered Context Retrieval)
- **Smart Query Analysis**: AI decides if context is needed
- **Semantic Search**: Vector-based similarity search
- **Multi-Collection**: Search across multiple models simultaneously
- **Source Citations**: Automatic source attribution
- **Flexible Prompts**: Works with ANY embedded content (emails, docs, posts, etc.)
- **Threshold Optimization**: Balanced precision/recall (0.3 default)

### 🤖 Multi-AI Engine Support
- **OpenAI**: GPT-4, GPT-4 Turbo, GPT-3.5, O1, O3
- **Anthropic**: Claude 3.5 Sonnet, Claude 3 Opus/Haiku
- **Google**: Gemini 1.5 Pro/Flash, Gemini 2.0
- **DeepSeek**: DeepSeek V3, DeepSeek Chat
- **Perplexity**: Sonar Pro, Sonar
- **Unified API**: Same interface for all providers

### 📊 Dynamic Model Registry
- **Auto-Discovery**: Automatically detects new AI models
- **Database-Driven**: All models stored with metadata
- **Cost Tracking**: Pricing, capabilities, context windows
- **Smart Recommendations**: AI suggests best model for task
- **Version Control**: Track model versions and deprecations

### 💾 Advanced Memory Management
- **Conversation History**: Multi-turn conversations
- **Context Windows**: Automatic token management
- **Memory Optimization**: Smart truncation and summarization
- **Session Management**: User-specific conversation tracking

### 🎬 Real-Time Streaming
- **SSE Support**: Server-Sent Events for live responses
- **Chunk Processing**: Process responses as they arrive
- **Progress Tracking**: Real-time generation progress
- **Error Handling**: Graceful stream error recovery

### 🔍 Vector Search & Embeddings
- **Multiple Providers**: OpenAI, Voyage AI, Cohere
- **Auto-Indexing**: Automatic vector generation
- **Hybrid Search**: Combine vector + keyword search
- **Media Support**: Images, PDFs, documents
- **Chunking Strategies**: Smart content splitting

### 🎯 Smart Features
- **Dynamic Actions**: AI-suggested next actions
- **File Analysis**: Upload and analyze files
- **URL Processing**: Fetch and analyze web content
- **Multi-Modal**: Text, images, documents
- **Batch Processing**: Process multiple requests efficiently

---

## 🌐 Federated RAG (Distributed Knowledge Base)

### Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Master Node                           │
│  ┌──────────────────────────────────────────────────┐  │
│  │  Federated RAG Service                            │  │
│  │  • Auto-discovers collections from all nodes     │  │
│  │  • Searches local + remote collections           │  │
│  │  • Merges and ranks results                      │  │
│  └──────────────────────────────────────────────────┘  │
│         │                    │                    │      │
│         ▼                    ▼                    ▼      │
└─────────┼────────────────────┼────────────────────┼─────┘
          │                    │                    │
    ┌─────▼─────┐        ┌────▼─────┐        ┌────▼─────┐
    │  Child 1  │        │ Child 2  │        │ Child 3  │
    │           │        │          │        │          │
    │ Posts     │        │ Emails   │        │ Docs     │
    │ Users     │        │ Messages │        │ Files    │
    └───────────┘        └──────────┘        └──────────┘
```

### Setup Federated RAG

**1. Configure Master Node:**
```bash
# .env
AI_ENGINE_IS_MASTER=true
AI_ENGINE_NODES_ENABLED=true
AI_ENGINE_JWT_SECRET=your-shared-secret
```

**2. Configure Child Nodes:**
```bash
# .env
AI_ENGINE_IS_MASTER=false
AI_ENGINE_JWT_SECRET=your-shared-secret  # Same as master!
```

**3. Register Child Nodes:**
```bash
php artisan ai-engine:node-register \
    --name="Content Node" \
    --url="https://content.example.com" \
    --type=child
```

**4. Discover Collections:**
```bash
php artisan ai-engine:discover-collections
```

**5. Use Federated RAG:**
```php
$rag = app(\LaravelAIEngine\Services\RAG\IntelligentRAGService::class);
$response = $rag->processMessage(
    message: 'Show me Laravel tutorials',
    sessionId: 'user-123',
    availableCollections: ['App\Models\Post'] // Can exist on any node!
);
```

### Features

✅ **Auto-Discovery**: Master finds collections on all nodes  
✅ **Transparent Search**: No code changes for federated vs local  
✅ **Health Monitoring**: Automatic node health checks  
✅ **Circuit Breakers**: Fault tolerance for failed nodes  
✅ **Load Balancing**: Distribute search across nodes  
✅ **Secure**: JWT authentication between nodes  
✅ **Caching**: Smart result caching for performance  

---

## 📦 Installation

```bash
composer require m-tech-stack/laravel-ai-engine
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=ai-engine-config
php artisan vendor:publish --tag=ai-engine-migrations
```

### Run Migrations

```bash
php artisan migrate
```

### Configure API Keys

```bash
# .env
OPENAI_API_KEY=your-openai-key
ANTHROPIC_API_KEY=your-anthropic-key
GOOGLE_API_KEY=your-google-key
```

---

## 🚀 Quick Start

### 1. Basic Chat

```php
use LaravelAIEngine\Services\ChatService;

$chat = app(ChatService::class);
$response = $chat->processMessage(
    message: 'Hello! How are you?',
    sessionId: 'user-123'
);

echo $response->content;
```

### 2. Intelligent RAG

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

$rag = app(IntelligentRAGService::class);
$response = $rag->processMessage(
    message: 'What emails do I have?',
    sessionId: 'user-123',
    availableCollections: ['App\Models\Email']
);

// AI automatically:
// 1. Analyzes if query needs context
// 2. Searches vector database
// 3. Generates response with citations
```

### 3. Federated Search

```php
// Master node automatically searches child nodes
$response = $rag->processMessage(
    message: 'Show me all Laravel tutorials',
    sessionId: 'user-123',
    availableCollections: [
        'App\Models\Post',      // May be on child node 1
        'App\Models\Document',  // May be on child node 2
        'App\Models\Tutorial'   // May be on master
    ]
);

// Searches all nodes, merges results, cites sources!
```

### 4. Model Registry

```bash
# Sync latest models
php artisan ai-engine:sync-models

# List all models
php artisan ai-engine:list-models

# Add custom model
php artisan ai-engine:add-model gpt-5 --interactive
```

### 5. Vector Indexing

```bash
# Index a model
php artisan ai-engine:vector-index "App\Models\Post"

# Check status
php artisan ai-engine:vector-status

# Search
php artisan ai-engine:vector-search "Laravel routing" --model="App\Models\Post"
```

---

## 📖 Usage Examples

### Smart Email Assistant

```php
$response = $rag->processMessage(
    message: 'Do I have any unread emails from yesterday?',
    sessionId: 'user-123',
    availableCollections: ['App\Models\Email']
);

// Response:
// "Yes, you have 3 unread emails from yesterday:
// 1. Meeting Reminder from John [Source 0]
// 2. Invoice from Accounting [Source 1]  
// 3. Project Update from Sarah [Source 2]"
```

### Document Search

```php
$response = $rag->processMessage(
    message: 'Find documents about Laravel routing',
    sessionId: 'user-123',
    availableCollections: ['App\Models\Document', 'App\Models\Post']
);

// Searches across multiple collections and nodes!
```

### Multi-Engine Chat

```php
// Use different engines
$openai = $chat->processMessage('Hello', 'session-1', engine: 'openai');
$claude = $chat->processMessage('Hello', 'session-2', engine: 'anthropic');
$gemini = $chat->processMessage('Hello', 'session-3', engine: 'google');
```

### Streaming Responses

```php
$chat->streamMessage(
    message: 'Write a long story',
    sessionId: 'user-123',
    callback: function($chunk) {
        echo $chunk;
        flush();
    }
);
```

---

## 🎯 Artisan Commands

### Node Management
```bash
# Register a node
php artisan ai-engine:node-register

# List nodes
php artisan ai-engine:node-list

# Ping nodes
php artisan ai-engine:node-ping

# Monitor nodes
php artisan ai-engine:monitor-nodes

# Node statistics
php artisan ai-engine:node-stats

# Discover collections
php artisan ai-engine:discover-collections
```

### Model Registry
```bash
# Sync models from providers
php artisan ai-engine:sync-models

# List all models
php artisan ai-engine:list-models

# Add custom model
php artisan ai-engine:add-model

# Analyze model for RAG
php artisan ai-engine:analyze-model
```

### Vector Operations
```bash
# Index models
php artisan ai-engine:vector-index

# Check status
php artisan ai-engine:vector-status

# Search vectors
php artisan ai-engine:vector-search

# Clean vectors
php artisan ai-engine:vector-clean

# Analytics
php artisan ai-engine:vector-analytics
```

### Testing
```bash
# Test RAG system
php artisan ai-engine:test-rag

# Test nodes
php artisan ai-engine:test-nodes

# Test engines
php artisan ai-engine:test-engines

# Full test suite
php artisan ai-engine:test-package
```

---

## 📚 Documentation

- **[Federated RAG Guide](docs/archive/FEDERATED-RAG-GUIDE.md)** - Complete federated setup
- **[Node Registration](docs/archive/NODE-REGISTRATION-GUIDE.md)** - Register and manage nodes
- **[Master Node Architecture](docs/archive/MASTER-NODE-ARCHITECTURE.md)** - Architecture overview
- **[Testing Guide](docs/archive/TESTING-AND-DEPLOYMENT-GUIDE.md)** - Testing and deployment
- **[API Reference](docs/)** - Full API documentation

---

## 🔧 Configuration

### Key Configuration Options

```php
// config/ai-engine.php

return [
    // Federated RAG
    'nodes' => [
        'enabled' => env('AI_ENGINE_NODES_ENABLED', false),
        'is_master' => env('AI_ENGINE_IS_MASTER', false),
        'jwt_secret' => env('AI_ENGINE_JWT_SECRET'),
    ],
    
    // Intelligent RAG
    'intelligent_rag' => [
        'enabled' => true,
        'min_relevance_score' => 0.3,  // Balanced threshold
        'max_context_items' => 5,
        'auto_discover' => true,
    ],
    
    // Vector Search
    'vector' => [
        'provider' => 'openai',  // openai, voyage, cohere
        'dimensions' => 1536,
        'threshold' => 0.3,
    ],
];
```

---

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

---

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

## 🎉 What's New

### Latest Features (December 2025)

✨ **Federated RAG System**
- Distribute collections across multiple nodes
- Auto-discovery of remote collections
- Transparent federated search
- Health monitoring and circuit breakers

✨ **Flexible System Prompt**
- Works with ANY embedded content
- Searches emails, posts, documents, files
- Smart context-based responses
- Better user experience

✨ **Optimized Thresholds**
- Default threshold: 0.3 (balanced)
- Better search results
- More relevant context
- Improved accuracy

✨ **Enhanced Discovery**
- Auto-discovers Vectorizable models
- Skips models without trait
- Handles fatal errors gracefully
- Faster and more reliable

---

## 💡 Support

- **Issues**: [GitHub Issues](https://github.com/mabou7agar/laravel-ai-support/issues)
- **Discussions**: [GitHub Discussions](https://github.com/mabou7agar/laravel-ai-support/discussions)
- **Email**: support@m-tech-stack.com

---

<div align="center">

**Built with ❤️ by [M-Tech Stack](https://github.com/mabou7agar)**

⭐ Star us on GitHub if you find this package useful!

</div>
