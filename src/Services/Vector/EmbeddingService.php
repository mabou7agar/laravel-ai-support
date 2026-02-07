<?php

namespace LaravelAIEngine\Services\Vector;

use OpenAI\Client as OpenAIClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\CreditManager;

class EmbeddingService
{
    protected OpenAIClient $client;
    protected CreditManager $creditManager;
    protected string $model;
    protected int $dimensions;
    protected bool $cacheEnabled;
    protected int $cacheTtl;

    public function __construct(
        OpenAIClient $client,
        CreditManager $creditManager
    ) {
        $this->client = $client;
        $this->creditManager = $creditManager;
        $this->model = config('ai-engine.vector.embedding_model', 'text-embedding-3-large');
        $this->dimensions = $this->getDimensionsForModel($this->model);
        $this->cacheEnabled = config('ai-engine.vector.cache_embeddings', true);
        $this->cacheTtl = config('ai-engine.vector.cache_ttl', 86400); // 24 hours
    }

    /**
     * Get dimensions for the embedding model
     * Uses config value if set, otherwise defaults to model's max
     */
    protected function getDimensionsForModel(string $model): int
    {
        // Max dimensions per model
        $modelMaxDimensions = [
            'text-embedding-3-large' => 3072,
            'text-embedding-3-small' => 1536,
            'text-embedding-ada-002' => 1536,
        ];

        $maxForModel = $modelMaxDimensions[$model] ?? 1536;

        // Use config if explicitly set, otherwise use model's max
        $configDimensions = config('ai-engine.vector.embedding_dimensions');

        if ($configDimensions === null) {
            return $maxForModel;
        }

        // If config exceeds model max, use max
        if ((int) $configDimensions > $maxForModel) {
            Log::info("Embedding dimensions capped to model max", [
                'configured' => $configDimensions,
                'max' => $maxForModel,
                'model' => $model,
            ]);
            return $maxForModel;
        }

        return (int) $configDimensions;
    }

    /**
     * Generate embedding for a single text
     */
    public function embed(string $text, ?string $userId = null): array
    {
        if (empty(trim($text))) {
            throw new \InvalidArgumentException('Text cannot be empty');
        }

        // Check cache
        if ($this->cacheEnabled) {
            $cacheKey = $this->getCacheKey($text);
            $cached = Cache::get($cacheKey);

            if ($cached) {
                Log::debug('Embedding cache hit', ['text_length' => strlen($text)]);
                return $cached;
            }
        }

        // Try OpenAI first, then fallback to Gemini, then OpenRouter if quota exceeded
        $providers = [
            'openai' => fn() => $this->embedWithOpenAI($text, $userId),
            'openrouter' => fn() => $this->embedWithOpenRouter($text, $userId),
            'gemini' => fn() => $this->embedWithGemini($text, $userId),
        ];

        $lastException = null;

        foreach ($providers as $provider => $embedFn) {
            try {
                Log::debug("Trying embedding with {$provider}", [
                    'text_length' => strlen($text),
                ]);

                $embedding = $embedFn();

                // Cache the result
                if ($this->cacheEnabled) {
                    Cache::put($this->getCacheKey($text), $embedding, $this->cacheTtl);
                }

                if ($provider !== 'openai') {
                    Log::warning("Embedding succeeded with fallback provider", [
                        'provider' => $provider,
                        'text_length' => strlen($text),
                    ]);
                }

                return $embedding;

            } catch (\Exception $e) {
                $lastException = $e;
                $errorMessage = $e->getMessage();

                // Check if it's a quota error
                $isQuotaError = str_contains($errorMessage, 'quota') ||
                    str_contains($errorMessage, 'insufficient_quota');

                Log::warning("Embedding failed with {$provider}", [
                    'error' => $errorMessage,
                    'is_quota_error' => $isQuotaError,
                    'text_length' => strlen($text),
                ]);

                // If it's not a quota error, don't try other providers
                if (!$isQuotaError) {
                    break;
                }

                // Continue to next provider
            }
        }

        // All providers failed
        Log::error('Embedding generation failed with all providers', [
            'error' => $lastException?->getMessage(),
            'text_length' => strlen($text),
        ]);
        throw $lastException ?? new \Exception('Embedding generation failed');
    }

    /**
     * Generate embedding using OpenAI
     */
    protected function embedWithOpenAI(string $text, ?string $userId = null): array
    {
        $params = [
            'model' => $this->model,
            'input' => $text,
        ];

        // Only include dimensions if model supports custom dimensions
        if ($this->model !== 'text-embedding-ada-002') {
            $params['dimensions'] = $this->dimensions;
        }

        $response = $this->client->embeddings()->create($params);
        $embedding = $response->embeddings[0]->embedding;

        // Get tokens used
        $tokensUsed = $response->usage->totalTokens ?? $this->estimateTokens($text);

        Log::info('Embedding generated with OpenAI', [
            'text_length' => strlen($text),
            'tokens_used' => $tokensUsed,
            'model' => $this->model,
        ]);

        return $embedding;
    }

    /**
     * Generate embedding using Gemini (fallback)
     */
    protected function embedWithGemini(string $text, ?string $userId = null): array
    {
        $apiKey = config('ai-engine.gemini.api_key') ?? config('services.gemini.api_key');

        if (!$apiKey) {
            throw new \Exception('Gemini API key not configured');
        }

        $model = 'text-embedding-004';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key={$apiKey}";

        $response = \Illuminate\Support\Facades\Http::post($url, [
            'model' => "models/{$model}",
            'content' => [
                'parts' => [
                    ['text' => $text]
                ]
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception("Gemini embedding failed: " . $response->body());
        }

        $data = $response->json();
        $embedding = $data['embedding']['values'] ?? null;

        if (!$embedding) {
            throw new \Exception('No embedding returned from Gemini');
        }

        Log::info('Embedding generated with Gemini (fallback)', [
            'text_length' => strlen($text),
            'model' => $model,
            'dimensions' => count($embedding),
        ]);

        return $embedding;
    }

    /**
     * Generate embedding using OpenRouter (fallback)
     */
    protected function embedWithOpenRouter(string $text, ?string $userId = null): array
    {
        $apiKey = config('ai-engine.engines.openrouter.api_key') ?? env('OPENROUTER_API_KEY');
        if (!$apiKey) {
            throw new \Exception('OpenRouter API key not configured');
        }


        // Determine model based on configured dimensions
        // Vector indexer defaults to text-embedding-3-large (3072 dimensions)
        $dimensions = config('vector-indexer.openai.embedding_dimensions', 3072);

        // Map dimensions to appropriate OpenAI model on OpenRouter
        $model = match ($dimensions) {
            3072 => 'openai/text-embedding-3-large',
            1536 => 'openai/text-embedding-3-small',
            default => 'openai/text-embedding-3-small', // Fallback
        };

        $url = 'https://openrouter.ai/api/v1/embeddings';

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer' => config('app.url'),
            'X-Title' => config('app.name'),
        ])->post($url, [
                    'model' => $model,
                    'input' => $text,
                ]);

        if (!$response->successful()) {
            throw new \Exception("OpenRouter embedding failed: " . $response->body());
        }

        $data = $response->json();
        $embedding = $data['data'][0]['embedding'] ?? null;

        if (!$embedding) {
            throw new \Exception('No embedding returned from OpenRouter');
        }

        Log::info('Embedding generated with OpenRouter (fallback)', [
            'text_length' => strlen($text),
            'model' => $model,
            'dimensions' => count($embedding),
        ]);

        return $embedding;
    }

    /**
     * Generate embeddings for multiple texts (batch)
     */
    public function embedBatch(array $texts, ?string $userId = null): array
    {
        if (empty($texts)) {
            return [];
        }

        $embeddings = [];
        $uncachedTexts = [];
        $uncachedIndices = [];

        // Check cache for each text
        if ($this->cacheEnabled) {
            foreach ($texts as $index => $text) {
                $cacheKey = $this->getCacheKey($text);
                $cached = Cache::get($cacheKey);

                if ($cached) {
                    $embeddings[$index] = $cached;
                } else {
                    $uncachedTexts[] = $text;
                    $uncachedIndices[] = $index;
                }
            }
        } else {
            $uncachedTexts = $texts;
            $uncachedIndices = array_keys($texts);
        }

        // Generate embeddings for uncached texts
        if (!empty($uncachedTexts)) {
            try {
                // Process in chunks to avoid API limits
                $chunkSize = config('ai-engine.vector.batch_size', 100);
                $chunks = array_chunk($uncachedTexts, $chunkSize, true);
                $chunkIndices = array_chunk($uncachedIndices, $chunkSize, true);

                foreach ($chunks as $chunkIndex => $chunk) {
                    $response = $this->client->embeddings()->create([
                        'model' => $this->model,
                        'input' => array_values($chunk),
                        'dimensions' => $this->dimensions,
                    ]);

                    $indices = $chunkIndices[$chunkIndex];
                    foreach ($response->embeddings as $i => $embeddingData) {
                        $originalIndex = $indices[$i];
                        $embedding = $embeddingData->embedding;
                        $embeddings[$originalIndex] = $embedding;

                        // Cache individual embedding
                        if ($this->cacheEnabled) {
                            $text = $uncachedTexts[array_search($originalIndex, $uncachedIndices)];
                            Cache::put($this->getCacheKey($text), $embedding, $this->cacheTtl);
                        }
                    }

                    // Track credits
                    $tokensUsed = $response->usage->totalTokens ?? 0;
                    $this->creditManager->deductCredits($userId, $tokensUsed, 'embedding');
                }

                Log::info('Batch embeddings generated', [
                    'count' => count($uncachedTexts),
                    'model' => $this->model,
                ]);
            } catch (\Exception $e) {
                Log::error('Batch embedding generation failed', [
                    'error' => $e->getMessage(),
                    'count' => count($uncachedTexts),
                ]);
                throw $e;
            }
        }

        // Sort by original indices
        ksort($embeddings);
        return array_values($embeddings);
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    public function cosineSimilarity(array $vector1, array $vector2): float
    {
        if (count($vector1) !== count($vector2)) {
            throw new \InvalidArgumentException('Vectors must have the same dimensions');
        }

        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] ** 2;
            $magnitude2 += $vector2[$i] ** 2;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * Get embedding dimensions
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Get embedding model
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Set embedding model
     */
    public function setModel(string $model, int $dimensions): void
    {
        $this->model = $model;
        $this->dimensions = $dimensions;
    }

    /**
     * Clear embedding cache
     */
    public function clearCache(?string $text = null): void
    {
        if ($text) {
            Cache::forget($this->getCacheKey($text));
        } else {
            // Clear all embedding caches (requires cache tagging)
            Cache::tags(['embeddings'])->flush();
        }
    }

    /**
     * Get cache key for text
     */
    protected function getCacheKey(string $text): string
    {
        return 'embedding:' . $this->model . ':' . md5($text);
    }

    /**
     * Estimate tokens for text (rough approximation)
     */
    protected function estimateTokens(string $text): int
    {
        // Rough estimate: 1 token ≈ 4 characters
        return (int) ceil(strlen($text) / 4);
    }
}
