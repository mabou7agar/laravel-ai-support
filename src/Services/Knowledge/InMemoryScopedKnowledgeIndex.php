<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Knowledge;

use LaravelAIEngine\Contracts\ScopedKnowledgeIndex;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\DTOs\ScopedKnowledgeMatch;

/**
 * Dependency-free default index for host-provided scoped knowledge.
 *
 * Hosts with a persistent semantic/vector index may replace the
 * ScopedKnowledgeIndex binding. The default remains useful for operational
 * guides and bounded document sets without introducing a database model or
 * requiring the package to understand the host's domain schema.
 */
final class InMemoryScopedKnowledgeIndex implements ScopedKnowledgeIndex
{
    public function search(iterable $documents, string $query, int $limit = 8): array
    {
        $query = $this->normalize($query);
        $queryTokens = array_values(array_unique($this->tokens($query)));
        if ($query === '' || $queryTokens === []) {
            return [];
        }

        $matches = [];
        $maximumDocuments = max(
            1,
            (int) config('ai-agent.assistant.knowledge_index.max_documents', 2000),
        );
        $indexed = 0;
        foreach ($documents as $document) {
            if (!$document instanceof ScopedKnowledgeDocument) {
                continue;
            }
            if ($indexed >= $maximumDocuments) {
                break;
            }
            $indexed++;

            $title = $this->metadataString($document, 'title');
            $keywords = $this->metadataStrings($document, 'keywords');
            $haystack = $this->normalize(implode(' ', array_filter([
                $title,
                $document->text,
                implode(' ', $keywords),
            ])));
            $haystackTokens = array_fill_keys($this->tokens($haystack), true);
            $matched = 0;
            foreach ($queryTokens as $token) {
                if (isset($haystackTokens[$token])) {
                    $matched++;
                }
            }
            if ($matched === 0) {
                continue;
            }

            $score = $matched / count($queryTokens);
            if (str_contains($haystack, $query)) {
                $score += 1.0;
            }
            if ($title !== '' && str_contains($this->normalize($title), $query)) {
                $score += 0.5;
            }

            $matches[] = new ScopedKnowledgeMatch($document, round($score, 6));
        }

        usort($matches, static function (ScopedKnowledgeMatch $left, ScopedKnowledgeMatch $right): int {
            $score = $right->score <=> $left->score;

            return $score !== 0
                ? $score
                : strcmp($left->document->id, $right->document->id);
        });

        return array_slice($matches, 0, max(1, $limit));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[^\p{L}\p{N}_]+/u', $value) ?: [],
            static fn (string $token): bool => mb_strlen($token) >= 2,
        ));
    }

    private function metadataString(ScopedKnowledgeDocument $document, string $key): string
    {
        $value = $document->metadata[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return list<string> */
    private function metadataStrings(ScopedKnowledgeDocument $document, string $key): array
    {
        $value = $document->metadata[$key] ?? [];
        if (is_scalar($value)) {
            $value = [$value];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            is_array($value) ? $value : [],
        )));
    }
}
