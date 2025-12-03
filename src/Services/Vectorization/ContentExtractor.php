<?php

namespace LaravelAIEngine\Services\Vectorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Extracts content from model fields
 */
class ContentExtractor
{
    protected int $maxFieldSize;

    public function __construct()
    {
        $this->maxFieldSize = config('ai-engine.vectorization.max_field_size', 100000);
    }

    /**
     * Extract content from model fields
     */
    public function extract(Model $model, array $fields): array
    {
        $content = [];
        $chunkedFields = [];

        foreach ($fields as $field) {
            if (!isset($model->$field)) {
                continue;
            }

            $fieldValue = $model->$field;
            $fieldSize = strlen($fieldValue);

            // Chunk if too large
            if ($fieldSize > $this->maxFieldSize) {
                $chunked = $this->chunkLargeField($fieldValue);
                $content[] = $chunked;
                $chunkedFields[] = [
                    'field' => $field,
                    'original_size' => $fieldSize,
                    'chunked_size' => strlen($chunked),
                ];
            } else {
                $content[] = $fieldValue;
            }
        }

        return [
            'content' => $content,
            'chunked_fields' => $chunkedFields,
        ];
    }

    /**
     * Chunk large field (70% beginning + 30% end)
     */
    protected function chunkLargeField(string $content): string
    {
        if (strlen($content) <= $this->maxFieldSize) {
            return $content;
        }

        $separatorSize = 50;
        $availableSize = $this->maxFieldSize - $separatorSize;
        
        $beginningSize = (int) ($availableSize * 0.7);
        $endSize = (int) ($availableSize * 0.3);

        $beginning = substr($content, 0, $beginningSize);
        $end = substr($content, -$endSize);

        // Try to cut at sentence boundaries
        $lastPeriod = strrpos($beginning, '.');
        if ($lastPeriod !== false && $lastPeriod > $beginningSize * 0.8) {
            $beginning = substr($beginning, 0, $lastPeriod + 1);
        }

        $firstPeriod = strpos($end, '.');
        if ($firstPeriod !== false && $firstPeriod < $endSize * 0.2) {
            $end = substr($end, $firstPeriod + 1);
        }

        return trim($beginning) . ' ' . trim($end);
    }
}
