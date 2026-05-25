<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Support\Str;

class Neo4jRetrievalScoringService
{
    /**
     * @return array<int, string>
     */
    public function expandQueryTerms(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $terms = [$query];

        $quoted = trim($query, "\"' ");
        if ($quoted !== '' && $quoted !== $query) {
            $terms[] = $quoted;
        }

        $words = preg_split('/\s+/', $query) ?: [];
        if (count($words) > 1) {
            $terms[] = implode(' ', array_slice($words, 0, 2));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($term): string => trim((string) $term),
            $terms
        ))));
    }

    public function calculateScore(?string $query, ?string $chunkText, ?string $title, ?string $summary): float
    {
        $query = Str::lower(trim((string) $query));
        if ($query === '') {
            return 1.0;
        }

        $haystack = Str::lower(trim(implode(' ', array_filter([$title, $summary, $chunkText]))));
        if ($haystack === '') {
            return 0.5;
        }

        if (str_contains($haystack, $query)) {
            return 0.98;
        }

        preg_match_all('/[a-z0-9]+/i', $query, $queryMatches);
        $queryTerms = array_values(array_filter(
            $queryMatches[0] ?? [],
            static fn (string $term): bool => !in_array($term, [
                'a', 'an', 'the', 'is', 'are', 'was', 'were', 'on', 'in', 'at', 'to', 'for',
                'of', 'and', 'or', 'me', 'show', 'tell', 'what', 'which', 'latest', 'status',
            ], true)
        ));
        if ($queryTerms === []) {
            return 0.5;
        }

        $hits = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($haystack, $term)) {
                $hits++;
            }
        }

        $ratio = $hits / count($queryTerms);

        return round(max(0.2, min(0.95, 0.2 + (0.75 * $ratio))), 4);
    }

    public function blendScore(?float $vectorScore, float $lexicalScore): float
    {
        if ($vectorScore === null) {
            return $lexicalScore;
        }

        $vectorScore = max(0.0, min(1.0, $vectorScore));
        $lexicalScore = max(0.0, min(1.0, $lexicalScore));

        if ((bool) config('ai-engine.vector.testing.use_fake_embeddings', false)) {
            return round(max($lexicalScore, ($vectorScore * 0.4) + ($lexicalScore * 0.6)), 4);
        }

        return round(($vectorScore * 0.7) + ($lexicalScore * 0.3), 4);
    }
}
