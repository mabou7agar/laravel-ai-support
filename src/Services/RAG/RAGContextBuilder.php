<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\DTOs\RAGSource;

class RAGContextBuilder
{
    /**
     * @param array<int, RAGSource> $sources
     */
    public function build(array $sources): array
    {
        $lines = [];
        foreach (array_values($sources) as $index => $source) {
            $label = $source->title ?: ($source->id ?: $source->type);
            $lines[] = "[Source {$index}] {$label}\n{$source->content}";
        }

        $citationGroups = array_map(
            static fn (RAGSource $source): array => array_map(static fn ($citation): array => $citation->toArray(), $source->citations),
            $sources
        );

        return [
            'context' => implode("\n\n", $lines),
            'sources' => array_map(static fn (RAGSource $source): array => $source->toArray(), $sources),
            'citations' => $citationGroups === [] ? [] : array_values(array_merge(...$citationGroups)),
        ];
    }
}
