<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use LaravelAIEngine\DTOs\RerankResult;

class RerankingService
{
    protected ?array $fakeResults = null;
    protected array $requests = [];

    /**
     * @param array<int, string|array{document?:string, score?:float, metadata?:array<string,mixed>}> $documents
     * @return array<int, RerankResult>
     */
    public function rerank(string $query, array $documents, int $limit = 10): array
    {
        $this->requests[] = [
            'query' => $query,
            'documents' => $documents,
            'limit' => $limit,
        ];

        if ($this->fakeResults !== null) {
            return array_slice($this->fakeResults, 0, max(1, $limit));
        }

        $results = [];
        foreach (array_values($documents) as $index => $document) {
            $text = is_array($document) ? (string) ($document['document'] ?? $document['text'] ?? '') : (string) $document;
            $metadata = is_array($document) ? (array) ($document['metadata'] ?? []) : [];

            $results[] = new RerankResult(
                index: $index,
                document: $text,
                score: $this->score($query, $text),
                metadata: $metadata
            );
        }

        usort($results, static fn (RerankResult $left, RerankResult $right): int => $right->score <=> $left->score);

        return array_slice($results, 0, max(1, $limit));
    }

    public function fake(array $results = []): self
    {
        $this->fakeResults = array_values(array_map(
            fn ($result, $index): RerankResult => $result instanceof RerankResult
                ? $result
                : new RerankResult(
                    index: (int) ($result['index'] ?? $index),
                    document: (string) ($result['document'] ?? ''),
                    score: (float) ($result['score'] ?? 1.0),
                    metadata: (array) ($result['metadata'] ?? [])
                ),
            $results,
            array_keys($results)
        ));

        return $this;
    }

    public function requests(): array
    {
        return $this->requests;
    }

    public function assertReranked(?callable $callback = null): void
    {
        if ($this->requests === []) {
            throw new \RuntimeException('Expected reranking to be requested, but no requests were recorded.');
        }

        if ($callback !== null) {
            foreach ($this->requests as $request) {
                if ($callback($request)) {
                    return;
                }
            }

            throw new \RuntimeException('No recorded reranking request matched the assertion callback.');
        }
    }

    protected function score(string $query, string $document): float
    {
        $queryTerms = $this->terms($query);
        $documentTerms = $this->terms($document);

        if ($queryTerms === [] || $documentTerms === []) {
            return 0.0;
        }

        $intersection = array_intersect($queryTerms, $documentTerms);
        $union = array_unique(array_merge($queryTerms, $documentTerms));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    protected function terms(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($value)) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => strlen($part) > 1)));
    }
}
