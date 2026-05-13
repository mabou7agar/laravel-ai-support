<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class GraphVectorLink
{
    public function __construct(
        public readonly string $graphNodeId,
        public readonly string $graphChunkId,
        public readonly string $vectorCollection,
        public readonly string $vectorPointId,
        public readonly string $modelClass,
        public readonly string|int|null $modelId,
        public readonly int $chunkIndex,
        public readonly ?string $sourceNode = null
    ) {}

    public static function fromSearchDocument(
        SearchDocument $document,
        int $chunkIndex = 0,
        ?string $vectorCollection = null,
        ?string $vectorPointId = null
    ): self {
        $graphNodeId = self::entityKey($document->sourceNode, $document->modelClass, $document->modelId);
        $collection = $vectorCollection ?: self::collectionName($document->modelClass);
        $pointId = $vectorPointId ?: self::pointId($document->modelId, $chunkIndex);

        return new self(
            graphNodeId: $graphNodeId,
            graphChunkId: $graphNodeId . '#chunk:' . $chunkIndex,
            vectorCollection: $collection,
            vectorPointId: $pointId,
            modelClass: $document->modelClass,
            modelId: $document->modelId,
            chunkIndex: $chunkIndex,
            sourceNode: $document->sourceNode
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromVectorMetadata(array $metadata): ?self
    {
        $link = is_array($metadata['graph_vector_link'] ?? null) ? $metadata['graph_vector_link'] : $metadata;

        $graphNodeId = (string) ($link['graph_node_id'] ?? $link['entity_key'] ?? '');
        $vectorCollection = (string) ($link['vector_collection'] ?? $link['qdrant_collection'] ?? '');
        $vectorPointId = (string) ($link['vector_point_id'] ?? $link['qdrant_point_id'] ?? '');
        $modelClass = (string) ($link['model_class'] ?? $metadata['model_class'] ?? '');

        if ($graphNodeId === '' || $vectorCollection === '' || $vectorPointId === '' || $modelClass === '') {
            return null;
        }

        $chunkIndex = is_numeric($link['chunk_index'] ?? null) ? (int) $link['chunk_index'] : 0;

        return new self(
            graphNodeId: $graphNodeId,
            graphChunkId: (string) ($link['graph_chunk_id'] ?? ($graphNodeId . '#chunk:' . $chunkIndex)),
            vectorCollection: $vectorCollection,
            vectorPointId: $vectorPointId,
            modelClass: $modelClass,
            modelId: $link['model_id'] ?? $metadata['model_id'] ?? null,
            chunkIndex: $chunkIndex,
            sourceNode: isset($link['source_node']) ? (string) $link['source_node'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'graph_node_id' => $this->graphNodeId,
            'graph_chunk_id' => $this->graphChunkId,
            'vector_collection' => $this->vectorCollection,
            'vector_point_id' => $this->vectorPointId,
            'qdrant_collection' => $this->vectorCollection,
            'qdrant_point_id' => $this->vectorPointId,
            'model_class' => $this->modelClass,
            'model_id' => $this->modelId,
            'chunk_index' => $this->chunkIndex,
            'source_node' => $this->sourceNode,
        ];
    }

    public static function entityKey(?string $sourceNode, string $modelClass, string|int|null $modelId): string
    {
        return implode(':', [
            $sourceNode ?: 'local',
            $modelClass,
            (string) $modelId,
        ]);
    }

    public static function pointId(string|int|null $modelId, int $chunkIndex = 0, bool $chunked = false): string
    {
        $id = (string) $modelId;

        return !$chunked && $chunkIndex === 0 ? $id : $id . '_chunk_' . $chunkIndex;
    }

    public static function collectionName(string $modelClass): string
    {
        if (class_exists($modelClass)) {
            $instance = new $modelClass();
            if (method_exists($instance, 'getVectorCollectionName')) {
                return (string) $instance->getVectorCollectionName();
            }
            if (method_exists($instance, 'getTable')) {
                return (string) config('ai-engine.vector.collection_prefix', 'vec_') . $instance->getTable();
            }
        }

        return (string) config('ai-engine.vector.collection_prefix', 'vec_') . str_replace('\\', '_', $modelClass);
    }
}
