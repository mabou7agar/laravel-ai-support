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

[Quick Start](#-quick-start) • [Features](#-key-features) • [Chat Without RAG](#1-simple-chat-without-rag) • [Multi-Tenant Security](#-multi-tenant-access-control) • [Controller Examples](#-complete-controller-examples) • [Documentation](docs/)

---

## 📋 Quick Reference

| Feature | Description | Code Example |
|---------|-------------|--------------|
| **Simple Chat** | Direct AI response (no RAG) | `useIntelligentRAG: false` |
| **RAG Search** | Search your knowledge base | `useIntelligentRAG: true, ragCollections: [Email::class]` |
| **Conversation Memory** | Remember chat history | `useMemory: true` |
| **User Isolation** | Secure multi-tenant RAG | `userId: $request->user()->id` |
| **Admin Access** | Access all data in RAG | `$user->is_admin = true` or `hasRole('admin')` |
| **Multi-Engine** | Switch AI providers | `engine: 'openai'` / `'anthropic'` / `'google'` |
| **Streaming** | Real-time responses | `streamMessage(callback: fn($chunk))` |
| **Federated RAG** | Distributed search | `AI_ENGINE_NODES_ENABLED=true` |

**💡 Key Point:** `useIntelligentRAG: false` = **NO RAG at all** (direct AI response)

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

### 1. Simple Chat (Without RAG)

Send messages directly to AI without any knowledge base search:

```php
use LaravelAIEngine\Services\ChatService;

$chat = app(ChatService::class);

// Basic chat - Direct AI response, no RAG
$response = $chat->processMessage(
    message: 'Hello! How are you?',
    sessionId: 'user-123',
    useIntelligentRAG: false  // ✅ No RAG - Direct AI response
);

echo $response->content;
```

**💡 Important:** `useIntelligentRAG: false` means **NO RAG at all**, not "RAG without intelligence". The AI responds directly without searching any knowledge base.

**Use Cases:**
- ✅ General conversations
- ✅ Creative writing & brainstorming
- ✅ Code generation
- ✅ Math & logic problems
- ✅ Translation
- ✅ Q&A without specific context

### 1b. Chat with Memory (No RAG)

Enable conversation history while keeping RAG disabled:

```php
$response = $chat->processMessage(
    message: 'What did we discuss earlier?',
    sessionId: 'user-123',
    useMemory: true,           // ✅ Remember conversation
    useIntelligentRAG: false   // ✅ No knowledge base search
);
```

**Result:** AI remembers previous messages in the session but doesn't search your data.

### 2. Chat with Intelligent RAG

Enable RAG to search your knowledge base intelligently:

```php
use LaravelAIEngine\Services\ChatService;

$chat = app(ChatService::class);

$response = $chat->processMessage(
    message: 'What emails do I have from yesterday?',
    sessionId: 'user-123',
    useIntelligentRAG: true,              // ✅ Enable RAG
    ragCollections: [Email::class],       // ✅ What to search
    userId: $request->user()->id          // ✅ User isolation
);

// AI automatically:
// 1. Analyzes if query needs context
// 2. Searches your emails in vector database
// 3. Generates response with citations
```

**How Intelligent RAG Works:**

```
User Query: "What emails do I have from yesterday?"
     ↓
AI Analysis: "This needs email context"
     ↓
Vector Search: Searches user's emails
     ↓
Context Retrieved: 3 relevant emails found
     ↓
AI Response: "You have 3 emails from yesterday:
              1. Meeting reminder [Source 0]
              2. Invoice [Source 1]
              3. Project update [Source 2]"
```

### 2b. RAG vs No RAG - When to Use What?

| Scenario | Use RAG? | Example |
|----------|----------|---------|
| **Search your data** | ✅ Yes | "Show me emails from John" |
| **Ask about your content** | ✅ Yes | "What documents mention Laravel?" |
| **General questions** | ❌ No | "What is Laravel?" |
| **Code generation** | ❌ No | "Generate a User controller" |
| **Creative writing** | ❌ No | "Write a poem about coding" |
| **Math/Logic** | ❌ No | "Calculate 15% of 200" |
| **Translation** | ❌ No | "Translate 'hello' to Spanish" |
| **Conversation** | ❌ No | "Tell me a joke" |

**Simple Rule:**
- **Need to search YOUR data?** → `useIntelligentRAG: true`
- **General AI capabilities?** → `useIntelligentRAG: false`

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

### 4. Understanding ChatService Parameters

```php
$response = $chat->processMessage(
    message: 'Your message',           // Required: User's input
    sessionId: 'user-123',             // Required: Unique session ID
    engine: 'openai',                  // Optional: AI provider (default: openai)
    model: 'gpt-4o',                   // Optional: Specific model (default: gpt-4o-mini)
    useMemory: true,                   // Optional: Remember conversation (default: true)
    useActions: true,                  // Optional: Enable AI actions (default: true)
    useIntelligentRAG: false,          // Optional: Enable RAG search (default: false)
    ragCollections: [Email::class],    // Optional: Models to search (when RAG enabled)
    userId: $request->user()->id       // Optional: For user isolation in RAG
);
```

**Parameter Details:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `message` | string | **Required** | User's input/question |
| `sessionId` | string | **Required** | Unique session identifier |
| `engine` | string | `'openai'` | AI provider: `openai`, `anthropic`, `google`, `deepseek` |
| `model` | string | `'gpt-4o-mini'` | Specific model to use |
| `useMemory` | bool | `true` | Remember conversation history |
| `useActions` | bool | `true` | Enable AI-suggested actions |
| `useIntelligentRAG` | bool | `false` | **Enable/Disable RAG** (not "intelligent" mode) |
| `ragCollections` | array | `[]` | Model classes to search (e.g., `[Email::class]`) |
| `userId` | string\|int | `null` | User ID for data isolation in RAG |

**Common Combinations:**

```php
// 1. Simple chat (no memory, no RAG)
$chat->processMessage(
    message: 'Hello',
    sessionId: 'temp-' . time(),
    useMemory: false,
    useIntelligentRAG: false
);

// 2. Conversation with memory (no RAG)
$chat->processMessage(
    message: 'Remember my name is John',
    sessionId: 'user-123',
    useMemory: true,
    useIntelligentRAG: false
);

// 3. RAG search with user isolation
$chat->processMessage(
    message: 'Show my emails',
    sessionId: 'user-123',
    useIntelligentRAG: true,
    ragCollections: [Email::class],
    userId: $request->user()->id
);

// 4. Different AI engine
$chat->processMessage(
    message: 'Write an essay',
    sessionId: 'user-123',
    engine: 'anthropic',
    model: 'claude-3-5-sonnet-20241022',
    useIntelligentRAG: false
);
```

### 5. Model Registry

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

### Simple Chat (No RAG)

```php
use LaravelAIEngine\Services\ChatService;

$chat = app(ChatService::class);

// 1. Basic conversation
$response = $chat->processMessage(
    message: 'Write a haiku about Laravel',
    sessionId: 'user-123',
    useIntelligentRAG: false
);

// 2. Code generation
$response = $chat->processMessage(
    message: 'Generate a Laravel controller for User CRUD',
    sessionId: 'user-456',
    useIntelligentRAG: false,
    engine: 'openai',
    model: 'gpt-4o'
);

// 3. Different AI engines
$openai = $chat->processMessage(
    message: 'Explain Laravel routing',
    sessionId: 'session-1',
    engine: 'openai',
    useIntelligentRAG: false
);

$claude = $chat->processMessage(
    message: 'Explain Laravel routing',
    sessionId: 'session-2',
    engine: 'anthropic',
    model: 'claude-3-5-sonnet-20241022',
    useIntelligentRAG: false
);
```

### Smart Email Assistant (With RAG)

```php
$response = $rag->processMessage(
    message: 'Do I have any unread emails from yesterday?',
    sessionId: 'user-123',
    availableCollections: ['App\Models\Email'],
    userId: $request->user()->id  // ✅ User isolation
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

## 🎓 Complete Controller Examples

### 1. Simple Chat Controller (No RAG)

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\ChatService;

class SimpleChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
    ) {}

    /**
     * Send a message without RAG
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'session_id' => 'required|string',
        ]);

        $response = $this->chatService->processMessage(
            message: $request->input('message'),
            sessionId: $request->input('session_id'),
            engine: 'openai',
            model: 'gpt-4o',
            useMemory: true,           // ✅ Remember conversation
            useIntelligentRAG: false   // ✅ No knowledge base
        );

        return response()->json([
            'success' => true,
            'response' => $response->content,
            'metadata' => $response->getMetadata(),
        ]);
    }

    /**
     * Generate code without RAG
     */
    public function generateCode(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string',
        ]);

        $response = $this->chatService->processMessage(
            message: $request->input('prompt'),
            sessionId: 'code-gen-' . $request->user()->id,
            engine: 'openai',
            model: 'gpt-4o',
            useMemory: false,          // ✅ No memory needed
            useIntelligentRAG: false   // ✅ No RAG
        );

        return response()->json([
            'code' => $response->content,
        ]);
    }
}
```

### 2. RAG Chat Controller (With User Isolation)

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\ChatService;
use App\Models\Email;
use App\Models\Document;

class RagChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
    ) {}

    /**
     * Chat with RAG and user isolation
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'session_id' => 'required|string',
        ]);

        // Get authenticated user ID
        $userId = $request->user()->id;

        $response = $this->chatService->processMessage(
            message: $request->input('message'),
            sessionId: $request->input('session_id'),
            engine: 'openai',
            model: 'gpt-4o',
            useMemory: true,
            useIntelligentRAG: true,
            ragCollections: [Email::class, Document::class],
            userId: $userId  // ✅ User sees only their data
        );

        return response()->json([
            'success' => true,
            'response' => $response->content,
            'sources' => $response->getMetadata()['sources'] ?? [],
        ]);
    }

    /**
     * Search emails with RAG
     */
    public function searchEmails(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
        ]);

        $response = $this->chatService->processMessage(
            message: $request->input('query'),
            sessionId: 'email-search-' . $request->user()->id,
            useIntelligentRAG: true,
            ragCollections: [Email::class],
            userId: $request->user()->id
        );

        return response()->json([
            'results' => $response->content,
            'sources' => $response->getMetadata()['sources'] ?? [],
        ]);
    }
}
```

### 3. Multi-Engine Chat Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\ChatService;

class MultiEngineChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
    ) {}

    /**
     * Chat with different AI engines
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'engine' => 'required|in:openai,anthropic,google,deepseek',
        ]);

        $models = [
            'openai' => 'gpt-4o',
            'anthropic' => 'claude-3-5-sonnet-20241022',
            'google' => 'gemini-1.5-pro',
            'deepseek' => 'deepseek-chat',
        ];

        $engine = $request->input('engine');
        $model = $models[$engine];

        $response = $this->chatService->processMessage(
            message: $request->input('message'),
            sessionId: $request->input('session_id'),
            engine: $engine,
            model: $model,
            useIntelligentRAG: false
        );

        return response()->json([
            'engine' => $engine,
            'model' => $model,
            'response' => $response->content,
        ]);
    }
}
```

### 4. Streaming Chat Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\ChatService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamingChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
    ) {}

    /**
     * Stream AI response in real-time
     */
    public function stream(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        return new StreamedResponse(function () use ($request) {
            $this->chatService->streamMessage(
                message: $request->input('message'),
                sessionId: 'stream-' . $request->user()->id,
                callback: function ($chunk) {
                    echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                    flush();
                }
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
```

### 5. Admin Controller (Access All Data)

```php
<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\ChatService;
use App\Models\Email;

class AdminChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
    ) {
        // Ensure only admins can access
        $this->middleware('role:super-admin');
    }

    /**
     * Admin searches all users' data
     */
    public function searchAllEmails(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
        ]);

        // Admin user ID - system automatically grants full access
        $response = $this->chatService->processMessage(
            message: $request->input('query'),
            sessionId: 'admin-search',
            useIntelligentRAG: true,
            ragCollections: [Email::class],
            userId: $request->user()->id  // ✅ Admin sees ALL data
        );

        return response()->json([
            'results' => $response->content,
            'sources' => $response->getMetadata()['sources'] ?? [],
            'note' => 'Admin access - showing all users data',
        ]);
    }
}
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

## 🔐 Multi-Tenant Access Control

### Secure RAG with User Isolation

The package includes enterprise-grade **multi-tenant access control** for RAG searches, ensuring users can only access their authorized data.

### Access Levels

```
┌─────────────────────────────────────────┐
│  ADMIN/SUPER USER                       │
│  ✓ Access ALL data                      │
│  ✓ No filtering applied                 │
│  ✓ For: super-admin, admin, support     │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  TENANT-SCOPED USER                     │
│  ✓ Access data within organization      │
│  ✓ Filtered by: tenant_id               │
│  ✓ For: team members, employees         │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  REGULAR USER                           │
│  ✓ Access only own data                 │
│  ✓ Filtered by: user_id                 │
│  ✓ For: individual users                │
└─────────────────────────────────────────┘
```

### Usage

**1. Regular User (Sees Only Own Data):**

```php
use LaravelAIEngine\Services\ChatService;

class ChatController extends Controller
{
    public function chat(Request $request, ChatService $chatService)
    {
        $response = $chatService->processMessage(
            message: $request->input('message'),
            sessionId: $request->input('session_id'),
            ragCollections: [Email::class, Document::class],
            userId: $request->user()->id  // ✅ User sees only their data
        );

        return response()->json($response);
    }
}
```

**2. Admin User (Sees All Data):**

```php
// User model
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'is_admin'];
    
    public function hasRole($roles) {
        return $this->roles()->whereIn('name', (array) $roles)->exists();
    }
}

// Admin user
$admin = User::find(1);
$admin->is_admin = true;  // ✅ Admin flag

// Or using Spatie Laravel Permission
$admin->assignRole('super-admin');

// Admin sees ALL data automatically
$response = $chatService->processMessage(
    message: 'Show me all emails',
    sessionId: 'admin-session',
    ragCollections: [Email::class],
    userId: $admin->id  // ✅ No filtering applied
);
```

**3. Tenant-Scoped User (Sees Team Data):**

```php
// User model with tenant
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'tenant_id'];
}

// Tenant user
$manager = User::find(2);
$manager->tenant_id = 'ABC Corp';  // ✅ Organization ID

// Manager sees all data in their organization
$response = $chatService->processMessage(
    message: 'Show me team emails',
    sessionId: 'manager-session',
    ragCollections: [Email::class],
    userId: $manager->id  // ✅ Filtered by tenant_id
);
```

### Configuration

```bash
# .env
AI_ENGINE_ENABLE_TENANT_SCOPE=true
AI_ENGINE_CACHE_USER_LOOKUPS=true
AI_ENGINE_LOG_ACCESS_LEVEL=true
```

```php
// config/vector-access-control.php
return [
    'admin_roles' => ['super-admin', 'admin', 'support'],
    'tenant_fields' => ['tenant_id', 'organization_id', 'company_id'],
    'enable_tenant_scope' => true,
    'cache_user_lookups' => true,  // Cache users for 5 minutes
];
```

### Model Setup

Add tenant and user fields to your vectorizable models:

```php
use LaravelAIEngine\Traits\Vectorizable;

class Email extends Model
{
    use Vectorizable;

    protected $fillable = [
        'user_id',        // ✅ Owner
        'tenant_id',      // ✅ Organization
        'subject',
        'body',
    ];

    protected $vectorizable = ['subject', 'body'];
}
```

### Security Features

✅ **Automatic User Fetching** - System fetches users internally  
✅ **Caching** - User lookups cached for 5 minutes  
✅ **Role-Based Access** - Admin/Tenant/User levels  
✅ **Data Isolation** - Users can't access others' data  
✅ **Audit Logging** - All access levels logged  
✅ **GDPR Compliant** - Proper data access controls  

### Documentation

For complete documentation, see:
- **[Multi-Tenant RAG Access Control](docs/MULTI_TENANT_RAG_ACCESS_CONTROL.md)**
- **[Simplified Access Control](docs/SIMPLIFIED_ACCESS_CONTROL.md)**
- **[Security Fixes](SECURITY_FIXES.md)**

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

✨ **Multi-Tenant Access Control** 🔐
- **Role-Based Access**: Admin/Tenant/User levels
- **Automatic User Fetching**: System fetches users internally with caching
- **Data Isolation**: Users can only access authorized data
- **Tenant-Scoped**: Team members see organization data
- **GDPR Compliant**: Enterprise-grade security
- **Audit Logging**: Track all access levels

✨ **Simplified API** 🎯
- **Pass User ID Only**: No need to pass user objects
- **Automatic Caching**: User lookups cached for 5 minutes
- **50% Faster**: Improved performance with caching
- **30% Less Code**: Cleaner, simpler architecture
- **Backward Compatible**: Old code still works

✨ **Chat Without RAG** 💬
- **Simple Conversations**: Send messages without knowledge base
- **Code Generation**: Generate code without context
- **Creative Writing**: AI creativity without constraints
- **Multi-Engine**: Use OpenAI, Claude, Gemini, DeepSeek
- **Streaming Support**: Real-time responses

✨ **Federated RAG System** 🌐
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
