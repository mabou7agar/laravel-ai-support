# 🦙 Ollama Integration Guide

## Overview

Laravel AI Engine now supports **Ollama**, allowing you to run powerful AI models locally on your own hardware. This means:

- ✅ **Zero API costs** - All models run locally
- ✅ **Complete privacy** - Your data never leaves your server
- ✅ **No rate limits** - Use as much as you want
- ✅ **Offline capable** - Works without internet connection
- ✅ **Full control** - Choose any model from Ollama's library

---

## 📋 Prerequisites

### 1. Install Ollama

**macOS:**
```bash
brew install ollama
```

**Linux:**
```bash
curl -fsSL https://ollama.com/install.sh | sh
```

**Windows:**
Download from [https://ollama.com/download](https://ollama.com/download)

### 2. Start Ollama Service

```bash
ollama serve
```

The service will run on `http://localhost:11434` by default.

### 3. Pull Your First Model

```bash
# Pull Llama 2 (7B parameters - good for most tasks)
ollama pull llama2

# Or pull Llama 3 (more powerful)
ollama pull llama3

# Or pull a code-specific model
ollama pull codellama

# Or pull a smaller, faster model
ollama pull phi
```

---

## 🚀 Quick Start

### 1. Configure Environment

Add to your `.env` file:

```env
# Ollama Configuration
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_DEFAULT_MODEL=llama2
OLLAMA_TIMEOUT=120
```

### 2. Use in Your Code

```php
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

// Create AI service
$aiService = app(AIEngineService::class);

// Create request
$request = new AIRequest(
    prompt: 'Explain Laravel in simple terms',
    engine: new EngineEnum(EngineEnum::OLLAMA),
    model: new EntityEnum(EntityEnum::OLLAMA_LLAMA2)
);

// Generate response
$response = $aiService->generate($request);

echo $response->getContent();
```

### 3. Use in RAG Chat

```php
// In your controller
public function chat(Request $request)
{
    $response = $this->chatService->sendMessage(
        message: $request->input('message'),
        sessionId: $request->input('session_id'),
        engine: 'ollama',  // Use Ollama
        model: 'llama2',   // Use Llama 2
        enableRAG: true
    );

    return response()->json($response);
}
```

---

## 📚 Available Models

### Llama Models (Meta)

| Model | Size | Best For | Pull Command |
|-------|------|----------|--------------|
| `llama2` | 7B | General chat, Q&A | `ollama pull llama2` |
| `llama2:13b` | 13B | Better reasoning | `ollama pull llama2:13b` |
| `llama2:70b` | 70B | Complex tasks | `ollama pull llama2:70b` |
| `llama3` | 8B | Latest, most capable | `ollama pull llama3` |
| `llama3:70b` | 70B | Production-grade | `ollama pull llama3:70b` |

### Mistral Models

| Model | Size | Best For | Pull Command |
|-------|------|----------|--------------|
| `mistral` | 7B | Fast, efficient | `ollama pull mistral` |
| `mixtral` | 8x7B | Expert mixture | `ollama pull mixtral` |

### Code Models

| Model | Size | Best For | Pull Command |
|-------|------|----------|--------------|
| `codellama` | 7B | Code generation | `ollama pull codellama` |
| `codellama:13b` | 13B | Better code quality | `ollama pull codellama:13b` |
| `codellama:34b` | 34B | Production code | `ollama pull codellama:34b` |
| `deepseek-coder` | 6.7B | Fast coding | `ollama pull deepseek-coder` |
| `wizardcoder` | 15B | Advanced coding | `ollama pull wizardcoder` |

### Small & Fast Models

| Model | Size | Best For | Pull Command |
|-------|------|----------|--------------|
| `phi` | 2.7B | Quick responses | `ollama pull phi` |
| `gemma:2b` | 2B | Very fast | `ollama pull gemma:2b` |
| `gemma:7b` | 7B | Balanced | `ollama pull gemma:7b` |
| `orca-mini` | 3B | Lightweight | `ollama pull orca-mini` |

### Specialized Models

| Model | Best For | Pull Command |
|-------|----------|--------------|
| `neural-chat` | Conversations | `ollama pull neural-chat` |
| `starling-lm` | Helpful assistant | `ollama pull starling-lm` |
| `vicuna` | Chatbot | `ollama pull vicuna` |
| `nous-hermes` | Instruction following | `ollama pull nous-hermes` |
| `qwen` | Multilingual | `ollama pull qwen` |
| `solar` | Korean + English | `ollama pull solar` |

---

## 💡 Usage Examples

### Basic Chat

```php
$request = new AIRequest(
    prompt: 'What is Laravel?',
    engine: new EngineEnum(EngineEnum::OLLAMA),
    model: new EntityEnum(EntityEnum::OLLAMA_LLAMA3)
);

$response = $aiService->generate($request);
```

### With System Message

```php
$request = new AIRequest(
    prompt: 'Write a function to calculate fibonacci',
    engine: new EngineEnum(EngineEnum::OLLAMA),
    model: new EntityEnum(EntityEnum::OLLAMA_CODELLAMA),
    systemMessage: 'You are an expert PHP developer'
);

$response = $aiService->generate($request);
```

### With Temperature Control

```php
$request = new AIRequest(
    prompt: 'Generate creative story ideas',
    engine: new EngineEnum(EngineEnum::OLLAMA),
    model: new EntityEnum(EntityEnum::OLLAMA_LLAMA2),
    temperature: 0.9  // Higher = more creative
);

$response = $aiService->generate($request);
```

### Streaming Responses

```php
$request = new AIRequest(
    prompt: 'Explain quantum computing',
    engine: new EngineEnum(EngineEnum::OLLAMA),
    model: new EntityEnum(EntityEnum::OLLAMA_LLAMA3)
);

foreach ($aiService->stream($request) as $chunk) {
    echo $chunk;  // Stream each word as it's generated
    flush();
}
```

### Generate Embeddings

```php
$request = new AIRequest(
    prompt: 'Laravel is a PHP framework',
    engine: new EngineEnum(EngineEnum::OLLAMA),
    model: new EntityEnum(EntityEnum::OLLAMA_LLAMA2)
);

$response = $aiService->generateEmbeddings($request);
$embeddings = $response->getData()['embeddings'];
```

---

## 🔧 Advanced Configuration

### Custom Ollama URL

If Ollama is running on a different server:

```env
OLLAMA_BASE_URL=http://192.168.1.100:11434
```

### Increase Timeout

For larger models or slower hardware:

```env
OLLAMA_TIMEOUT=300  # 5 minutes
```

### Use Specific Model Version

```php
$request = new AIRequest(
    prompt: 'Hello',
    engine: new EngineEnum(EngineEnum::OLLAMA),
    model: new EntityEnum(EntityEnum::OLLAMA_LLAMA3_8B)  // Specific version
);
```

---

## 🎯 Model Selection Guide

### For Chat Applications
- **Best:** `llama3` or `llama3:70b`
- **Fast:** `phi` or `gemma:2b`
- **Balanced:** `mistral`

### For Code Generation
- **Best:** `codellama:34b`
- **Fast:** `deepseek-coder`
- **Balanced:** `codellama:13b`

### For RAG/Document Q&A
- **Best:** `llama3:70b`
- **Fast:** `llama2`
- **Balanced:** `llama3`

### For Embeddings
- **Best:** `llama2` or `llama3`
- **Fast:** `phi`

---

## 🚀 Performance Tips

### 1. Choose Right Model Size

```
2B-3B models:  Fast, good for simple tasks
7B-8B models:  Balanced, good for most tasks
13B-15B models: Better quality, slower
34B-70B models: Best quality, needs powerful hardware
```

### 2. Hardware Requirements

| Model Size | RAM Required | GPU Recommended |
|------------|--------------|-----------------|
| 2B-3B | 4GB | Optional |
| 7B-8B | 8GB | Recommended |
| 13B-15B | 16GB | Highly recommended |
| 34B-70B | 32GB+ | Required |

### 3. Speed Optimization

```bash
# Use quantized models for faster inference
ollama pull llama2:7b-q4_0  # 4-bit quantization

# Use GPU acceleration (automatic if available)
# Ollama will use CUDA/Metal/ROCm automatically
```

### 4. Concurrent Requests

Ollama handles one request at a time by default. For concurrent requests:

```bash
# Run multiple Ollama instances on different ports
OLLAMA_HOST=0.0.0.0:11435 ollama serve &
OLLAMA_HOST=0.0.0.0:11436 ollama serve &
```

---

## 🔍 Troubleshooting

### Ollama Not Running

```bash
# Check if Ollama is running
curl http://localhost:11434/api/tags

# Start Ollama
ollama serve
```

### Model Not Found

```bash
# List installed models
ollama list

# Pull missing model
ollama pull llama2
```

### Slow Response Times

1. Use smaller model (e.g., `phi` instead of `llama3:70b`)
2. Use quantized models (e.g., `llama2:7b-q4_0`)
3. Ensure GPU is being used (check with `nvidia-smi` or `Activity Monitor`)
4. Increase timeout in `.env`

### Out of Memory

1. Use smaller model
2. Close other applications
3. Use quantized models
4. Increase system swap space

### Connection Refused

```bash
# Check Ollama is listening on correct port
lsof -i :11434

# Restart Ollama
pkill ollama
ollama serve
```

---

## 📊 Comparison: Ollama vs Cloud APIs

| Feature | Ollama (Local) | OpenAI/Anthropic |
|---------|----------------|------------------|
| **Cost** | Free | Pay per token |
| **Privacy** | 100% private | Data sent to cloud |
| **Speed** | Depends on hardware | Usually faster |
| **Quality** | Good (depends on model) | Excellent |
| **Rate Limits** | None | Yes |
| **Internet Required** | No | Yes |
| **Setup** | Requires installation | Just API key |

---

## 🎓 Best Practices

### 1. Start Small

```php
// Start with a small model for testing
$model = EntityEnum::OLLAMA_PHI;

// Then upgrade to larger model if needed
$model = EntityEnum::OLLAMA_LLAMA3;
```

### 2. Cache Responses

```php
// Enable caching in config
'cache' => [
    'enabled' => true,
    'ttl' => 3600,
],
```

### 3. Use Appropriate Model

```php
// For simple tasks
$model = EntityEnum::OLLAMA_PHI;

// For complex reasoning
$model = EntityEnum::OLLAMA_LLAMA3_70B;

// For code
$model = EntityEnum::OLLAMA_CODELLAMA;
```

### 4. Monitor Resource Usage

```bash
# Monitor GPU usage
nvidia-smi -l 1

# Monitor CPU/RAM
htop
```

---

## 🔗 Useful Links

- **Ollama Website:** https://ollama.com
- **Model Library:** https://ollama.com/library
- **GitHub:** https://github.com/ollama/ollama
- **Discord Community:** https://discord.gg/ollama

---

## 📝 Example: Complete RAG Chat with Ollama

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class OllamaChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
        protected AIEngineService $aiService
    ) {}

    /**
     * Send message using Ollama
     */
    public function sendMessage(Request $request)
    {
        $response = $this->chatService->sendMessage(
            message: $request->input('message'),
            sessionId: $request->input('session_id'),
            engine: 'ollama',
            model: 'llama3',
            enableRAG: true,
            ragCollections: ['App\\Models\\Post', 'App\\Models\\Product']
        );

        return response()->json($response);
    }

    /**
     * Stream response using Ollama
     */
    public function streamMessage(Request $request)
    {
        $aiRequest = new AIRequest(
            prompt: $request->input('message'),
            engine: new EngineEnum(EngineEnum::OLLAMA),
            model: new EntityEnum(EntityEnum::OLLAMA_LLAMA3)
        );

        return response()->stream(function () use ($aiRequest) {
            foreach ($this->aiService->stream($aiRequest) as $chunk) {
                echo "data: " . json_encode(['content' => $chunk]) . "\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Get available Ollama models
     */
    public function getModels()
    {
        $driver = $this->aiService->getDriver(
            new EngineEnum(EngineEnum::OLLAMA)
        );

        $models = $driver->getAvailableModels();

        return response()->json([
            'models' => $models
        ]);
    }

    /**
     * Pull a new model
     */
    public function pullModel(Request $request)
    {
        $driver = $this->aiService->getDriver(
            new EngineEnum(EngineEnum::OLLAMA)
        );

        $success = $driver->pullModel($request->input('model'));

        return response()->json([
            'success' => $success,
            'message' => $success 
                ? 'Model pulled successfully' 
                : 'Failed to pull model'
        ]);
    }
}
```

---

**🎉 You're now ready to use Ollama with Laravel AI Engine!**

**Benefits:**
- ✅ Zero API costs
- ✅ Complete privacy
- ✅ No rate limits
- ✅ Works offline
- ✅ Full control

**Get started:** `ollama pull llama3 && php artisan serve`
