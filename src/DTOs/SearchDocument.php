<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class SearchDocument
{
    /**
     * @param string|int|null $modelId
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $searchableAttributes
     * @param array<int, string> $keywords
     * @param array<string, mixed> $object
     * @param array<string, mixed> $accessScope
     * @param array<int, array<string, mixed>> $relations
     * @param array<int, array<string, mixed>> $chunks
     */
    public function __construct(
        public string $modelClass,
        public string|int|null $modelId,
        public string $content,
        public ?string $title = null,
        public ?string $ragContent = null,
        public ?string $ragSummary = null,
        public ?string $ragDetail = null,
        public ?string $listPreview = null,
        public array $metadata = [],
        public array $searchableAttributes = [],
        public array $keywords = [],
        public array $object = [],
        public array $accessScope = [],
        public array $relations = [],
        public array $chunks = [],
        public ?string $sourceNode = null,
        public ?string $appSlug = null,
        public ?string $scopeType = null,
        public string|int|null $scopeId = null,
        public ?string $scopeLabel = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?object $model = null): self
    {
        $modelClass = (string) ($data['model_class'] ?? ($model ? get_class($model) : ''));
        $modelId = $data['model_id'] ?? ($model->id ?? null);
        $content = trim((string) ($data['content'] ?? ''));

        $chunks = [];
        foreach (($data['chunks'] ?? []) as $index => $chunk) {
            if (is_string($chunk)) {
                $chunk = ['content' => $chunk];
            }

            if (!is_array($chunk)) {
                continue;
            }

            $chunkContent = trim((string) ($chunk['content'] ?? ''));
            if ($chunkContent === '') {
                continue;
            }

            $chunks[] = array_merge([
                'content' => $chunkContent,
                'index' => is_numeric($chunk['index'] ?? null) ? (int) $chunk['index'] : (int) $index,
            ], $chunk);
        }

        return new self(
            modelClass: $modelClass,
            modelId: $modelId,
            content: $content,
            title: isset($data['title']) ? trim((string) $data['title']) : null,
            ragContent: isset($data['rag_content']) ? trim((string) $data['rag_content']) : null,
            ragSummary: isset($data['rag_summary']) ? trim((string) $data['rag_summary']) : null,
            ragDetail: isset($data['rag_detail']) ? trim((string) $data['rag_detail']) : null,
            listPreview: isset($data['list_preview']) ? trim((string) $data['list_preview']) : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            searchableAttributes: is_array($data['searchable_attributes'] ?? null) ? $data['searchable_attributes'] : [],
            keywords: array_values(array_filter(array_map(
                static fn ($keyword): ?string => is_scalar($keyword) ? trim((string) $keyword) : null,
                is_array($data['keywords'] ?? null) ? $data['keywords'] : []
            ))),
            object: is_array($data['object'] ?? null) ? $data['object'] : [],
            accessScope: is_array($data['access_scope'] ?? null) ? $data['access_scope'] : [],
            relations: array_values(array_filter(
                is_array($data['relations'] ?? null) ? $data['relations'] : [],
                static fn ($relation): bool => is_array($relation)
            )),
            chunks: $chunks,
            sourceNode: isset($data['source_node']) ? trim((string) $data['source_node']) : null,
            appSlug: isset($data['app_slug']) ? trim((string) $data['app_slug']) : null,
            scopeType: isset($data['scope_type']) ? trim((string) $data['scope_type']) : null,
            scopeId: $data['scope_id'] ?? null,
            scopeLabel: isset($data['scope_label']) ? trim((string) $data['scope_label']) : null,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizedChunks(): array
    {
        if ($this->chunks !== []) {
            return $this->chunks;
        }

        return [[
            'content' => $this->content,
            'index' => 0,
        ]];
    }

    public function primaryChunk(): string
    {
        $chunks = $this->normalizedChunks();

        return (string) ($chunks[0]['content'] ?? $this->content);
    }

    /**
     * @return array<string, mixed>
     */
    public function entityRef(): array
    {
        return array_filter([
            'model_id' => $this->modelId,
            'model_class' => $this->modelClass,
            'model_type' => class_basename($this->modelClass),
            'source_node' => $this->sourceNode,
            'app_slug' => $this->appSlug,
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId,
            'scope_label' => $this->scopeLabel,
            'canonical_user_id' => $this->accessScope['canonical_user_id'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
