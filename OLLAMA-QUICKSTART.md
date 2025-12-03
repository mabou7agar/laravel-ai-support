# 🚀 Ollama Quick Start - 5 Minutes Setup

Get started with local AI models in your Laravel application in just 5 minutes!

---

## Step 1: Install Ollama (2 minutes)

### macOS
```bash
brew install ollama
```

### Linux
```bash
curl -fsSL https://ollama.com/install.sh | sh
```

### Windows
Download from [ollama.com/download](https://ollama.com/download)

---

## Step 2: Start Ollama & Pull a Model (2 minutes)

```bash
# Start Ollama service
ollama serve

# In a new terminal, pull Llama 2 (7B model - ~4GB download)
ollama pull llama2

# Or pull Llama 3 (more powerful)
ollama pull llama3

# Or pull a smaller, faster model
ollama pull phi
```

**Wait for download to complete...**

---

## Step 3: Configure Laravel (1 minute)

Add to your `.env` file:

```env
# Ollama Configuration
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_DEFAULT_MODEL=llama2
OLLAMA_TIMEOUT=120
```

---

## Step 4: Test It! (30 seconds)

### Option A: Using RAG Chat API

```bash
curl -X POST http://localhost:8000/api/v1/rag/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-token" \
  -d '{
    "message": "What is Laravel?",
    "session_id": "test-123",
    "engine": "ollama",
    "model": "llama2"
  }'
```

### Option B: Using PHP Code

```php
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

$aiService = app(AIEngineService::class);

$request = new AIRequest(
    prompt: 'Explain Laravel in simple terms',
    engine: new EngineEnum(EngineEnum::OLLAMA),
    model: new EntityEnum(EntityEnum::OLLAMA_LLAMA2)
);

$response = $aiService->generate($request);
echo $response->getContent();
```

### Option C: Using Artisan Command

```bash
php artisan tinker

>>> $ai = app(\LaravelAIEngine\Services\AIEngineService::class);
>>> $request = new \LaravelAIEngine\DTOs\AIRequest(
...     prompt: 'Hello, how are you?',
...     engine: new \LaravelAIEngine\Enums\EngineEnum('ollama'),
...     model: new \LaravelAIEngine\Enums\EntityEnum('llama2')
... );
>>> $response = $ai->generate($request);
>>> echo $response->getContent();
```

---

## 🎉 That's It!

You now have:
- ✅ Local AI running on your machine
- ✅ Zero API costs
- ✅ Complete privacy
- ✅ No rate limits
- ✅ Offline capability

---

## 🔥 Next Steps

### 1. Try Different Models

```bash
# For coding tasks
ollama pull codellama

# For faster responses
ollama pull phi

# For better quality
ollama pull llama3
```

### 2. Use in Your Application

```php
// In your controller
public function chat(Request $request)
{
    $response = $this->chatService->sendMessage(
        message: $request->input('message'),
        sessionId: $request->input('session_id'),
        engine: 'ollama',
        model: 'llama2',
        enableRAG: true
    );

    return response()->json($response);
}
```

### 3. Enable Streaming

```php
foreach ($aiService->stream($request) as $chunk) {
    echo $chunk;
    flush();
}
```

---

## 📊 Model Recommendations

| Use Case | Recommended Model | Size | Speed |
|----------|------------------|------|-------|
| **Quick Testing** | `phi` | 2.7B | ⚡⚡⚡ |
| **General Chat** | `llama2` | 7B | ⚡⚡ |
| **Best Quality** | `llama3` | 8B | ⚡⚡ |
| **Code Generation** | `codellama` | 7B | ⚡⚡ |
| **Production** | `llama3:70b` | 70B | ⚡ |

---

## 🐛 Troubleshooting

### "Connection refused"
```bash
# Make sure Ollama is running
ollama serve
```

### "Model not found"
```bash
# Pull the model first
ollama pull llama2
```

### "Slow responses"
```bash
# Use a smaller model
ollama pull phi

# Or use quantized version
ollama pull llama2:7b-q4_0
```

---

## 💡 Pro Tips

1. **Start Small**: Begin with `phi` or `llama2` for testing
2. **Check Models**: Run `ollama list` to see installed models
3. **Monitor Resources**: Use `htop` or Activity Monitor
4. **Use GPU**: Ollama automatically uses GPU if available
5. **Cache Responses**: Enable caching in Laravel config

---

## 📚 Learn More

- **Full Documentation**: See `OLLAMA-INTEGRATION.md`
- **Model Library**: https://ollama.com/library
- **Ollama Docs**: https://github.com/ollama/ollama

---

**🎊 Enjoy your local AI models!**

**Benefits:**
- 💰 Free forever
- 🔒 100% private
- ⚡ No rate limits
- 🌐 Works offline
- 🎮 Full control
