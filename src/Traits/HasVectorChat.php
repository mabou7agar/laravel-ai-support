<?php

namespace LaravelAIEngine\Traits;

use LaravelAIEngine\Services\RAG\VectorRAGBridge;

trait HasVectorChat
{
    /**
     * Chat with AI using vector context from this model class
     */
    public static function vectorChat(
        string $query,
        ?string $userId = null,
        array $options = []
    ): array {
        $rag = app(VectorRAGBridge::class);
        
        return $rag->chat($query, static::class, $userId, $options);
    }

    /**
     * Stream chat with AI using vector context
     */
    public static function vectorChatStream(
        string $query,
        callable $callback,
        ?string $userId = null,
        array $options = []
    ): array {
        $rag = app(VectorRAGBridge::class);
        
        return $rag->streamChat($query, static::class, $callback, $userId, $options);
    }

    /**
     * Ask a question about this specific model instance
     */
    public function ask(string $question, array $options = []): string
    {
        $rag = app(VectorRAGBridge::class);
        
        // Get content from this model
        $content = $this->getVectorContent();
        
        // Build prompt with model content
        $prompt = <<<PROMPT
Based on the following information:

{$content}

Question: {$question}

Please provide a helpful answer based on the information above.
PROMPT;

        $engine = $options['engine'] ?? config('ai-engine.default');
        $model = $options['model'] ?? 'gpt-4o';

        return app(\LaravelAIEngine\Services\AIEngineManager::class)
            ->engine($engine)
            ->model($model)
            ->chat($prompt);
    }

    /**
     * Summarize this model's content
     */
    public function summarize(int $maxWords = 100, array $options = []): string
    {
        $content = $this->getVectorContent();
        
        $prompt = "Summarize the following in {$maxWords} words or less:\n\n{$content}";

        $engine = $options['engine'] ?? config('ai-engine.default');
        $model = $options['model'] ?? 'gpt-4o-mini';

        return app(\LaravelAIEngine\Services\AIEngineManager::class)
            ->engine($engine)
            ->model($model)
            ->chat($prompt);
    }

    /**
     * Generate tags/keywords for this model
     */
    public function generateTags(int $maxTags = 5, array $options = []): array
    {
        $content = $this->getVectorContent();
        
        $prompt = "Extract {$maxTags} relevant tags/keywords from the following text. Return only the tags as a comma-separated list:\n\n{$content}";

        $engine = $options['engine'] ?? config('ai-engine.default');
        $model = $options['model'] ?? 'gpt-4o-mini';

        $response = app(\LaravelAIEngine\Services\AIEngineManager::class)
            ->engine($engine)
            ->model($model)
            ->chat($prompt);

        // Parse tags from response
        $tags = array_map('trim', explode(',', $response));
        return array_slice($tags, 0, $maxTags);
    }

    /**
     * Get vector content (required by Vectorizable trait)
     */
    abstract public function getVectorContent(): string;
}
