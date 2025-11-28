<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Context Enhancement Service
 * 
 * Improves RAG context quality by:
 * - Expanding search results with related records
 * - Adding metadata and summaries
 * - Deduplicating and ranking results
 * - Enriching context with additional information
 */
class ContextEnhancementService
{
    /**
     * Enhance search results with additional context
     * 
     * @param Collection $results
     * @param string $query
     * @param array $options
     * @return array Enhanced context
     */
    public function enhanceContext(Collection $results, string $query, array $options = []): array
    {
        if ($results->isEmpty()) {
            return [
                'items' => [],
                'summary' => 'No relevant information found.',
                'metadata' => [
                    'total_results' => 0,
                    'enhanced' => false,
                ],
            ];
        }

        $enhancedItems = [];
        $seenContent = [];

        foreach ($results as $index => $result) {
            // Get base content
            $content = $this->extractContent($result);
            
            // Skip duplicates
            $contentHash = md5($content);
            if (isset($seenContent[$contentHash])) {
                continue;
            }
            $seenContent[$contentHash] = true;

            // Build enhanced item
            $enhancedItem = [
                'content' => $content,
                'metadata' => $this->extractMetadata($result),
                'source' => $this->buildSourceInfo($result, $index),
                'relevance_score' => $result->_score ?? 0,
            ];

            // Add related context if available
            if ($options['include_related'] ?? true) {
                $relatedContext = $this->getRelatedContext($result);
                if (!empty($relatedContext)) {
                    $enhancedItem['related'] = $relatedContext;
                    $enhancedItem['content'] .= "\n\nRelated Information:\n" . $relatedContext;
                }
            }

            $enhancedItems[] = $enhancedItem;
        }

        // Generate summary
        $summary = $this->generateContextSummary($enhancedItems, $query);

        return [
            'items' => $enhancedItems,
            'summary' => $summary,
            'metadata' => [
                'total_results' => count($enhancedItems),
                'original_results' => $results->count(),
                'enhanced' => true,
                'query' => $query,
            ],
        ];
    }

    /**
     * Extract content from result
     * 
     * @param mixed $result
     * @return string
     */
    protected function extractContent($result): string
    {
        if (is_array($result)) {
            return $result['content'] ?? $result['text'] ?? '';
        }

        if (is_object($result)) {
            if (method_exists($result, 'getVectorContent')) {
                return $result->getVectorContent();
            }

            if (isset($result->content)) {
                return $result->content;
            }

            if (isset($result->text)) {
                return $result->text;
            }

            // Try common fields
            foreach (['subject', 'title', 'body', 'description', 'message'] as $field) {
                if (isset($result->$field)) {
                    return $result->$field;
                }
            }
        }

        return '';
    }

    /**
     * Extract metadata from result
     * 
     * @param mixed $result
     * @return array
     */
    protected function extractMetadata($result): array
    {
        $metadata = [];

        if (is_object($result)) {
            // Common metadata fields
            $metadataFields = [
                'id', 'user_id', 'created_at', 'updated_at',
                'from_name', 'from_address', 'to_addresses',
                'subject', 'email_date', 'folder_name',
                'category', 'type', 'status', 'priority'
            ];

            foreach ($metadataFields as $field) {
                if (isset($result->$field)) {
                    $metadata[$field] = $result->$field;
                }
            }

            // Get model class
            if (method_exists($result, 'getMorphClass')) {
                $metadata['model_type'] = $result->getMorphClass();
            } else {
                $metadata['model_type'] = get_class($result);
            }
        }

        return $metadata;
    }

    /**
     * Build source information
     * 
     * @param mixed $result
     * @param int $index
     * @return array
     */
    protected function buildSourceInfo($result, int $index): array
    {
        $source = [
            'index' => $index,
            'type' => 'unknown',
        ];

        if (is_object($result)) {
            $source['type'] = class_basename($result);
            $source['id'] = $result->id ?? null;

            // Add human-readable title
            if (isset($result->subject)) {
                $source['title'] = $result->subject;
            } elseif (isset($result->title)) {
                $source['title'] = $result->title;
            } elseif (isset($result->name)) {
                $source['title'] = $result->name;
            }

            // Add date if available
            if (isset($result->created_at)) {
                $source['date'] = $result->created_at;
            } elseif (isset($result->email_date)) {
                $source['date'] = $result->email_date;
            }
        }

        return $source;
    }

    /**
     * Get related context for a result
     * 
     * @param mixed $result
     * @return string
     */
    protected function getRelatedContext($result): string
    {
        if (!is_object($result)) {
            return '';
        }

        $relatedParts = [];

        // Add conversation thread context for emails
        if (isset($result->in_reply_to) && !empty($result->in_reply_to)) {
            $relatedParts[] = "This is part of an email conversation thread.";
        }

        // Add sender context
        if (isset($result->from_name) && isset($result->from_address)) {
            $relatedParts[] = "From: {$result->from_name} ({$result->from_address})";
        }

        // Add recipient context
        if (isset($result->to_addresses) && is_array($result->to_addresses)) {
            $toList = array_map(function($addr) {
                return $addr['email'] ?? '';
            }, array_slice($result->to_addresses, 0, 3));
            
            if (!empty($toList)) {
                $relatedParts[] = "To: " . implode(', ', $toList);
            }
        }

        // Add date context
        if (isset($result->email_date)) {
            $relatedParts[] = "Date: " . $result->email_date;
        } elseif (isset($result->created_at)) {
            $relatedParts[] = "Date: " . $result->created_at;
        }

        // Add folder/category context
        if (isset($result->folder_name)) {
            $relatedParts[] = "Folder: {$result->folder_name}";
        } elseif (isset($result->category)) {
            $relatedParts[] = "Category: {$result->category}";
        }

        return implode("\n", $relatedParts);
    }

    /**
     * Generate a summary of the context
     * 
     * @param array $items
     * @param string $query
     * @return string
     */
    protected function generateContextSummary(array $items, string $query): string
    {
        $count = count($items);

        if ($count === 0) {
            return "No relevant information found for: {$query}";
        }

        // Group by type
        $types = [];
        foreach ($items as $item) {
            $type = $item['source']['type'] ?? 'item';
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        $typeSummary = [];
        foreach ($types as $type => $count) {
            $typeSummary[] = "{$count} {$type}" . ($count > 1 ? 's' : '');
        }

        return sprintf(
            "Found %d relevant result%s: %s",
            $count,
            $count > 1 ? 's' : '',
            implode(', ', $typeSummary)
        );
    }

    /**
     * Expand search with related records
     * 
     * @param Collection $results
     * @param array $options
     * @return Collection
     */
    public function expandWithRelated(Collection $results, array $options = []): Collection
    {
        $expanded = collect();

        foreach ($results as $result) {
            $expanded->push($result);

            // Add related records if model supports it
            if (is_object($result) && method_exists($result, 'similarTo')) {
                try {
                    $similar = $result->similarTo(2);
                    foreach ($similar as $item) {
                        $expanded->push($item);
                    }
                } catch (\Exception $e) {
                    Log::debug('Failed to get similar items', [
                        'model' => get_class($result),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $expanded->unique('id');
    }
}
