<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Vectorization;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Models\AIModel;

/**
 * Calculates token limits for embedding models
 */
class TokenCalculator
{
    /**
     * Get token limit for embedding model
     */
    public function getLimit(string $model): int
    {
        // Try database first
        try {
            $aiModel = AIModel::findByModelId($model);
            
            if ($aiModel && isset($aiModel->context_window['input'])) {
                return (int) $aiModel->context_window['input'];
            }
            
            if ($aiModel && isset($aiModel->max_tokens)) {
                return (int) $aiModel->max_tokens;
            }
        } catch (\Exception $e) {
            if (config('ai-engine.debug')) {
                Log::channel('ai-engine')->debug('Could not fetch model from database, using fallback', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Fallback to hardcoded limits
        return $this->getHardcodedLimit($model);
    }

    /**
     * Get hardcoded token limit
     */
    protected function getHardcodedLimit(string $model): int
    {
        $limits = [
            // OpenAI
            'text-embedding-3-small' => 8191,
            'text-embedding-3-large' => 8191,
            'text-embedding-ada-002' => 8191,
            
            // Cohere
            'embed-english-v3.0' => 512,
            'embed-multilingual-v3.0' => 512,
            
            // Voyage AI
            'voyage-large-2' => 16000,
            'voyage-code-2' => 16000,
            'voyage-2' => 4000,
            
            // Mistral
            'mistral-embed' => 8192,
            
            // Jina AI
            'jina-embeddings-v2-base-en' => 8192,
            'jina-embeddings-v2-small-en' => 8192,
        ];
        
        if (isset($limits[$model])) {
            return $limits[$model];
        }
        
        // Pattern matching
        if (str_contains($model, 'text-embedding-3') || str_contains($model, 'text-embedding-ada')) {
            return 8191;
        }
        
        if (str_contains($model, 'voyage')) {
            return 4000;
        }
        
        if (str_contains($model, 'cohere') || str_contains($model, 'embed-')) {
            return 512;
        }
        
        if (str_contains($model, 'jina')) {
            return 8192;
        }
        
        // Default
        if (config('ai-engine.debug')) {
            Log::channel('ai-engine')->warning('Unknown embedding model, using default token limit', [
                'model' => $model,
                'default_limit' => 4000,
            ]);
        }
        
        return 4000;
    }

    /**
     * Estimate tokens from text
     */
    public function estimate(string $text, ?string $profile = null): int
    {
        $text = trim($text);

        if ($text === '') {
            return 0;
        }

        $profile ??= $this->detectProfile($text);
        $charactersPerToken = $this->charactersPerToken($profile);

        return max(1, (int) ceil(mb_strlen($text, 'UTF-8') / $charactersPerToken));
    }

    /**
     * Convert a token budget to an approximate character budget.
     */
    public function charactersForTokens(int $tokens, ?string $profile = null): int
    {
        $tokens = max(1, $tokens);
        $profile ??= 'latin';

        return max(1, (int) floor($tokens * $this->charactersPerToken($profile)));
    }

    /**
     * Detect the text profile used for estimation.
     */
    public function detectProfile(string $text): string
    {
        if (preg_match('/[\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}]/u', $text) === 1) {
            return 'cjk';
        }

        if (preg_match('/[{}();$=<>]|\\b(function|class|return|public|private|protected|const|let|var|import|namespace)\\b/i', $text) === 1) {
            return 'code';
        }

        return 'latin';
    }

    /**
     * Return the configured character-per-token ratio for a profile.
     */
    public function charactersPerToken(string $profile): float
    {
        $configured = config("ai-engine.token_estimation.profiles.{$profile}");

        if (is_numeric($configured) && (float) $configured > 0) {
            return (float) $configured;
        }

        return match ($profile) {
            'cjk' => 1.0,
            'code' => 2.0,
            default => 4.0,
        };
    }
}
