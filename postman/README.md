# Laravel AI Engine - Postman Collection

Complete Postman collection for testing all Laravel AI Engine APIs.

## 📦 What's Included

### Collections
- **Chat APIs** (7 endpoints) - Conversational AI with memory and actions
- **RAG APIs** (7 endpoints) - Retrieval-Augmented Generation
- **Node APIs** (4 endpoints) - Federated node management
- **Vector Search APIs** (4 endpoints) - Semantic search and indexing
- **Model Registry APIs** (3 endpoints) - Dynamic AI model management
- **Streaming APIs** (1 endpoint) - Real-time SSE streaming
- **Health & Status** (2 endpoints) - System monitoring

**Total: 28 API endpoints**

---

## 🚀 Quick Start

### 1. Import Collection

**Option A: Import from File**
1. Open Postman
2. Click **Import**
3. Select `Laravel-AI-Engine.postman_collection.json`
4. Click **Import**

**Option B: Import from URL**
```
https://raw.githubusercontent.com/mabou7agar/laravel-ai-support/main/postman/Laravel-AI-Engine.postman_collection.json
```

### 2. Import Environment

1. Click **Import**
2. Select `Laravel-AI-Engine.postman_environment.json`
3. Click **Import**
4. Select the environment from the dropdown (top right)

### 3. Configure Variables

Update these environment variables:

| Variable | Description | Example |
|----------|-------------|---------|
| `base_url` | Master node URL | `https://middleware.test` |
| `child_node_url` | Child node URL | `https://ai.test` |
| `api_token` | API authentication token | `21\|ABC...` |
| `node_jwt_token` | JWT for node-to-node auth | Generated via artisan |
| `session_id` | Chat session identifier | `user-123-session` |
| `model_id` | AI model identifier | `gpt-4` |

---

## 📚 API Categories

### 1️⃣ Chat APIs

**Send Chat Message**
```http
POST /ai-demo/chat/send
```
Send a message with optional RAG and memory.

**Get Chat History**
```http
GET /ai-demo/chat/history/{session_id}
```
Retrieve conversation history.

**Clear Chat History**
```http
POST /ai-demo/chat/clear
```
Clear conversation for a session.

**Get Available Engines**
```http
GET /ai-demo/chat/engines
```
List all available AI engines.

**Get Available Actions**
```http
GET /ai-demo/chat/actions
```
List dynamic actions.

**Execute Action**
```http
POST /ai-demo/chat/execute-action
```
Execute a dynamic action.

---

### 2️⃣ RAG APIs

**RAG Chat**
```http
POST /api/v1/rag/chat
```
Send message with intelligent RAG context retrieval.

**Get RAG Collections**
```http
GET /api/v1/rag/collections
```
Get available Vectorizable models.

**Get RAG Engines**
```http
GET /api/v1/rag/engines
```
List AI engines for RAG.

**RAG Health Check**
```http
GET /api/v1/rag/health
```
Check RAG system health.

**Clear RAG Chat History**
```http
POST /api/v1/rag/chat/clear
```
Clear RAG conversation history.

**Get RAG Chat History**
```http
GET /api/v1/rag/chat/history/{session_id}
```
Retrieve RAG conversation history.

**Execute RAG Action**
```http
POST /api/v1/rag/actions/execute
```
Execute a RAG-specific action.

---

### 3️⃣ Node APIs (Federated)

**Get Collections (Child Node)**
```http
GET /api/ai-engine/collections
```
Get available collections from a child node.

**Search (Child Node)**
```http
POST /api/ai-engine/search
```
Search collections on a child node.

**Ping (Child Node)**
```http
GET /api/ai-engine/ping
```
Health check for a child node.

**Health Check (Child Node)**
```http
GET /api/ai-engine/health
```
Detailed health status of a child node.

---

### 4️⃣ Vector Search APIs

**Search Vectors**
```http
POST /api/v1/vector/search
```
Semantic search using vector embeddings.

**Index Model**
```http
POST /api/v1/vector/index
```
Index a model for vector search.

**Get Vector Status**
```http
GET /api/v1/vector/status
```
Check indexing status for a model.

**Get Vector Analytics**
```http
GET /api/v1/vector/analytics
```
Get vector search analytics and statistics.

---

### 5️⃣ Model Registry APIs

**List AI Models**
```http
GET /api/v1/models
```
Get all available AI models.

**Get Model Details**
```http
GET /api/v1/models/{model_id}
```
Get details of a specific model.

**Get Recommended Model**
```http
POST /api/v1/models/recommend
```
Get AI-recommended model for a task.

---

### 6️⃣ Streaming APIs

**Stream Chat (SSE)**
```http
POST /api/v1/chat/stream
```
Stream chat responses in real-time using Server-Sent Events.

---

### 7️⃣ Health & Status

**System Health**
```http
GET /api/v1/health
```
Overall system health check.

**Engine Status**
```http
GET /api/v1/engines/status
```
Status of all AI engines.

---

## 🔐 Authentication

### API Token Authentication

Most endpoints require Bearer token authentication:

```http
Authorization: Bearer {api_token}
```

**Get your token:**
```bash
php artisan tinker
>>> $user = User::first();
>>> $token = $user->createToken('postman')->plainTextToken;
```

### Node JWT Authentication

For node-to-node communication:

```http
Authorization: Bearer {node_jwt_token}
```

**Generate JWT token:**
```bash
php artisan tinker
>>> $node = \LaravelAIEngine\Models\AINode::first();
>>> $auth = app(\LaravelAIEngine\Services\Node\NodeAuthService::class);
>>> $token = $auth->generateToken($node, 3600);
```

---

## 📝 Example Requests

### Example 1: Simple Chat

```json
POST /ai-demo/chat/send
{
    "message": "Hello! How are you?",
    "session_id": "user-123-session",
    "memory": true,
    "actions": false
}
```

### Example 2: RAG Chat with Collections

```json
POST /api/v1/rag/chat
{
    "message": "What posts do you have about Laravel?",
    "session_id": "rag-session-123",
    "rag_collections": ["App\\Models\\Post"],
    "use_intelligent_rag": true
}
```

### Example 3: Federated Search

```json
POST /api/ai-engine/search
{
    "query": "Laravel routing",
    "limit": 5,
    "options": {
        "collections": ["App\\Models\\Post"],
        "threshold": 0.3
    }
}
```

### Example 4: Vector Search

```json
POST /api/v1/vector/search
{
    "query": "Laravel Eloquent ORM",
    "model_class": "App\\Models\\Post",
    "limit": 5,
    "threshold": 0.3
}
```

---

## 🧪 Testing Workflow

### 1. Test Basic Chat
1. **Get Available Engines** - Check what's available
2. **Send Chat Message** - Test basic conversation
3. **Get Chat History** - Verify history is saved
4. **Clear Chat History** - Clean up

### 2. Test RAG System
1. **Get RAG Collections** - See available collections
2. **RAG Health Check** - Verify system is ready
3. **RAG Chat** - Send message with context retrieval
4. **Get RAG Chat History** - Check conversation

### 3. Test Federated Search
1. **Ping Child Node** - Verify node is healthy
2. **Get Collections** - See what's available on child
3. **Search** - Test federated search
4. **Health Check** - Verify node status

### 4. Test Vector Search
1. **Get Vector Status** - Check indexing status
2. **Index Model** - Index if needed
3. **Search Vectors** - Test semantic search
4. **Get Vector Analytics** - View statistics

---

## 🔧 Troubleshooting

### Issue: 401 Unauthorized

**Solution:** Update `api_token` in environment variables

```bash
php artisan tinker
>>> User::first()->createToken('postman')->plainTextToken
```

### Issue: 404 Not Found

**Solution:** Verify routes are published

```bash
php artisan route:list | grep ai-engine
```

### Issue: Node JWT Invalid

**Solution:** Ensure both nodes use same `AI_ENGINE_JWT_SECRET`

```bash
# .env on both master and child
AI_ENGINE_JWT_SECRET=your-shared-secret
```

### Issue: No Collections Found

**Solution:** Run collection discovery

```bash
php artisan ai-engine:discover-collections
```

### Issue: Vector Search Returns 0 Results

**Solution:** Index the model first

```bash
php artisan ai-engine:vector-index "App\Models\Post"
```

---

## 📊 Response Examples

### Chat Response
```json
{
    "response": "Hello! I'm doing great, thank you for asking!",
    "session_id": "user-123-session",
    "metadata": {
        "model": "gpt-4",
        "tokens": 25,
        "finish_reason": "stop"
    }
}
```

### RAG Response with Sources
```json
{
    "response": "Based on the available posts, here are Laravel tutorials:\n1. Getting Started with Laravel [Source 0]\n2. Laravel Routing Guide [Source 1]",
    "sources": [
        {
            "id": 1,
            "class": "App\\Models\\Post",
            "title": "Getting Started with Laravel",
            "score": 0.85
        }
    ],
    "metadata": {
        "context_items": 2,
        "search_time_ms": 45
    }
}
```

### Federated Search Response
```json
{
    "results": [
        {
            "id": 1,
            "content": "Laravel routing is...",
            "score": 0.75,
            "model_class": "App\\Models\\Post",
            "source_node": "child-node-1"
        }
    ],
    "count": 5,
    "duration_ms": 123
}
```

---

## 🎯 Best Practices

1. **Use Environment Variables** - Don't hardcode URLs or tokens
2. **Test Incrementally** - Start with health checks, then basic features
3. **Monitor Performance** - Check `duration_ms` in responses
4. **Handle Errors** - Check status codes and error messages
5. **Clear Sessions** - Clean up test sessions regularly
6. **Use Meaningful Session IDs** - Include user/context info

---

## 📚 Additional Resources

- **[Main Documentation](../README.md)** - Complete package guide
- **[Federated RAG Guide](../docs/FEDERATED-RAG-SUCCESS.md)** - Distributed setup
- **[API Reference](../docs/)** - Detailed API documentation

---

## 🤝 Contributing

Found an issue or want to add more examples? Please submit a PR!

---

## 📄 License

MIT License - Same as Laravel AI Engine package

---

**Built with ❤️ for Laravel AI Engine**

Last Updated: December 2, 2025
