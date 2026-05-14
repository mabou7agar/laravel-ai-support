<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final class RAGSource
{
    /**
     * @param array<int, RAGCitation> $citations
     */
    public function __construct(
        public readonly string $type,
        public readonly string $content,
        public readonly ?string $id = null,
        public readonly ?string $title = null,
        public readonly ?float $score = null,
        public readonly array $metadata = [],
        public readonly array $citations = []
    ) {}

    public static function fromMixed(mixed $source, ?string $type = null): self
    {
        $data = self::dataFromMixed($source);
        $metadata = array_merge(
            is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            is_array($data['vector_metadata'] ?? null) ? $data['vector_metadata'] : []
        );

        $sourceType = $type
            ?? (string) ($data['source_type'] ?? $metadata['source_type'] ?? $metadata['hybrid_sources'][0] ?? 'vector');

        return new self(
            type: $sourceType,
            content: self::firstString([
                $data['matched_chunk_text'] ?? null,
                $metadata['chunk_text'] ?? null,
                $data['content'] ?? null,
                $data['text'] ?? null,
                $data['chunk'] ?? null,
                $data['summary'] ?? null,
                $metadata['chunk_preview'] ?? null,
                $metadata['object']['summary'] ?? null,
                $data['graph_object']['summary'] ?? null,
            ]),
            id: self::firstNullableString([
                $data['id'] ?? null,
                $metadata['id'] ?? null,
                $metadata['model_id'] ?? null,
                $metadata['entity_ref']['model_id'] ?? null,
            ]),
            title: self::firstNullableString([
                $data['title'] ?? null,
                $metadata['title'] ?? null,
                $metadata['object']['title'] ?? null,
                $data['graph_object']['title'] ?? null,
                $metadata['model'] ?? null,
            ]),
            score: isset($data['score']) ? (float) $data['score'] : (isset($data['vector_score']) ? (float) $data['vector_score'] : null),
            metadata: $metadata,
            citations: self::citationsFrom($data, $sourceType, $metadata)
        );
    }

    public static function providerFileSearch(array $citation): self
    {
        return new self(
            type: 'provider_file_search',
            content: (string) ($citation['text'] ?? $citation['quote'] ?? ''),
            id: isset($citation['file_id']) ? (string) $citation['file_id'] : null,
            title: $citation['filename'] ?? $citation['title'] ?? null,
            metadata: $citation,
            citations: [RAGCitation::fromArray([
                'type' => 'provider_file_search',
                'title' => $citation['filename'] ?? $citation['title'] ?? null,
                'url' => $citation['url'] ?? null,
                'source_id' => $citation['file_id'] ?? null,
                'metadata' => $citation,
            ])]
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'content' => $this->content,
            'id' => $this->id,
            'title' => $this->title,
            'score' => $this->score,
            'metadata' => $this->metadata,
            'citations' => array_map(static fn (RAGCitation $citation): array => $citation->toArray(), $this->citations),
        ];
    }

    /**
     * @return array<int, RAGCitation>
     */
    private static function citationsFrom(array $data, string $type, array $metadata = []): array
    {
        $citations = $data['citations'] ?? $data['sources'] ?? [];
        if (!is_array($citations) || $citations === []) {
            return [RAGCitation::fromArray([
                'type' => $type,
                'title' => $data['title']
                    ?? ($data['citation_title'] ?? null)
                    ?? ($metadata['title'] ?? null)
                    ?? ($metadata['object']['title'] ?? null)
                    ?? ($data['graph_object']['title'] ?? null),
                'url' => $data['url'] ?? ($data['citation_url'] ?? null),
                'source_id' => $data['id']
                    ?? ($metadata['model_id'] ?? null)
                    ?? ($metadata['entity_ref']['model_id'] ?? null),
                'metadata' => $metadata,
            ])];
        }

        return array_values(array_map(static fn (mixed $citation): RAGCitation => RAGCitation::fromArray((array) $citation), $citations));
    }

    private static function dataFromMixed(mixed $source): array
    {
        if (is_array($source)) {
            return $source;
        }

        if ($source instanceof Arrayable) {
            return $source->toArray();
        }

        if ($source instanceof JsonSerializable) {
            $json = $source->jsonSerialize();

            return is_array($json) ? $json : [];
        }

        if (is_object($source) && method_exists($source, 'toArray')) {
            $array = $source->toArray();

            return is_array($array) ? $array : [];
        }

        return is_object($source) ? get_object_vars($source) : [];
    }

    private static function firstString(array $values): string
    {
        return self::firstNullableString($values) ?? '';
    }

    private static function firstNullableString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
