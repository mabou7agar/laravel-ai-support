<?php

namespace LaravelAIEngine\Services\Vectorization;

use Illuminate\Support\Facades\Log;

/**
 * Chunks content for vectorization
 */
class ContentChunker
{
    protected TokenCalculator $tokenCalculator;

    public function __construct(TokenCalculator $tokenCalculator)
    {
        $this->tokenCalculator = $tokenCalculator;
    }

    /**
     * Split content into chunks
     */
    public function split(string $content, string $modelClass, ?int $modelId = null): array
    {
        if (empty($content)) {
            return [];
        }

        $embeddingModel = config('ai-engine.vector.embedding_model', 'text-embedding-3-small');
        $tokenLimit = $this->tokenCalculator->getLimit($embeddingModel);
        
        // Calculate chunk size
        $chunkSize = config('ai-engine.vectorization.chunk_size');
        if (!$chunkSize) {
            $chunkSize = (int) ($tokenLimit * 0.9 * 1.3);
        }
        
        $overlap = config('ai-engine.vectorization.chunk_overlap', 200);
        
        // Single chunk if fits
        if (strlen($content) <= $chunkSize) {
            return [$content];
        }

        $chunks = [];
        $position = 0;
        $contentLength = strlen($content);

        while ($position < $contentLength) {
            $chunk = substr($content, $position, $chunkSize);
            
            // Break at sentence boundary
            if ($position + $chunkSize < $contentLength) {
                $lastPeriod = strrpos($chunk, '.');
                $lastNewline = strrpos($chunk, "\n");
                $breakPoint = max($lastPeriod, $lastNewline);
                
                if ($breakPoint !== false && $breakPoint > $chunkSize * 0.8) {
                    $chunk = substr($chunk, 0, $breakPoint + 1);
                    $position += $breakPoint + 1;
                } else {
                    $position += $chunkSize;
                }
            } else {
                $position = $contentLength;
            }
            
            $chunks[] = trim($chunk);
            
            // Overlap for next chunk
            if ($position < $contentLength) {
                $position -= $overlap;
            }
        }

        if (config('ai-engine.debug')) {
            Log::channel('ai-engine')->info('Content split into chunks', [
                'model' => $modelClass,
                'id' => $modelId,
                'total_length' => $contentLength,
                'chunk_count' => count($chunks),
                'chunk_size' => $chunkSize,
                'overlap' => $overlap,
                'chunk_lengths' => array_map('strlen', $chunks),
            ]);
        }

        return $chunks;
    }

    /**
     * Truncate content to fit token limit
     */
    public function truncate(string $content, string $embeddingModel): string
    {
        $tokenLimit = $this->tokenCalculator->getLimit($embeddingModel);
        $maxChars = config('ai-engine.vectorization.max_content_length');
        
        if (!$maxChars) {
            $maxChars = (int) ($tokenLimit * 0.9 * 1.3);
        }

        if (strlen($content) <= $maxChars) {
            return $content;
        }

        $truncated = substr($content, 0, $maxChars);

        // Try to cut at sentence
        $lastPeriod = strrpos($truncated, '.');
        if ($lastPeriod !== false && $lastPeriod > $maxChars * 0.9) {
            $truncated = substr($truncated, 0, $lastPeriod + 1);
        }

        return $truncated;
    }
}
