<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\OpenRouter;

use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Http;

class OpenRouterEngineDriver extends BaseEngineDriver
{
    protected string $baseUrl = 'https://openrouter.ai/api/v1';
    
    /**
     * Get the API key for OpenRouter
     */
    protected function getApiKey(): string
    {
        return config('ai-engine.engines.openrouter.api_key') 
            ?? env('OPENROUTER_API_KEY') 
            ?? throw new \InvalidArgumentException('OpenRouter API key not configured');
    }

    /**
     * Get the headers for OpenRouter API requests
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('ai-engine.engines.openrouter.site_url', config('app.url')),
            'X-Title' => config('ai-engine.engines.openrouter.site_name', config('app.name')),
        ];
    }

    /**
     * Generate content using OpenRouter
     */
    public function generate(AIRequest $request): AIResponse
    {
        return $this->generateText($request);
    }

    /**
     * Generate text content via OpenRouter API
     */
    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $this->logApiRequest('generateText', $request);
            
            $messages = $this->buildMessages($request);
            $payload = [
                'model' => $request->model->value,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens ?? 4096,
                'temperature' => $request->temperature ?? 0.7,
            ];
            
            // Add OpenRouter specific parameters
            if ($transforms = config('ai-engine.engines.openrouter.transforms')) {
                $payload['transforms'] = $transforms;
            }
            
            if ($route = config('ai-engine.engines.openrouter.route')) {
                $payload['route'] = $route;
            }
            
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(config('ai-engine.engines.openrouter.timeout', 60))
                ->post($this->baseUrl . '/chat/completions', $payload);
            
            if (!$response->successful()) {
                $error = $response->json()['error']['message'] ?? $response->body();
                return AIResponse::error($error, $request->engine, $request->model);
            }
            
            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            
            return AIResponse::success(
                $content,
                $request->engine,
                $request->model,
                [
                    'model' => $data['model'] ?? $request->model->value,
                    'usage' => $data['usage'] ?? [],
                    'openrouter_id' => $data['id'] ?? null,
                    'provider' => $data['provider'] ?? null,
                ]
            );

        } catch (\Exception $e) {
            return AIResponse::error($e->getMessage(), $request->engine, $request->model);
        }
    }

    /**
     * Build messages array for chat completion
     */
    protected function buildMessages(AIRequest $request): array
    {
        $messages = [];
        
        if ($request->systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }
        
        // Add conversation history if present
        if (!empty($request->conversationHistory)) {
            foreach ($request->conversationHistory as $msg) {
                $messages[] = [
                    'role' => $msg['role'] ?? 'user',
                    'content' => $msg['content'] ?? '',
                ];
            }
        }
        
        // Add the current prompt
        $messages[] = ['role' => 'user', 'content' => $request->prompt];
        
        return $messages;
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        // For now, fall back to non-streaming
        $response = $this->generateText($request);
        yield $response->content;
    }

    /**
     * Validate the request
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($request->getPrompt())) {
            throw new \InvalidArgumentException('Prompt is required');
        }
        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::OPENROUTER);
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, ['text', 'chat', 'streaming']);
    }

    /**
     * Test the engine connection
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(10)
                ->get($this->baseUrl . '/models');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the engine name for logging
     */
    public function getEngineName(): string
    {
        return 'openrouter';
    }

    /**
     * Check if the model is supported by OpenRouter
     */
    public function supportsModel(string $model): bool
    {
        return str_contains($model, '/');
    }

    /**
     * Get available models from OpenRouter
     */
    public function getAvailableModels(): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(10)
                ->get($this->baseUrl . '/models');
            
            if ($response->successful()) {
                $data = $response->json();
                return collect($data['data'] ?? [])
                    ->pluck('id')
                    ->toArray();
            }
        } catch (\Exception $e) {
            // Fallback to configured models
        }
        
        return array_keys(config('ai-engine.engines.openrouter.models', []));
    }
    
    /**
     * Get supported capabilities
     */
    public function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'streaming'];
    }
    
    /**
     * Get the engine enum
     */
    public function getEngineEnum(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::OPENROUTER);
    }
    
    /**
     * Get the default model
     */
    public function getDefaultModel(): EntityEnum
    {
        $model = config('ai-engine.engines.openrouter.default_model', 'meta-llama/llama-3.1-8b-instruct:free');
        return EntityEnum::from($model);
    }
    
    /**
     * Get the base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
    
    /**
     * Validate the configuration
     */
    public function validateConfig(): void
    {
        if (empty($this->getApiKey())) {
            throw new \InvalidArgumentException('OpenRouter API key is required');
        }
    }
}
