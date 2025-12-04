<div align="center">

# 🚀 Laravel AI Engine

### Enterprise-Grade Multi-AI Integration with Federated RAG

[![Latest Version](https://img.shields.io/packagist/v/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![Total Downloads](https://img.shields.io/packagist/dt/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![License](https://img.shields.io/packagist/l/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![PHP Version](https://img.shields.io/packagist/php-v/m-tech-stack/laravel-ai-engine.svg?style=flat-square)](https://packagist.org/packages/m-tech-stack/laravel-ai-engine)
[![Laravel](https://img.shields.io/badge/Laravel-9%20%7C%2010%20%7C%2011%20%7C%2012-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)

**The most advanced Laravel AI package with Federated RAG, Smart Action System, Intelligent Context Retrieval, Dynamic Model Registry, and Enterprise-Grade Multi-Tenant Security.**

**🎯 100% Future-Proof** - Automatically supports GPT-5, GPT-6, Claude 4, and all future AI models!

**🆕 New in v2.x:** Smart Action System, Workspace Isolation, Multi-Database Tenancy, and AI-powered executors for email, calendar, tasks, and more!

[Quick Start](#-quick-start) • [Features](#-key-features) • [Smart Actions](#-interactive-actions) • [Multi-Tenant Security](#-multi-tenant-access-control) • [Multi-DB Tenancy](#-multi-database-tenancy) • [Documentation](#-documentation)

---

## 📋 Quick Reference

| Feature | Description | Code Example |
|---------|-------------|--------------|
| **Simple Chat** | Direct AI response (no RAG) | `useIntelligentRAG: false` |
| **RAG Search** | Search your knowledge base | `useIntelligentRAG: true, ragCollections: [Email::class]` |
| **Aggregate Queries** | Count, statistics, summaries | `"how many emails do I have"` → Auto-detected |
| **Numbered Options** | Clickable response options | `numbered_options` in response |
| **Smart Actions** | AI-powered executable actions | `POST /api/v1/actions/execute` |
| **Action Execution** | Execute with AI param filling | `executor: 'email.reply'` → AI drafts reply |
| **Federated Actions** | Actions route to correct node | Collection-based auto-routing |
| **Conversation Memory** | Remember chat history | `useMemory: true` |
| **User Isolation** | Secure multi-tenant RAG | `userId: $request->user()->id` |
| **Workspace Isolation** | Data scoped to workspace | `$user->workspace_id = 5` |
| **Multi-DB Tenancy** | Separate collections per tenant | `AI_ENGINE_MULTI_DB_TENANCY=true` |
| **Admin Access** | Access all data in RAG | `$user->is_admin = true` or `hasRole('admin')` |
| **Multi-Engine** | Switch AI providers | `engine: 'openai'` / `'anthropic'` / `'google'` |
| **GPT-5 Support** | Full GPT-5 family support | `model: 'gpt-5-mini'` / `'gpt-5.1'` |
| **Streaming** | Real-time responses | `streamMessage(callback: fn($chunk))` |
| **Federated RAG** | Distributed search | `AI_ENGINE_NODES_ENABLED=true` |
| **Force Reindex** | Recreate collections | `php artisan ai-engine:vector-index --force` |

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
- **OpenAI**: GPT-5.1, GPT-5, GPT-5-mini, GPT-4o, O1, O3
- **Anthropic**: Claude 4.5 Sonnet, Claude 4 Opus, Claude 3.5 Sonnet
- **Google**: Gemini 3 Pro, Gemini 2.5 Pro/Flash, Gemini 2.0
- **DeepSeek**: DeepSeek V3, DeepSeek Chat
- **Perplexity**: Sonar Pro, Sonar
- **Unified API**: Same interface for all providers
- **Future-Proof**: Full support for latest models with auto-discovery

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
- **Smart Chunking**: Large content automatically chunked (never skipped)
- **Auto Payload Indexes**: Automatically detects and creates indexes from model relationships
- **Schema-Based Types**: Detects field types (integer, UUID, string) from database schema
- **Force Recreate**: `--force` flag to delete and recreate collections with fresh schema
- **Index Verification**: Auto-checks and creates missing payload indexes during indexing
- **Multi-Tenant Collections**: Configurable collection prefix with `vec_` default
- **Project Context Injection**: Configure domain-specific context for better AI understanding

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

## 🔧 Vector Indexing

### Index Your Models

```bash
# Index a specific model
php artisan ai-engine:vector-index "App\Models\Document"

# Force recreate collection with fresh schema and indexes
php artisan ai-engine:vector-index "App\Models\Document" --force

# Index with specific batch size
php artisan ai-engine:vector-index "App\Models\Document" --batch=50
```

### What Happens During Indexing

1. **Collection Check**: Verifies if collection exists
2. **Index Verification**: Checks for missing payload indexes (user_id, tenant_id, etc.)
3. **Auto-Create Indexes**: Creates any missing indexes automatically
4. **Content Chunking**: Large content is chunked (never skipped)
5. **Embedding Generation**: Creates vector embeddings via OpenAI
6. **Upsert to Qdrant**: Stores vectors with metadata

### Example Output

```
Indexing App\Models\EmailCache...
📋 Indexing Fields (From $fillable):
   • subject
   • from_address
   • body_text
   
✓ Collection 'vec_email_cache' already exists
🔍 Checking payload indexes...
   ✓ All required payload indexes exist
      • user_id
      • tenant_id
      • model_id

🔑 Payload indexes for 'vec_email_cache':
   • user_id
   • tenant_id
   • model_id
   • workspace_id
   • visibility

Found 150 models to index
 150/150 [============================] 100%

✓ Indexed 150 models successfully
```

---

## 🎯 Project Context Configuration

Provide domain-specific context to improve AI understanding:

```php
// config/ai-engine.php
'project_context' => [
    'description' => 'Email management system for enterprise customers',
    'industry' => 'SaaS / Enterprise Software',
    'key_entities' => [
        'EmailCache' => 'Cached email messages with full content',
        'Mailbox' => 'User email accounts and configurations',
        'User' => 'System users with workspace assignments',
    ],
    'business_rules' => [
        'Users can only access emails from their assigned mailboxes',
        'Admins can access all emails in their workspace',
    ],
    'terminology' => [
        'workspace' => 'Isolated tenant environment',
        'mailbox' => 'Connected email account',
    ],
    'target_users' => 'Business professionals managing email communications',
    'data_sensitivity' => 'Contains confidential business communications',
],
```

This context is automatically injected into AI prompts for better domain understanding.

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
# Sync all providers (OpenAI, Anthropic, Google, OpenRouter)
php artisan ai-engine:sync-models

# Sync specific provider
php artisan ai-engine:sync-models --provider=openai
php artisan ai-engine:sync-models --provider=anthropic
php artisan ai-engine:sync-models --provider=google
php artisan ai-engine:sync-models --provider=deepseek

# List all models
php artisan ai-engine:list-models

# Add custom model
php artisan ai-engine:add-model gpt-5 --interactive
```

**Supported Models (December 2025):**
- **OpenAI**: GPT-5.1, GPT-5, GPT-5-mini, GPT-5-nano, GPT-4o, O1, O3
- **Anthropic**: Claude 4.5 Sonnet, Claude 4 Opus, Claude 4 Sonnet, Claude 3.5
- **Google**: Gemini 3 Pro, Gemini 2.5 Pro/Flash, Gemini 2.0
- **DeepSeek**: DeepSeek V3, DeepSeek R1, DeepSeek Chat/Coder

### 5. Vector Indexing

```bash
# Index all vectorizable models
php artisan ai-engine:vector-index

# Index specific model
php artisan ai-engine:vector-index "App\Models\Post"

# Force recreate collection (deletes old, creates new with fresh schema)
php artisan ai-engine:vector-index --force

# Check status
php artisan ai-engine:vector-status

# Search
php artisan ai-engine:vector-search "Laravel routing" --model="App\Models\Post"
```

**New in v2.x: Smart Payload Indexes**

The `--force` flag now:
1. **Deletes existing collection** - Removes old collection with wrong dimensions/indexes
2. **Creates new collection** - With correct embedding dimensions
3. **Auto-detects relationships** - Creates indexes for all `belongsTo` foreign keys
4. **Schema-based types** - Detects field types (integer, UUID, string) from database

```php
// Example: EmailCache model with belongsTo relations
class EmailCache extends Model
{
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function mailbox(): BelongsTo { return $this->belongsTo(Mailbox::class); }
}

// Automatically creates payload indexes for:
// - user_id (integer - from schema)
// - mailbox_id (keyword - UUID detected from schema)
// - Plus config fields: tenant_id, workspace_id, status, etc.
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

## 📊 Aggregate Queries (Counts & Statistics)

The AI automatically detects aggregate queries like "how many", "count", "total" and retrieves statistics from your database:

```php
// User asks: "How many emails do I have?"
$response = $chat->processMessage(
    message: 'How many emails do I have?',
    sessionId: 'user-123',
    useIntelligentRAG: true,
    ragCollections: [Email::class],
    userId: $request->user()->id
);

// AI Response: "You have 14 emails in your inbox."
```

### Supported Aggregate Patterns

| Query Pattern | Example | What Happens |
|--------------|---------|--------------|
| `how many` | "How many users are there?" | Counts records |
| `count` | "Count my documents" | Counts records |
| `total` | "Total emails this week" | Counts with filters |
| `summary` | "Give me a summary of my data" | Statistics overview |
| `statistics` | "Show me statistics" | Full stats breakdown |

### Response with Statistics

```json
{
  "response": "Here's a summary of your data:\n- 14 emails\n- 5 documents\n- 3 tasks",
  "aggregate_data": {
    "Email": { "database_count": 14, "recent_count": 3 },
    "Document": { "database_count": 5 },
    "Task": { "database_count": 3 }
  }
}
```

---

## 🎯 Interactive Actions

The package includes a powerful **Smart Action System** that generates executable, AI-powered actions with automatic parameter extraction.

### Key Features

| Feature | Description |
|---------|-------------|
| **AI Parameter Extraction** | Automatically extracts emails, dates, times from content |
| **Pre-filled Actions** | Actions come ready-to-execute with all required data |
| **Federated Execution** | Actions automatically route to the correct node |
| **Smart Executors** | Built-in handlers for email, calendar, tasks, and more |

### Enabling Actions

```bash
curl -X POST 'https://your-app.test/ai/chat' \
  -H 'Content-Type: application/json' \
  -d '{
    "message": "show me my emails",
    "session_id": "user-123",
    "intelligent_rag": true,
    "actions": true
  }'
```

### Response with Smart Actions

```json
{
  "response": "You have 5 emails...",
  "actions": [
    {
      "id": "reply_email_abc123",
      "type": "button",
      "label": "✉️ Reply to Email",
      "data": {
        "action": "reply_email",
        "executor": "email.reply",
        "params": {
          "to_email": "sender@example.com",
          "subject": "Re: Meeting Tomorrow",
          "original_content": "Hi, can we meet..."
        },
        "ready": true
      }
    },
    {
      "id": "create_event_def456",
      "type": "button",
      "label": "📅 Create Calendar Event",
      "data": {
        "action": "create_event",
        "executor": "calendar.create",
        "params": {
          "title": "Meeting",
          "date": "2025-12-05",
          "time": "15:00"
        },
        "ready": true
      }
    }
  ],
  "numbered_options": [
    {
      "id": "opt_1_abc123",
      "number": 1,
      "text": "Undelivered Mail Returned to Sender",
      "source_index": 0,
      "clickable": true
    }
  ],
  "has_options": true
}
```

### Action Execution API

Execute actions via dedicated endpoints:

```bash
# Execute any action
POST /api/v1/actions/execute
{
  "action_type": "reply_email",
  "data": {
    "executor": "email.reply",
    "params": {
      "to_email": "user@example.com",
      "subject": "Re: Meeting",
      "original_content": "Original email content..."
    },
    "ready": true
  }
}

# Response with AI-generated draft
{
  "success": true,
  "result": {
    "type": "email_reply",
    "action": "compose_email",
    "data": {
      "to": "user@example.com",
      "subject": "Re: Meeting",
      "body": "Thank you for your message. I'm available...",
      "ready_to_send": true
    }
  }
}
```

### Smart Executors

| Executor | Description | Output |
|----------|-------------|--------|
| `email.reply` | AI-generated email reply | Draft body, recipient, subject |
| `email.forward` | Forward email | Forwarded content with note |
| `calendar.create` | Create calendar event | ICS data + Google Calendar URL |
| `task.create` | Create task/todo | Task with due date, priority |
| `ai.summarize` | Summarize content | Concise summary |
| `ai.translate` | Translate content | Translated text |
| `source.view` | View source document | Full document data |
| `source.find_similar` | Find similar content | Related items |

### Calendar Event Example

```bash
POST /api/v1/actions/execute
{
  "action_type": "create_event",
  "data": {
    "executor": "calendar.create",
    "params": {
      "title": "Project Discussion",
      "date": "2025-12-05",
      "time": "15:00",
      "duration": 60,
      "location": "Conference Room A"
    }
  }
}

# Response
{
  "success": true,
  "result": {
    "type": "calendar_event",
    "data": {
      "title": "Project Discussion",
      "date": "2025-12-05",
      "time": "15:00",
      "ics_data": "BEGIN:VCALENDAR...",
      "google_calendar_url": "https://calendar.google.com/..."
    }
  }
}
```

### Federated Action Execution

Actions automatically route to the correct node based on collection ownership:

```bash
# Execute on specific remote node
POST /api/v1/actions/execute-remote
{
  "node": "emails-node",
  "action_type": "view_source",
  "data": {
    "params": { "model_class": "App\\Models\\Email", "model_id": 123 }
  }
}

# Execute on all nodes
POST /api/v1/actions/execute-all
{
  "action_type": "sync_data",
  "data": { ... },
  "parallel": true
}

# Get actions from all nodes
GET /api/v1/actions/available?include_remote=true
```

### Collection-Based Routing

When a node registers its collections, actions automatically route:

```php
// Node registration with collections
$registry->register([
    'name' => 'Emails Node',
    'slug' => 'emails-node',
    'url' => 'https://emails.example.com',
    'collections' => ['App\\Models\\Email', 'App\\Models\\EmailAttachment'],
]);

// Action automatically routes to emails-node
POST /api/v1/actions/execute
{
  "action_type": "view_source",
  "data": {
    "params": { "model_class": "App\\Models\\Email", "model_id": 123 }
  }
}
// → Automatically executed on emails-node!
```

### Frontend Integration

```javascript
async function executeAction(action) {
    const response = await fetch('/api/v1/actions/execute', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
            action_type: action.data.action,
            data: action.data
        })
    });
    
    const result = await response.json();
    
    if (result.success) {
        switch (result.result.type) {
            case 'email_reply':
                openEmailComposer(result.result.data);
                break;
            case 'calendar_event':
                // Open Google Calendar or download ICS
                window.open(result.result.data.google_calendar_url);
                break;
            case 'summary':
                showSummary(result.result.data.summary);
                break;
        }
    }
}
```

### Numbered Options

When AI returns a numbered list, options are automatically extracted:

```javascript
response.numbered_options.forEach(option => {
    console.log(`${option.number}. ${option.text}`);
    // Each option has:
    // - id: Unique identifier (opt_1_abc123)
    // - number: Display number (1, 2, 3...)
    // - text: Title/subject
    // - source_index: Links to sources array
    // - clickable: true
});

// Select an option
POST /api/v1/actions/select-option
{
  "option_number": 1,
  "session_id": "user-123",
  "source_index": 0,
  "sources": [{ "model_id": 382, "model_class": "App\\Models\\Email" }]
}
```

📖 **Full Documentation:** [docs/actions.md](docs/actions.md)

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

### 📖 Core Documentation

| Guide | Description |
|-------|-------------|
| **[Quick Start](docs/quickstart.md)** | Get started in 5 minutes |
| **[Installation](docs/installation.md)** | Complete installation guide |
| **[Configuration](docs/configuration.md)** | All configuration options |
| **[RAG Guide](docs/rag.md)** | Retrieval-Augmented Generation |
| **[Vector Search](docs/vector-search.md)** | Semantic search setup |
| **[Conversations](docs/conversations.md)** | Chat with memory |
| **[Multi-Modal](docs/multimodal.md)** | Images, audio, documents |

### 🔐 Security & Access Control

| Guide | Description |
|-------|-------------|
| **[Multi-Tenant Access](docs/MULTI_TENANT_RAG_ACCESS_CONTROL.md)** | User/Tenant/Admin isolation |
| **[Simplified Access](docs/SIMPLIFIED_ACCESS_CONTROL.md)** | Quick access control setup |
| **[Security Fixes](SECURITY_FIXES.md)** | Security best practices |

### 🌐 Federated RAG

| Guide | Description |
|-------|-------------|
| **[Federated RAG Success](docs/FEDERATED-RAG-SUCCESS.md)** | Complete federated setup |
| **[Master Node Usage](docs/MASTER_NODE_CLIENT_USAGE.md)** | Master node configuration |
| **[Node Registration](docs/archive/NODE-REGISTRATION-GUIDE.md)** | Register child nodes |

### 🎯 Advanced Features

| Guide | Description |
|-------|-------------|
| **[Chunking Strategies](docs/CHUNKING-STRATEGIES.md)** | Smart content splitting |
| **[Large Media Processing](docs/LARGE-MEDIA-PROCESSING.md)** | Handle large files |
| **[URL & Media Embeddings](docs/URL-MEDIA-EMBEDDINGS.md)** | Embed URLs and media |
| **[User Context Injection](docs/USER_CONTEXT_INJECTION.md)** | Inject user context |
| **[Troubleshooting RAG](docs/TROUBLESHOOTING_NO_RAG_RESULTS.md)** | Fix common issues |

### 🔧 Integration Guides

| Guide | Description |
|-------|-------------|
| **[Ollama Integration](OLLAMA-INTEGRATION.md)** | Local LLM with Ollama |
| **[Ollama Quickstart](OLLAMA-QUICKSTART.md)** | Quick Ollama setup |
| **[Performance Optimization](PERFORMANCE_OPTIMIZATION.md)** | Speed & efficiency |
| **[Postman Collection](postman/README.md)** | API testing with Postman |

### 📋 Reference

| Resource | Description |
|----------|-------------|
| **[Changelog](CHANGELOG.md)** | Version history |
| **[API Reference](docs/README.md)** | Full API documentation |
| **[Artisan Commands](#-artisan-commands)** | CLI reference |

---

## 🔐 Multi-Tenant Access Control

### Secure RAG with User Isolation

The package includes enterprise-grade **multi-tenant access control** for RAG searches, ensuring users can only access their authorized data.

### Access Levels

```
┌─────────────────────────────────────────┐
│  LEVEL 1: ADMIN/SUPER USER              │
│  ✓ Access ALL data                      │
│  ✓ No filtering applied                 │
│  ✓ For: super-admin, admin, support     │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  LEVEL 2: TENANT-SCOPED USER            │
│  ✓ Access data within organization      │
│  ✓ Filtered by: tenant_id               │
│  ✓ For: team members, employees         │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  LEVEL 2.5: WORKSPACE-SCOPED USER  🆕   │
│  ✓ Access data within workspace         │
│  ✓ Filtered by: workspace_id            │
│  ✓ For: workspace members               │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  LEVEL 3: REGULAR USER                  │
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

**4. Workspace-Scoped User (Sees Workspace Data):** 🆕

```php
// User model with workspace
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'workspace_id'];
}

// Workspace member
$member = User::find(3);
$member->workspace_id = 5;  // ✅ Workspace ID

// Member sees all data in their workspace
$response = $chatService->processMessage(
    message: 'Show me workspace documents',
    sessionId: 'workspace-session',
    ragCollections: [Document::class],
    userId: $member->id  // ✅ Filtered by workspace_id
);
```

### Configuration

```bash
# .env
AI_ENGINE_ENABLE_TENANT_SCOPE=true
AI_ENGINE_ENABLE_WORKSPACE_SCOPE=true
AI_ENGINE_CACHE_USER_LOOKUPS=true
AI_ENGINE_LOG_ACCESS_LEVEL=true

# Multi-Database Tenancy (optional)
AI_ENGINE_MULTI_DB_TENANCY=false
AI_ENGINE_MULTI_DB_COLLECTION_STRATEGY=prefix
```

```php
// config/vector-access-control.php
return [
    'admin_roles' => ['super-admin', 'admin', 'support'],
    'tenant_fields' => ['tenant_id', 'organization_id', 'company_id'],
    'workspace_fields' => ['workspace_id', 'current_workspace_id'],
    'enable_tenant_scope' => true,
    'enable_workspace_scope' => true,
    'cache_user_lookups' => true,
];
```

### Model Setup

Add tenant, workspace, and user fields to your vectorizable models:

```php
use LaravelAIEngine\Traits\Vectorizable;

class Email extends Model
{
    use Vectorizable;

    protected $fillable = [
        'user_id',        // ✅ Owner
        'tenant_id',      // ✅ Organization
        'workspace_id',   // ✅ Workspace (optional)
        'subject',
        'body',
    ];

    protected $vectorizable = ['subject', 'body'];
    
    // Optional: Custom display name for actions
    public function getRagDisplayName(): string
    {
        return 'Email';  // Shows "View Full Email" instead of "View Full EmailCache"
    }
}
```

### Security Features

✅ **Automatic User Fetching** - System fetches users internally  
✅ **Caching** - User lookups cached for 5 minutes  
✅ **Role-Based Access** - Admin/Tenant/Workspace/User levels  
✅ **Data Isolation** - Users can't access others' data  
✅ **Workspace Support** - Isolate data by workspace  
✅ **Audit Logging** - All access levels logged  
✅ **GDPR Compliant** - Proper data access controls  

---

## 🏢 Multi-Database Tenancy

For applications where each tenant has their own database, the package supports **complete data isolation** at the vector database level using tenant-specific collections.

### Architecture Comparison

```
Single-DB Tenancy:                    Multi-DB Tenancy:
┌─────────────────────┐              ┌─────────────────────┐
│  vec_emails         │              │  acme_vec_emails    │
│  ├─ tenant_id: 1    │              │  └─ (all Acme data) │
│  ├─ tenant_id: 2    │              ├─────────────────────┤
│  └─ tenant_id: 3    │              │  globex_vec_emails  │
└─────────────────────┘              │  └─ (all Globex)    │
     (filter by ID)                  └─────────────────────┘
                                          (separate collections)
```

### Configuration

```bash
# .env
AI_ENGINE_MULTI_DB_TENANCY=true
AI_ENGINE_MULTI_DB_COLLECTION_STRATEGY=prefix  # prefix, suffix, or separate
AI_ENGINE_TENANT_RESOLVER=session              # session, config, database, or custom
```

### Collection Naming Strategies

| Strategy | Example |
|----------|---------|
| `prefix` | `acme_vec_emails` |
| `suffix` | `vec_emails_acme` |
| `separate` | `acme/vec_emails` |

### Custom Tenant Resolver

```php
use LaravelAIEngine\Contracts\TenantResolverInterface;

class MyTenantResolver implements TenantResolverInterface
{
    public function getCurrentTenantId(): ?string { return tenant()?->id; }
    public function getCurrentTenantSlug(): ?string { return tenant()?->slug; }
    public function getTenantConnection(): ?string { return tenant()?->database; }
    public function hasTenant(): bool { return tenant() !== null; }
}
```

### Supported Packages

Auto-detection for: **Spatie Laravel Multitenancy**, **Stancl Tenancy**, **Tenancy for Laravel (Hyn)**

### Documentation

For complete documentation, see:
- **[Multi-Tenant RAG Access Control](docs/MULTI_TENANT_RAG_ACCESS_CONTROL.md)**
- **[Workspace Isolation](docs/WORKSPACE_ISOLATION.md)** 🆕
- **[Multi-Database Tenancy](docs/MULTI_DATABASE_TENANCY.md)** 🆕
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

## ⚡ Performance Tuning

### Model Selection for RAG

Choose the right models for optimal performance:

```bash
# .env
INTELLIGENT_RAG_ANALYSIS_MODEL=gpt-4o-mini    # Fast query classification
INTELLIGENT_RAG_RESPONSE_MODEL=gpt-5-mini     # Quality responses
```

| Task | Recommended Model | Why |
|------|------------------|-----|
| **Query Analysis** | `gpt-4o-mini` | Fast, cheap, sufficient for classification |
| **Response Generation** | `gpt-5-mini` | Good quality, balanced cost |
| **Complex Reasoning** | `gpt-5.1` | Best quality, higher cost |
| **High Throughput** | `gpt-4o-mini` | Fastest, lowest cost |

### Context Optimization

```bash
# .env
VECTOR_RAG_MAX_ITEM_LENGTH=2000    # Truncate long content (chars)
VECTOR_RAG_MAX_CONTEXT=5           # Max context items
```

### Vector Index Performance

```bash
# Force recreate collections with fresh schema
php artisan ai-engine:vector-index --force

# This ensures:
# - Correct embedding dimensions
# - Proper payload indexes for filtering
# - Schema-based field types
```

### GPT-5 vs GPT-4 Performance

| Model | Analysis Time | Response Time | Best For |
|-------|--------------|---------------|----------|
| gpt-4o-mini | ~1-2s | ~2s | Fast, cheap tasks |
| gpt-4o | ~2s | ~3s | Balanced quality |
| gpt-5-nano | ~5-6s | ~5s | Simple reasoning |
| gpt-5-mini | ~5-6s | ~5s | Quality responses |
| gpt-5.1 | ~8-10s | ~8s | Complex reasoning |

**Note:** GPT-5 models have reasoning overhead even for simple tasks. Use `gpt-4o-mini` for analysis.

---

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

---

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

## 📡 API Endpoints Reference

### Chat Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/ai/chat` | Main chat endpoint with RAG |
| `POST` | `/api/v1/rag/chat` | RAG chat API |
| `GET` | `/api/v1/rag/conversations` | List user conversations |

### Action Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/actions/execute` | Execute any action (local or remote) |
| `POST` | `/api/v1/actions/execute-remote` | Execute on specific remote node |
| `POST` | `/api/v1/actions/execute-all` | Execute on all nodes |
| `POST` | `/api/v1/actions/select-option` | Select a numbered option |
| `GET` | `/api/v1/actions/available` | Get available actions |

### Node/Federation Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/nodes/health` | Node health check |
| `GET` | `/api/v1/nodes/collections` | List node collections |
| `POST` | `/api/v1/nodes/search` | Federated search |

---

## 🎉 What's New

### Latest Features (December 2025)

✨ **Smart Content Chunking** 📄 (NEW!)
- **Never Skip**: Large content is automatically chunked, never skipped
- **Intelligent Splitting**: 50% beginning + 30% end + 20% middle sample
- **Configurable Limits**: `VECTOR_MAX_CONTENT_SIZE=30000` (30KB default)
- **Word Boundaries**: Chunks break at natural word boundaries

✨ **Project Context Injection** 🎯 (NEW!)
- **Domain Understanding**: Configure project description, industry, entities
- **Business Rules**: Define rules the AI should follow
- **Terminology**: Custom vocabulary for your domain
- **Auto-Injection**: Context automatically added to AI prompts

✨ **Enhanced Vector Indexing** 🔧 (NEW!)
- **Index Verification**: Auto-checks for missing payload indexes
- **Auto-Create**: Missing indexes created automatically during indexing
- **Collection Prefix**: Always uses `vec_` prefix for consistency
- **Multi-Tenant Ready**: Custom collection names via `getVectorCollectionName()`

✨ **Circular Dependency Fix** 🔄 (NEW!)
- **Lazy Loading**: Services use lazy loading to avoid circular deps
- **Proper DI**: Constructor injection with explicit service registration
- **No Memory Issues**: Fixed memory exhaustion during app boot

✨ **Performance Optimizations** ⚡ (NEW!)
- **Configurable Models**: Separate models for analysis vs response generation
- **Context Truncation**: Limits context item length to prevent huge prompts (2000 chars default)
- **Smart Query Analysis**: Uses exact phrases for title-like queries instead of expanding
- **Optimized Defaults**: `gpt-4o-mini` for analysis, `gpt-5-mini` for responses

```php
// config/ai-engine.php
'intelligent_rag' => [
    'analysis_model' => 'gpt-4o-mini',   // Fast, cheap - for query classification
    'response_model' => 'gpt-5-mini',    // Quality - for final response
    'max_context_item_length' => 2000,   // Truncate long content
]
```

**Performance Results:**
| Config | Analysis | Response | Total Time |
|--------|----------|----------|------------|
| Both gpt-4o-mini | ~2s | ~2s | ~3-4s |
| gpt-4o-mini + gpt-5-mini | ~2s | ~3s | ~5-6s |
| Both GPT-5 | ~6s | ~6s | ~12s |

✨ **Smart Payload Indexes** 🔍 (NEW!)
- **Auto-detect belongsTo**: Automatically creates indexes for foreign key fields
- **Schema-based types**: Detects integer, UUID, string from database
- **Force recreate**: `--force` deletes and recreates collections with fresh schema
- **Config + Relations**: Merges config fields with detected relationship fields

✨ **Latest AI Models Support** 🤖 (NEW!)
- **OpenAI GPT-5**: gpt-5.1, gpt-5, gpt-5-mini, gpt-5-nano + O3 reasoning models
- **Anthropic Claude 4**: claude-4.5-sonnet, claude-4-opus, claude-4-sonnet
- **Google Gemini 3**: gemini-3-pro-preview, gemini-3-pro-image + Gemini 2.5/2.0
- **Reasoning parameters**: `max_completion_tokens`, `reasoning_effort` handled automatically
- **Auto-detection**: Correct parameters per model family

✨ **Smart Action System** 🎯 (NEW!)
- **AI Parameter Extraction**: Automatically extracts emails, dates, times from content
- **Pre-filled Actions**: Actions come ready-to-execute with all required data
- **Smart Executors**: Built-in handlers for email, calendar, tasks, summarize, translate
- **Federated Execution**: Actions automatically route to the correct node
- **Collection-Based Routing**: Actions route based on which node owns the collection

✨ **Action Execution API** 🚀 (NEW!)
- **Execute Endpoint**: `POST /api/v1/actions/execute`
- **Remote Execution**: `POST /api/v1/actions/execute-remote`
- **Multi-Node Execution**: `POST /api/v1/actions/execute-all`
- **AI-Generated Drafts**: Email replies, calendar events, task creation

✨ **Aggregate Query Detection** 📊
- **Auto-Detection**: Queries like "how many", "count", "total" automatically detected
- **Database Statistics**: Real counts from your database, not just vector search
- **Smart Fallback**: Uses database counts when vector counts are unavailable
- **Multi-Collection**: Statistics across all your indexed models

✨ **Interactive Actions System** 🎯
- **Context-Aware Actions**: AI suggests relevant actions based on response
- **Numbered Options**: Clickable options extracted from AI responses
- **Unique IDs**: Each option has unique identifier for reliable selection
- **Source Linking**: Options link back to source documents
- **Custom Actions**: Define your own actions in config

✨ **Enhanced Query Analysis** 🧠
- **Semantic Search Terms**: AI generates better search terms for vague queries
- **Collection Validation**: Only searches collections that exist locally
- **Fallback Strategies**: Multiple fallback levels for better results
- **Conversation Context**: Uses chat history to understand follow-up queries

✨ **Multi-Tenant Access Control** 🔐
- **Role-Based Access**: Admin/Tenant/Workspace/User levels
- **Workspace Scoping**: Isolate data by workspace within tenants
- **Automatic User Fetching**: System fetches users internally with caching
- **Data Isolation**: Users can only access authorized data
- **GDPR Compliant**: Enterprise-grade security

✨ **Multi-Database Tenancy** 🏢 (NEW!)
- **Separate Collections**: Each tenant gets isolated vector collections
- **Collection Strategies**: prefix, suffix, or separate naming
- **Auto-Detection**: Works with Spatie, Stancl, Hyn tenancy packages
- **Custom Resolvers**: Implement your own tenant resolution logic

✨ **Simplified API** 🎯
- **Pass User ID Only**: No need to pass user objects
- **Automatic Caching**: User lookups cached for 5 minutes
- **50% Faster**: Improved performance with caching
- **Backward Compatible**: Old code still works

✨ **Federated RAG System** 🌐
- Distribute collections across multiple nodes
- Auto-discovery of remote collections
- Transparent federated search
- Health monitoring and circuit breakers

✨ **Dynamic Model Registry** 🤖
- **Auto-Discovery**: Automatically detects new AI models from providers
- **Zero Code Changes**: GPT-5, Claude 4 work immediately when released
- **Cost Tracking**: Pricing and capabilities for every model
- **CLI Management**: `ai-engine:sync-models`, `ai-engine:list-models`

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
