# Laravel AI Engine Documentation

Welcome to the Laravel AI Engine documentation! This comprehensive guide will help you integrate powerful AI capabilities into your Laravel applications.

## 📚 Table of Contents

### Getting Started
- [Installation Guide](installation.md) - Get up and running quickly
- [Quick Start Guide](quickstart.md) - Learn the basics in 5 minutes
- [Configuration Guide](configuration.md) - Complete configuration reference

### Core Features
- [Vector Search](vector-search.md) - Semantic search with Qdrant/Pinecone
- [RAG (Retrieval Augmented Generation)](rag.md) - Context-aware AI responses
- [Conversations](conversations.md) - Build chat applications with memory
- [Interactive Actions](actions.md) - AI-suggested actions & numbered options

### Security & Access Control
- [Multi-Tenant Access Control](MULTI_TENANT_RAG_ACCESS_CONTROL.md) - User/Tenant/Admin isolation
- [Workspace Isolation](WORKSPACE_ISOLATION.md) - Workspace-scoped data access 🆕
- [Multi-Database Tenancy](MULTI_DATABASE_TENANCY.md) - Separate collections per tenant 🆕
- [Simplified Access Control](SIMPLIFIED_ACCESS_CONTROL.md) - Quick access control setup

### Federated RAG
- [Federated RAG Success](FEDERATED-RAG-SUCCESS.md) - Complete federated setup
- [Master Node Usage](MASTER_NODE_CLIENT_USAGE.md) - Master node configuration
- [User Context Injection](USER_CONTEXT_INJECTION.md) - Inject user context

### Advanced Features
- [Chunking Strategies](CHUNKING-STRATEGIES.md) - Smart content splitting
- [Large Media Processing](LARGE-MEDIA-PROCESSING.md) - Handle large files
- [URL & Media Embeddings](URL-MEDIA-EMBEDDINGS.md) - Embed URLs and media
- [Media Auto-Detection](MEDIA-AUTO-DETECTION.md) - Automatic media handling
- [Service-Based Architecture](SERVICE-BASED-ARCHITECTURE.md) - Architecture overview
- [Performance Optimization](PERFORMANCE.md) - Speed and cost optimization 🆕

### Guides
- [Multi-Modal AI](multimodal.md) - Images, audio, video, documents
- [Troubleshooting RAG](TROUBLESHOOTING_NO_RAG_RESULTS.md) - Fix common issues
- [GPT-5 Support](PERFORMANCE.md#gpt-5-family-considerations) - GPT-5 model usage 🆕

### Reference
- [Artisan Commands](../README.md#-artisan-commands) - CLI tools reference

## 🚀 Quick Links

### Common Tasks

**Chat & Conversations**
```php
// Simple chat
$response = AIEngine::chat('What is Laravel?');

// Create conversation
$conversation = app(ConversationManager::class)->createConversation(
    userId: auth()->id(),
    title: 'Help Chat'
);
```

**Vector Search**
```bash
# Index models
php artisan ai-engine:vector-index "App\Models\Post"

# Force recreate with fresh schema
php artisan ai-engine:vector-index --force

# Search
$results = Post::vectorSearch('Laravel tutorials');
```

**RAG (Context-Aware Chat)**
```php
$response = Post::ragChat('What are Laravel best practices?');
```

## 📖 Documentation Structure

### For Beginners
1. Start with [Installation](installation.md)
2. Follow [Quick Start](quickstart.md)
3. Explore [Configuration](configuration.md)

### For Developers
1. Learn [Vector Search](vector-search.md)
2. Implement [RAG](rag.md)
3. Build [Conversations](conversations.md)

### For Production
1. Review [Security](security.md)
2. Optimize [Performance](performance.md)
3. Monitor with [Analytics](analytics.md)

## 🎯 Use Cases

### Customer Support
- Build AI-powered chatbots
- Provide instant answers from documentation
- Track conversation history

### Content Management
- Semantic search across content
- AI-powered content recommendations
- Automatic tagging and categorization

### Knowledge Base
- RAG-powered Q&A systems
- Intelligent documentation search
- Context-aware help systems

### Development Tools
- Code assistant chatbots
- Documentation search
- API helper tools

## 🔧 Features Overview

### AI Engines
- ✅ OpenAI (GPT-4o, GPT-4o-mini, DALL-E, Whisper)
- ✅ Anthropic (Claude 3.5 Sonnet, Claude 3 Opus)
- ✅ Google (Gemini 1.5 Pro, Gemini 1.5 Flash)
- ✅ OpenRouter (100+ models)

### Vector Databases
- ✅ Qdrant
- ✅ Pinecone

### Storage Drivers
- ✅ Redis
- ✅ Database
- ✅ File
- ✅ MongoDB

### Media Processing
- ✅ Images (GPT-4 Vision, DALL-E)
- ✅ Audio (Whisper, TTS)
- ✅ Video (FFmpeg integration)
- ✅ Documents (PDF, DOCX, TXT)

## 💡 Examples

### Basic Chat
```php
use LaravelAIEngine\Facades\AIEngine;

$response = AIEngine::chat('Explain Laravel routing');
echo $response;
```

### Streaming Chat
```php
AIEngine::streamChat('Write a story', function ($chunk) {
    echo $chunk;
    flush();
});
```

### Vector Search
```php
use LaravelAIEngine\Traits\HasVectorSearch;

class Post extends Model
{
    use HasVectorSearch, Vectorizable;
}

$results = Post::vectorSearch('Laravel best practices');
```

### RAG Chat
```php
$response = Post::ragChat('How do I optimize queries?');
echo $response['answer'];
```

### Image Generation
```php
$images = AIEngine::generateImages('A futuristic city', count: 2);
```

### Audio Transcription
```php
$audio = app(AudioService::class);
$transcription = $audio->transcribe('recording.mp3');
```

## 🎓 Learning Path

### Week 1: Basics
- [ ] Install and configure
- [ ] Try basic chat
- [ ] Create conversations
- [ ] Explore streaming

### Week 2: Vector Search
- [ ] Set up Qdrant/Pinecone
- [ ] Index your models
- [ ] Implement search
- [ ] Try RAG chat

### Week 3: Advanced
- [ ] Multi-modal AI
- [ ] Credit management
- [ ] Analytics setup
- [ ] Performance optimization

### Week 4: Production
- [ ] Security hardening
- [ ] Monitoring setup
- [ ] Cost optimization
- [ ] Scaling strategies

## 🆘 Getting Help

### Documentation
- Read the relevant guide
- Check the API reference
- Review examples

### Community
- [GitHub Issues](https://github.com/m-tech-stack/laravel-ai-engine/issues)
- [GitHub Discussions](https://github.com/m-tech-stack/laravel-ai-engine/discussions)

### Troubleshooting
- Check [Common Issues](troubleshooting.md)
- Review error logs
- Enable debug mode

## 🚀 Next Steps

Ready to get started? Choose your path:

**New to the package?**
→ [Installation Guide](installation.md)

**Want to try it quickly?**
→ [Quick Start Guide](quickstart.md)

**Building a search feature?**
→ [Vector Search Guide](vector-search.md)

**Creating a chatbot?**
→ [Conversations Guide](conversations.md)

**Need context-aware AI?**
→ [RAG Guide](rag.md)

---

**Happy coding! 🎉**
