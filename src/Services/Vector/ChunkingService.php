<?php

namespace LaravelAIEngine\Services\Vector;

use Illuminate\Support\Facades\Log;

class ChunkingService
{
    protected int $chunkSize;
    protected int $chunkOverlap;
    protected int $minChunkSize;

    public function __construct()
    {
        $this->chunkSize = config('ai-engine.vector.chunking.chunk_size', 1000);
        $this->chunkOverlap = config('ai-engine.vector.chunking.chunk_overlap', 200);
        $this->minChunkSize = config('ai-engine.vector.chunking.min_chunk_size', 100);
    }

    /**
     * Split text into chunks
     */
    public function chunk(string $text, ?array $options = null): array
    {
        $chunkSize = $options['chunk_size'] ?? $this->chunkSize;
        $overlap = $options['overlap'] ?? $this->chunkOverlap;
        $minSize = $options['min_size'] ?? $this->minChunkSize;

        // Clean text
        $text = $this->cleanText($text);

        if (strlen($text) <= $chunkSize) {
            return [$text];
        }

        // Try semantic chunking first (by paragraphs)
        $chunks = $this->chunkByParagraphs($text, $chunkSize, $overlap);

        // If chunks are too large, split by sentences
        $finalChunks = [];
        foreach ($chunks as $chunk) {
            if (strlen($chunk) > $chunkSize * 1.5) {
                $finalChunks = array_merge(
                    $finalChunks,
                    $this->chunkBySentences($chunk, $chunkSize, $overlap)
                );
            } else {
                $finalChunks[] = $chunk;
            }
        }

        // Filter out chunks that are too small
        $finalChunks = array_filter($finalChunks, function ($chunk) use ($minSize) {
            return strlen(trim($chunk)) >= $minSize;
        });

        return array_values($finalChunks);
    }

    /**
     * Chunk text by paragraphs
     */
    protected function chunkByParagraphs(
        string $text,
        int $chunkSize,
        int $overlap
    ): array {
        $paragraphs = preg_split('/\n\s*\n/', $text);
        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            
            if (empty($paragraph)) {
                continue;
            }

            // If adding this paragraph exceeds chunk size
            if (strlen($currentChunk) + strlen($paragraph) > $chunkSize && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                
                // Start new chunk with overlap
                $currentChunk = $this->getOverlapText($currentChunk, $overlap) . "\n\n" . $paragraph;
            } else {
                $currentChunk .= (empty($currentChunk) ? '' : "\n\n") . $paragraph;
            }
        }

        // Add remaining chunk
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Chunk text by sentences
     */
    protected function chunkBySentences(
        string $text,
        int $chunkSize,
        int $overlap
    ): array {
        $sentences = $this->splitIntoSentences($text);
        $chunks = [];
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            
            if (empty($sentence)) {
                continue;
            }

            // If adding this sentence exceeds chunk size
            if (strlen($currentChunk) + strlen($sentence) > $chunkSize && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                
                // Start new chunk with overlap
                $currentChunk = $this->getOverlapText($currentChunk, $overlap) . ' ' . $sentence;
            } else {
                $currentChunk .= (empty($currentChunk) ? '' : ' ') . $sentence;
            }
        }

        // Add remaining chunk
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Split text into sentences
     */
    protected function splitIntoSentences(string $text): array
    {
        // Split on sentence boundaries (., !, ?)
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $text);
        return array_filter($sentences);
    }

    /**
     * Get overlap text from end of chunk
     */
    protected function getOverlapText(string $text, int $overlapSize): string
    {
        if (strlen($text) <= $overlapSize) {
            return $text;
        }

        // Get last N characters
        $overlap = substr($text, -$overlapSize);

        // Try to start at a word boundary
        $spacePos = strpos($overlap, ' ');
        if ($spacePos !== false) {
            $overlap = substr($overlap, $spacePos + 1);
        }

        return $overlap;
    }

    /**
     * Clean text before chunking
     */
    protected function cleanText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Normalize line breaks
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        
        // Remove multiple consecutive line breaks
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Chunk with metadata preservation
     */
    public function chunkWithMetadata(
        string $text,
        array $metadata = [],
        ?array $options = null
    ): array {
        $chunks = $this->chunk($text, $options);
        $result = [];

        foreach ($chunks as $index => $chunk) {
            $result[] = [
                'content' => $chunk,
                'metadata' => array_merge($metadata, [
                    'chunk_index' => $index,
                    'total_chunks' => count($chunks),
                    'chunk_size' => strlen($chunk),
                ]),
            ];
        }

        return $result;
    }

    /**
     * Smart chunking based on content type
     */
    public function smartChunk(string $text, string $contentType = 'text'): array
    {
        return match ($contentType) {
            'code' => $this->chunkCode($text),
            'markdown' => $this->chunkMarkdown($text),
            'html' => $this->chunkHtml($text),
            default => $this->chunk($text),
        };
    }

    /**
     * Chunk code by functions/classes
     */
    protected function chunkCode(string $code): array
    {
        // Simple code chunking - split by function/class definitions
        $chunks = [];
        $lines = explode("\n", $code);
        $currentChunk = '';
        $inFunction = false;

        foreach ($lines as $line) {
            // Detect function/class start
            if (preg_match('/^(function|class|interface|trait)\s+/i', trim($line))) {
                if (!empty($currentChunk) && strlen($currentChunk) > $this->minChunkSize) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }
                $inFunction = true;
            }

            $currentChunk .= $line . "\n";

            // If chunk is getting too large, split it
            if (strlen($currentChunk) > $this->chunkSize * 1.5) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
                $inFunction = false;
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Chunk markdown by sections
     */
    protected function chunkMarkdown(string $markdown): array
    {
        // Split by headers
        $sections = preg_split('/^(#{1,6}\s+.+)$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        $chunks = [];
        $currentChunk = '';

        for ($i = 0; $i < count($sections); $i++) {
            $section = $sections[$i];
            
            if (empty(trim($section))) {
                continue;
            }

            // If this is a header and current chunk is not empty
            if (preg_match('/^#{1,6}\s+/', $section) && !empty($currentChunk)) {
                if (strlen($currentChunk) > $this->minChunkSize) {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $section;
            } else {
                $currentChunk .= "\n" . $section;
            }

            // If chunk is too large, save it
            if (strlen($currentChunk) > $this->chunkSize) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return array_filter($chunks);
    }

    /**
     * Chunk HTML by elements
     */
    protected function chunkHtml(string $html): array
    {
        // Strip HTML tags and chunk the text
        $text = strip_tags($html);
        return $this->chunk($text);
    }

    /**
     * Estimate token count for text
     */
    public function estimateTokens(string $text): int
    {
        // Rough estimation: ~4 characters per token
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Chunk to fit within token limit
     */
    public function chunkByTokens(string $text, int $maxTokens = 8000): array
    {
        $maxChars = $maxTokens * 4; // Rough estimation
        return $this->chunk($text, ['chunk_size' => $maxChars]);
    }

    /**
     * Set chunk size
     */
    public function setChunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }

    /**
     * Set chunk overlap
     */
    public function setOverlap(int $overlap): self
    {
        $this->chunkOverlap = $overlap;
        return $this;
    }

    /**
     * Set minimum chunk size
     */
    public function setMinChunkSize(int $size): self
    {
        $this->minChunkSize = $size;
        return $this;
    }
}
