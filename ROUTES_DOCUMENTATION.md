# Routes Documentation

## 🌐 Web Routes Configuration

The Laravel AI Engine package includes demo routes that are **conditionally loaded** based on your environment configuration.

## 🔒 Security & Availability

### Default Behavior

By default, demo routes are **ONLY available in local environment**:

```php
// config/ai-engine.php
'enable_demo_routes' => env('AI_ENGINE_ENABLE_DEMO_ROUTES', app()->environment('local')),
```

### Enable in Production

To enable demo routes in other environments, set the environment variable:

```env
# .env
AI_ENGINE_ENABLE_DEMO_ROUTES=true
```

⚠️ **Warning:** Only enable demo routes in production if you have proper authentication/authorization in place!

## 📍 Available Routes

### Demo Pages

All demo routes are prefixed with `/ai-demo` by default (configurable):

| Route | URL | Description |
|-------|-----|-------------|
| `ai-engine.chat.index` | `/ai-demo/chat` | Basic enhanced chat demo |
| `ai-engine.chat.rag` | `/ai-demo/chat/rag` | RAG-powered chat demo |
| `ai-engine.chat.voice` | `/ai-demo/chat/voice` | Voice-enabled chat demo |
| `ai-engine.chat.multimodal` | `/ai-demo/chat/multimodal` | Multi-modal chat demo |
| `ai-engine.vector-search` | `/ai-demo/vector-search` | Vector search demo |

### API Endpoints

All API routes are prefixed with `/ai-demo/api`:

| Method | Route | Description |
|--------|-------|-------------|
| POST | `/ai-demo/api/chat/send` | Send a message |
| GET | `/ai-demo/api/chat/history/{sessionId}` | Get chat history |
| DELETE | `/ai-demo/api/chat/history/{sessionId}` | Clear chat history |
| POST | `/ai-demo/api/chat/upload` | Upload a file |
| GET | `/ai-demo/api/chat/search/{sessionId}` | Search messages |
| GET | `/ai-demo/api/chat/export/{sessionId}` | Export chat history |
| POST | `/ai-demo/api/chat/action` | Execute an action |
| GET | `/ai-demo/api/chat/engines` | Get available engines |

## ⚙️ Configuration Options

### Route Prefix

Change the route prefix in your `.env`:

```env
AI_ENGINE_DEMO_PREFIX=my-ai-demo
```

Or in `config/ai-engine.php`:

```php
'demo_route_prefix' => env('AI_ENGINE_DEMO_PREFIX', 'ai-demo'),
```

### Middleware

Configure middleware for demo routes:

```php
// config/ai-engine.php
'demo_route_middleware' => ['web', 'auth'], // Add authentication
```

## 🚀 Usage Examples

### Basic Chat Demo

Visit: `http://your-app.test/ai-demo/chat`

Features:
- ✅ Real-time streaming
- ✅ Message history
- ✅ Voice input
- ✅ File upload
- ✅ Search & export
- ✅ Dark/Light theme

### RAG Chat Demo

Visit: `http://your-app.test/ai-demo/chat/rag`

Features:
- ✅ All basic chat features
- ✅ Vector search integration
- ✅ Source display
- ✅ Context-aware responses
- ✅ Relevance scoring

### API Usage

#### Send Message

```javascript
fetch('/ai-demo/api/chat/send', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({
        message: 'Hello AI!',
        session_id: 'my-session',
        engine: 'openai',
        model: 'gpt-4o-mini'
    })
})
.then(response => response.json())
.then(data => console.log(data));
```

#### Get Chat History

```javascript
fetch('/ai-demo/api/chat/history/my-session')
    .then(response => response.json())
    .then(data => console.log(data.messages));
```

#### Upload File

```javascript
const formData = new FormData();
formData.append('file', fileInput.files[0]);
formData.append('session_id', 'my-session');

fetch('/ai-demo/api/chat/upload', {
    method: 'POST',
    headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: formData
})
.then(response => response.json())
.then(data => console.log(data.file));
```

#### Export Chat

```javascript
// Export as JSON
window.location.href = '/ai-demo/api/chat/export/my-session?format=json';

// Export as text
window.location.href = '/ai-demo/api/chat/export/my-session?format=txt';
```

## 🔐 Adding Authentication

### Protect Demo Routes

```php
// config/ai-engine.php
'demo_route_middleware' => ['web', 'auth'],
```

### Custom Authorization

Create a middleware:

```php
php artisan make:middleware CheckAIDemoAccess
```

```php
// app/Http/Middleware/CheckAIDemoAccess.php
public function handle($request, Closure $next)
{
    if (!auth()->user()?->can('access-ai-demo')) {
        abort(403, 'Unauthorized access to AI demo');
    }
    
    return $next($request);
}
```

Register and use:

```php
// config/ai-engine.php
'demo_route_middleware' => ['web', 'auth', CheckAIDemoAccess::class],
```

## 🎨 Customizing Demo Views

### Publish Views

```bash
php artisan vendor:publish --tag=ai-engine-views
```

Views will be published to `resources/views/vendor/ai-engine/demo/`

### Customize Chat Demo

Edit `resources/views/vendor/ai-engine/demo/chat.blade.php`:

```blade
<x-ai-chat-enhanced
    sessionId="custom-chat"
    engine="anthropic"
    model="claude-3-5-sonnet"
    theme="dark"
    :enableRAG="true"
    ragModelClass="App\Models\YourModel"
/>
```

## 📊 Environment Variables

Complete list of environment variables:

```env
# Enable/disable demo routes
AI_ENGINE_ENABLE_DEMO_ROUTES=true

# Route prefix
AI_ENGINE_DEMO_PREFIX=ai-demo

# AI Engine Configuration
AI_ENGINE_DEFAULT=openai
OPENAI_API_KEY=sk-...

# Vector Search (for RAG demo)
VECTOR_DB_DRIVER=qdrant
QDRANT_HOST=http://localhost:6333
QDRANT_API_KEY=your-key
```

## 🧪 Testing Routes

### Check if Routes are Loaded

```bash
php artisan route:list --name=ai-engine
```

Expected output:
```
GET|HEAD  ai-demo/chat ................ ai-engine.chat.index
GET|HEAD  ai-demo/chat/rag ............ ai-engine.chat.rag
GET|HEAD  ai-demo/chat/voice .......... ai-engine.chat.voice
POST      ai-demo/api/chat/send ....... ai-engine.api.chat.send
...
```

### Test API Endpoints

```bash
# Test send message
curl -X POST http://your-app.test/ai-demo/api/chat/send \
  -H "Content-Type: application/json" \
  -H "X-CSRF-TOKEN: your-token" \
  -d '{"message":"Hello","session_id":"test"}'

# Test get history
curl http://your-app.test/ai-demo/api/chat/history/test

# Test get engines
curl http://your-app.test/ai-demo/api/chat/engines
```

## 🚫 Disabling Routes

### Completely Disable Demo Routes

```env
AI_ENGINE_ENABLE_DEMO_ROUTES=false
```

Or in config:

```php
// config/ai-engine.php
'enable_demo_routes' => false,
```

### Disable Specific Routes

Modify `routes/web.php` in the package or override in your application.

## 📝 Best Practices

### 1. **Production Security**

```php
// Only enable in specific environments
'enable_demo_routes' => env('AI_ENGINE_ENABLE_DEMO_ROUTES', 
    app()->environment(['local', 'staging'])
),
```

### 2. **Rate Limiting**

```php
'demo_route_middleware' => ['web', 'auth', 'throttle:60,1'],
```

### 3. **IP Whitelisting**

```php
// Create middleware to whitelist IPs
'demo_route_middleware' => ['web', WhitelistIPs::class],
```

### 4. **Logging**

```php
// Log demo route access
'demo_route_middleware' => ['web', LogDemoAccess::class],
```

## 🎯 Summary

**Routes are:**
- ✅ Conditionally loaded based on environment
- ✅ Configurable via environment variables
- ✅ Protected by middleware
- ✅ Customizable prefix and settings
- ✅ Disabled by default in production

**Default Access:**
- ✅ Local environment: **ENABLED**
- ❌ Production environment: **DISABLED**
- ⚙️ Other environments: **CONFIGURABLE**

**To enable in production:**
```env
AI_ENGINE_ENABLE_DEMO_ROUTES=true
```

⚠️ **Remember to add proper authentication and authorization!**
