# RAG Support in Enhanced Chat UI

## ✅ YES - RAG is Now Fully Supported!

The enhanced chat UI now includes **complete RAG (Retrieval Augmented Generation) support** with visual source display and context management.

## 🎉 Features Added

### 1. **RAG Configuration**
- Enable/disable RAG per chat instance
- Configure model class for vector search
- Set maximum context items
- Set minimum relevance score
- Toggle source display

### 2. **Visual Source Display**
- Collapsible sources section
- Source relevance scores with visual bars
- Source metadata display
- Preview of source content
- Numbered sources for easy reference

### 3. **Automatic Context Injection**
- RAG context automatically added to messages
- Vector search performed before AI response
- Relevant sources retrieved and displayed
- Context seamlessly integrated into conversation

## 📦 Usage

### Basic RAG Chat

```blade
<x-ai-chat-enhanced
    sessionId="rag-chat"
    engine="openai"
    model="gpt-4o"
    :enableRAG="true"
    ragModelClass="App\Models\Post"
    :ragMaxContext="5"
    :ragMinScore="0.5"
    :showRAGSources="true"
/>
```

### Advanced RAG Configuration

```blade
<x-ai-chat-enhanced
    sessionId="advanced-rag"
    engine="anthropic"
    model="claude-3-5-sonnet"
    theme="dark"
    :streaming="true"
    :memory="true"
    :enableRAG="true"
    ragModelClass="App\Models\Documentation"
    :ragMaxContext="10"
    :ragMinScore="0.7"
    :showRAGSources="true"
    :suggestions="[
        'How do I use Laravel queues?',
        'Explain middleware',
        'What is Eloquent ORM?'
    ]"
/>
```

### Multiple Model RAG

```blade
<x-ai-chat-enhanced
    sessionId="multi-model-rag"
    :enableRAG="true"
    ragModelClass="App\Models\Post,App\Models\Documentation"
    :ragMaxContext="8"
    :ragMinScore="0.6"
/>
```

## 🎨 Visual Features

### Source Display

When RAG is enabled, AI responses automatically show:

1. **Sources Header**
   - 📚 Icon indicator
   - Source count badge
   - Collapsible toggle button

2. **Source Items**
   - Relevance score bar (visual gradient)
   - Source number (#1, #2, etc.)
   - Source title
   - Relevance percentage
   - Content preview (150 chars)
   - Metadata (ID, type, date)

### Example Display

```
┌─────────────────────────────────────┐
│ 📚 Sources (3) ▼                    │
├─────────────────────────────────────┤
│ ████████████████░░░░ 85%            │
│ #1 Laravel Queues Guide             │
│ Laravel queues provide a unified... │
│ ID: 123 • Type: Post • Date: 11/27  │
├─────────────────────────────────────┤
│ ████████████░░░░░░░░ 72%            │
│ #2 Queue Configuration              │
│ To configure queues in Laravel...   │
│ ID: 456 • Type: Docs • Date: 11/20  │
└─────────────────────────────────────┘
```

## ⚙️ Configuration Options

### Blade Component Props

```php
'enableRAG' => true|false,              // Enable RAG functionality
'ragModelClass' => 'App\Models\Post',   // Model class for vector search
'ragMaxContext' => 5,                   // Maximum context items to retrieve
'ragMinScore' => 0.5,                   // Minimum relevance score (0-1)
'showRAGSources' => true|false,         // Display sources to user
```

### JavaScript Options

```javascript
{
    enableRAG: true,
    ragModelClass: 'App\\Models\\Post',
    ragMaxContext: 5,
    ragMinScore: 0.5,
    showRAGSources: true
}
```

## 🔧 How It Works

### 1. Message Flow with RAG

```
User sends message
    ↓
Chat client adds RAG config to payload
    ↓
Server performs vector search
    ↓
Relevant sources retrieved
    ↓
Context injected into AI prompt
    ↓
AI generates response with context
    ↓
Response + sources sent to client
    ↓
UI displays response with collapsible sources
```

### 2. Payload Structure

```javascript
{
    type: 'send_message',
    session_id: 'chat-123',
    message: 'How do I use Laravel queues?',
    rag_enabled: true,
    rag_model_class: 'App\\Models\\Post',
    rag_max_context: 5,
    rag_min_score: 0.5
}
```

### 3. Response Structure

```javascript
{
    type: 'ai.response.complete',
    content: 'Based on the documentation...',
    sources: [
        {
            id: 123,
            type: 'Post',
            score: 0.85,
            content: 'Laravel queues provide...',
            metadata: {
                title: 'Laravel Queues Guide',
                id: 123,
                type: 'Post',
                created_at: '2024-11-27'
            }
        }
    ]
}
```

## 🎯 Events

### RAG-Specific Events

```javascript
// Listen for RAG context received
chatUI.client.on('ragContext', (data) => {
    console.log('RAG context:', data.sources);
});

// Listen for RAG sources
chatUI.client.on('ragSources', (data) => {
    console.log('Sources:', data.sources);
});

// Listen for RAG context received event
chatUI.on('ragContextReceived', (data) => {
    console.log('Context received:', data);
});
```

## 💡 Use Cases

### 1. **Documentation Chat**

```blade
<x-ai-chat-enhanced
    :enableRAG="true"
    ragModelClass="App\Models\Documentation"
    :ragMaxContext="5"
    placeholder="Ask about our documentation..."
/>
```

### 2. **Knowledge Base Support**

```blade
<x-ai-chat-enhanced
    :enableRAG="true"
    ragModelClass="App\Models\Article"
    :ragMaxContext="8"
    :ragMinScore="0.7"
    placeholder="Search our knowledge base..."
/>
```

### 3. **Product Information**

```blade
<x-ai-chat-enhanced
    :enableRAG="true"
    ragModelClass="App\Models\Product"
    :ragMaxContext="3"
    placeholder="Ask about our products..."
/>
```

### 4. **Code Assistant**

```blade
<x-ai-chat-enhanced
    :enableRAG="true"
    ragModelClass="App\Models\CodeSnippet"
    :ragMaxContext="10"
    :ragMinScore="0.6"
    placeholder="Ask coding questions..."
/>
```

## 🎨 Styling

### Custom Source Styling

```css
/* Customize source container */
.rag-sources-container {
    margin-top: 20px;
    border-top: 2px solid #667eea;
}

/* Customize source items */
.rag-source-item {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

/* Customize score bar */
.score-fill {
    background: #10b981;
}
```

## 📊 Performance

### Optimization Tips

1. **Set Appropriate Limits**
   ```blade
   :ragMaxContext="5"  <!-- Don't retrieve too many sources -->
   ```

2. **Use Relevance Threshold**
   ```blade
   :ragMinScore="0.7"  <!-- Only high-quality matches -->
   ```

3. **Cache Vector Embeddings**
   - Enable embedding cache in config
   - Reduces API calls to OpenAI

4. **Index Models Properly**
   ```bash
   php artisan ai-engine:vector-index "App\Models\Post" --batch=100
   ```

## 🔒 Security

### Authorization

RAG respects model authorization:

```php
// In your model
use LaravelAIEngine\Traits\HasVectorSearch;

class Post extends Model
{
    use HasVectorSearch;
    
    protected static function applyUserFilters(array $filters, ?object $user = null): array
    {
        if ($user) {
            $filters['user_id'] = $user->id;
        }
        return $filters;
    }
}
```

## 📈 Analytics

Track RAG usage:

```php
use LaravelAIEngine\Services\Vector\VectorAnalyticsService;

$analytics = app(VectorAnalyticsService::class);

// Get RAG search stats
$stats = $analytics->getGlobalAnalytics(days: 30);

// Performance metrics
$performance = $analytics->getPerformanceMetrics(days: 7);
```

## 🎉 Summary

**RAG Support Status:** ✅ **FULLY SUPPORTED**

**Features:**
- ✅ Automatic context retrieval
- ✅ Visual source display
- ✅ Collapsible UI
- ✅ Relevance scoring
- ✅ Metadata display
- ✅ Multiple model support
- ✅ Configurable thresholds
- ✅ Event system
- ✅ Authorization support
- ✅ Analytics tracking

**The enhanced chat UI is now a complete RAG-powered chat interface!** 🚀

## 🔗 Related Documentation

- [Vector Search Guide](docs/vector-search.md)
- [RAG Guide](docs/rag.md)
- [Chat Enhancements](CHAT_ENHANCEMENTS.md)
- [Configuration Guide](docs/configuration.md)
