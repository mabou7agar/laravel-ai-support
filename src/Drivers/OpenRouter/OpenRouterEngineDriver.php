<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\OpenRouter;

use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use Illuminate\Http\Client\Response;

class OpenRouterEngineDriver extends OpenAIEngineDriver
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
        $headers = parent::getHeaders();
        
        // OpenRouter specific headers
        $headers['HTTP-Referer'] = config('ai-engine.engines.openrouter.site_url', config('app.url'));
        $headers['X-Title'] = config('ai-engine.engines.openrouter.site_name', config('app.name'));
        
        return $headers;
    }

    /**
     * Prepare the request payload for OpenRouter
     */
    protected function preparePayload(AIRequest $request): array
    {
        $payload = parent::preparePayload($request);
        
        // OpenRouter uses the full model name (e.g., "openai/gpt-4o")
        $payload['model'] = $request->model->value;
        
        // Add OpenRouter specific parameters if configured
        if ($transforms = config('ai-engine.engines.openrouter.transforms')) {
            $payload['transforms'] = $transforms;
        }
        
        if ($route = config('ai-engine.engines.openrouter.route')) {
            $payload['route'] = $route;
        }
        
        return $payload;
    }

    /**
     * Handle OpenRouter specific response processing
     */
    protected function processResponse(Response $response, AIRequest $request): AIResponse
    {
        $data = $response->json();
        
        // OpenRouter includes additional metadata in responses
        $metadata = [
            'model' => $data['model'] ?? $request->model->value,
            'usage' => $data['usage'] ?? [],
        ];
        
        // Add OpenRouter specific metadata if present
        if (isset($data['id'])) {
            $metadata['openrouter_id'] = $data['id'];
        }
        
        if (isset($data['provider'])) {
            $metadata['provider'] = $data['provider'];
        }
        
        // Process the response using parent logic but with OpenRouter metadata
        $aiResponse = parent::processResponse($response, $request);
        
        return new AIResponse(
            success: $aiResponse->success,
            content: $aiResponse->content,
            metadata: array_merge($aiResponse->metadata, $metadata),
            usage: $aiResponse->usage,
            cost: $aiResponse->cost
        );
    }

    /**
     * Get the engine name for logging and analytics
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
        // OpenRouter supports many models - we'll trust the model name format
        return str_contains($model, '/') || parent::supportsModel($model);
    }

    /**
     * Get available models from OpenRouter (optional implementation)
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->httpClient->get($this->baseUrl . '/models', [
                'headers' => $this->getHeaders()
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                return collect($data['data'] ?? [])
                    ->pluck('id')
                    ->toArray();
            }
        } catch (\Exception $e) {
            // Fallback to configured models if API call fails
        }
        
        return [
            'openai/gpt-4o',
            'openai/gpt-4o-mini',
            'anthropic/claude-3.5-sonnet',
            'anthropic/claude-3-haiku',
            'google/gemini-pro',
            'meta-llama/llama-3.1-405b-instruct',
            'meta-llama/llama-3.1-70b-instruct',
            'mistralai/mixtral-8x7b-instruct',
            'qwen/qwen-2.5-72b-instruct',
            'deepseek/deepseek-chat'
        ];
    }
}
