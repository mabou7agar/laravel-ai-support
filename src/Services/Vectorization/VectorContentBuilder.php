<?php

namespace LaravelAIEngine\Services\Vectorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates vector content generation
 */
class VectorContentBuilder
{
    protected VectorizableFieldDetector $fieldDetector;
    protected ContentExtractor $contentExtractor;
    protected ContentChunker $contentChunker;
    protected TokenCalculator $tokenCalculator;

    public function __construct(
        VectorizableFieldDetector $fieldDetector,
        ContentExtractor $contentExtractor,
        ContentChunker $contentChunker,
        TokenCalculator $tokenCalculator
    ) {
        $this->fieldDetector = $fieldDetector;
        $this->contentExtractor = $contentExtractor;
        $this->contentChunker = $contentChunker;
        $this->tokenCalculator = $tokenCalculator;
    }

    /**
     * Build vector content for model
     */
    public function build(Model $model): string
    {
        $fullContent = $this->buildFullContent($model);
        
        $strategy = config('ai-engine.vectorization.strategy', 'split');
        
        if ($strategy === 'split') {
            $chunks = $this->contentChunker->split(
                $fullContent,
                get_class($model),
                $model->id ?? null
            );
            return $chunks[0] ?? '';
        }
        
        // Truncate strategy
        $embeddingModel = config('ai-engine.vector.embedding_model', 'text-embedding-3-small');
        return $this->contentChunker->truncate($fullContent, $embeddingModel);
    }

    /**
     * Build vector content chunks for model
     */
    public function buildChunks(Model $model): array
    {
        $fullContent = $this->buildFullContent($model);
        
        $strategy = config('ai-engine.vectorization.strategy', 'split');
        
        if ($strategy === 'truncate') {
            $embeddingModel = config('ai-engine.vector.embedding_model', 'text-embedding-3-small');
            return [$this->contentChunker->truncate($fullContent, $embeddingModel)];
        }
        
        return $this->contentChunker->split(
            $fullContent,
            get_class($model),
            $model->id ?? null
        );
    }

    /**
     * Build full content without chunking/truncation
     */
    protected function buildFullContent(Model $model): string
    {
        // Detect fields
        $detection = $this->fieldDetector->detect($model);
        $fields = $detection['fields'];
        $source = $detection['source'];

        if (empty($fields)) {
            // Fallback to common fields
            $fields = ['title', 'name', 'content', 'description', 'body', 'text'];
            $source = 'fallback common fields';
        }

        // Extract content
        $extraction = $this->contentExtractor->extract($model, $fields);
        $content = $extraction['content'];
        $chunkedFields = $extraction['chunked_fields'];

        // Add media content if available
        $hasMedia = false;
        if (method_exists($model, 'getMediaVectorContent')) {
            try {
                $mediaContent = $model->getMediaVectorContent();
                if (!empty($mediaContent)) {
                    $content[] = $mediaContent;
                    $hasMedia = true;
                    
                    if (config('ai-engine.debug')) {
                        Log::channel('ai-engine')->debug('Media content integrated', [
                            'model' => get_class($model),
                            'id' => $model->id ?? 'new',
                            'media_content_length' => strlen($mediaContent),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Media processing failed, continuing with text only', [
                    'model' => get_class($model),
                    'id' => $model->id ?? 'new',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $fullContent = implode(' ', $content);

        // Debug logging
        if (config('ai-engine.debug')) {
            Log::channel('ai-engine')->debug('Full vector content generated', [
                'model' => get_class($model),
                'id' => $model->id ?? 'new',
                'source' => $source,
                'fields_used' => $fields,
                'fields_chunked' => $chunkedFields,
                'has_media' => $hasMedia,
                'content_length' => strlen($fullContent),
            ]);
        }

        // Log chunked fields
        if (!empty($chunkedFields)) {
            Log::channel('ai-engine')->info('Large fields chunked during vectorization', [
                'model' => get_class($model),
                'id' => $model->id ?? 'new',
                'chunked_fields' => $chunkedFields,
            ]);
        }

        return $fullContent;
    }
}
