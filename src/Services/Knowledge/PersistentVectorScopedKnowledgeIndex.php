<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Knowledge;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Contracts\ScopedKnowledgeIndex;
use LaravelAIEngine\Contracts\SynchronizesScopedKnowledgeIndex;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\DTOs\ScopedKnowledgeMatch;
use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use RuntimeException;
use Throwable;

final class PersistentVectorScopedKnowledgeIndex implements ScopedKnowledgeIndex, SynchronizesScopedKnowledgeIndex
{
    public function __construct(
        private readonly VectorDriverManager $drivers,
        private readonly EmbeddingService $embeddings,
        private readonly CacheRepository $cache,
        private readonly InMemoryScopedKnowledgeIndex $fallback,
    ) {
    }

    public function search(iterable $documents, string $query, int $limit = 8): array
    {
        $documents = $this->documentsByStorageId($documents);
        if ($documents === [] || trim($query) === '') {
            return [];
        }

        try {
            if ((bool) config('ai-agent.assistant.knowledge_index.vector.sync_on_search', true)) {
                $this->sync(array_values($documents));
            }

            $driver = $this->driver();
            if (!$driver->collectionExists($this->collection())) {
                return $this->fallback($documents, $query, $limit);
            }

            $results = $driver->search(
                $this->collection(),
                $this->embeddings->embed($query),
                max(1, min(50, $limit)),
                max(0.0, min(1.0, (float) config(
                    'ai-agent.assistant.knowledge_index.vector.threshold',
                    0.25,
                ))),
                $this->filters(array_keys($documents)),
            );

            $matches = [];
            foreach ($results as $result) {
                $metadata = (array) ($result['metadata'] ?? []);
                $storageId = trim((string) (
                    $metadata['knowledge_storage_id']
                    ?? $metadata['model_id']
                    ?? $result['id']
                    ?? ''
                ));
                if ($storageId === '' || !isset($documents[$storageId])) {
                    continue;
                }

                // Fail closed even when a custom vector driver ignores filters.
                $matches[] = new ScopedKnowledgeMatch(
                    $documents[$storageId],
                    (float) ($result['score'] ?? 0.0),
                );
                if (count($matches) >= max(1, $limit)) {
                    break;
                }
            }

            return $matches;
        } catch (Throwable $exception) {
            Log::channel('ai-engine')->warning('Persistent scoped knowledge search fell back to memory.', [
                'driver' => $this->drivers->getDefaultDriver(),
                'exception' => $exception::class,
            ]);

            return $this->fallback($documents, $query, $limit);
        }
    }

    public function sync(iterable $documents, bool $force = false): int
    {
        $documents = $this->documentsByStorageId($documents);
        if ($documents === []) {
            return 0;
        }

        $driver = $this->driver();
        $collection = $this->collection();
        $collectionExists = $driver->collectionExists($collection);
        $changed = [];

        foreach ($documents as $storageId => $document) {
            $fingerprint = $this->fingerprint($document);
            if ($force || !$collectionExists || $this->cache->get($this->cacheKey($storageId)) !== $fingerprint) {
                $changed[$storageId] = ['document' => $document, 'fingerprint' => $fingerprint];
            }
        }
        if ($changed === []) {
            return 0;
        }

        $indexed = 0;
        foreach (array_chunk($changed, $this->batchSize(), true) as $batch) {
            $texts = array_map(
                fn (array $entry): string => $this->embeddingText($entry['document']),
                array_values($batch),
            );
            $vectors = $this->embeddings->embedBatch($texts);
            if (count($vectors) !== count($batch)) {
                throw new RuntimeException('The embedding provider returned an incomplete scoped-knowledge batch.');
            }

            if (!$collectionExists) {
                $dimensions = count($vectors[0] ?? []);
                if ($dimensions < 1 || !$driver->createCollection($collection, $dimensions)) {
                    throw new RuntimeException(sprintf(
                        'Unable to create scoped-knowledge vector collection [%s].',
                        $collection,
                    ));
                }
                $collectionExists = true;
            }

            $points = [];
            foreach (array_values($batch) as $index => $entry) {
                /** @var ScopedKnowledgeDocument $document */
                $document = $entry['document'];
                $storageId = $this->storageId($document);
                $points[] = [
                    'id' => $storageId,
                    'vector' => $vectors[$index],
                    'metadata' => $this->metadata($document, $storageId, $entry['fingerprint']),
                ];
            }
            if (!$driver->upsert($collection, $points)) {
                throw new RuntimeException(sprintf(
                    'Unable to persist scoped knowledge in vector collection [%s].',
                    $collection,
                ));
            }

            foreach ($batch as $storageId => $entry) {
                $this->cache->forever($this->cacheKey($storageId), $entry['fingerprint']);
                $indexed++;
            }
        }

        return $indexed;
    }

    /** @param iterable<ScopedKnowledgeDocument> $documents @return array<string, ScopedKnowledgeDocument> */
    private function documentsByStorageId(iterable $documents): array
    {
        $values = [];
        $maximum = max(1, (int) config('ai-agent.assistant.knowledge_index.max_documents', 2000));
        foreach ($documents as $document) {
            if (!$document instanceof ScopedKnowledgeDocument || $document->id === '' || $document->text === '') {
                continue;
            }
            $values[$this->storageId($document)] = $document;
            if (count($values) >= $maximum) {
                break;
            }
        }

        return $values;
    }

    private function driver(): VectorDriverInterface
    {
        return $this->drivers->driver();
    }

    private function collection(): string
    {
        $collection = trim((string) config(
            'ai-agent.assistant.knowledge_index.vector.collection',
            'ai_assistant_scoped_knowledge',
        ));
        if ($collection === '' || preg_match('/^[A-Za-z0-9_-]+$/', $collection) !== 1) {
            throw new RuntimeException('The scoped-knowledge vector collection name is invalid.');
        }

        return $collection;
    }

    private function batchSize(): int
    {
        return max(1, min(100, (int) config(
            'ai-agent.assistant.knowledge_index.vector.batch_size',
            50,
        )));
    }

    private function storageId(ScopedKnowledgeDocument $document): string
    {
        return hash('sha256', implode('|', [
            $document->scope->value,
            $document->tenantId ?? '',
            $document->workspaceId ?? '',
            $document->userId ?? '',
            $document->id,
        ]));
    }

    private function fingerprint(ScopedKnowledgeDocument $document): string
    {
        return hash('sha256', (string) json_encode(
            $document->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function cacheKey(string $storageId): string
    {
        return 'ai-engine:scoped-knowledge:'.$this->drivers->getDefaultDriver().':'
            .$this->collection().':'.$storageId;
    }

    private function embeddingText(ScopedKnowledgeDocument $document): string
    {
        $title = is_scalar($document->metadata['title'] ?? null)
            ? trim((string) $document->metadata['title'])
            : '';
        $keywords = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            (array) ($document->metadata['keywords'] ?? []),
        )));

        return trim(implode("\n", array_filter([
            $title,
            $document->text,
            implode(' ', $keywords),
        ])));
    }

    /** @return array<string, scalar|null> */
    private function metadata(
        ScopedKnowledgeDocument $document,
        string $storageId,
        string $fingerprint,
    ): array {
        $scalar = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';

        return array_filter([
            'model_class' => ScopedKnowledgeDocument::class,
            'model_id' => $storageId,
            'knowledge_storage_id' => $storageId,
            'knowledge_document_id' => $document->id,
            'knowledge_scope' => $document->scope->value,
            'tenant_id' => $document->tenantId,
            'workspace_id' => $document->workspaceId,
            'user_id' => $document->userId,
            'title' => $scalar($document->metadata['title'] ?? null),
            'url' => $scalar($document->metadata['url'] ?? null),
            'fingerprint' => $fingerprint,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param list<string> $storageIds @return array<string, mixed> */
    private function filters(array $storageIds): array
    {
        return match ($this->drivers->getDefaultDriver()) {
            'pinecone' => ['knowledge_storage_id' => ['$in' => $storageIds]],
            default => ['knowledge_storage_id' => $storageIds],
        };
    }

    /** @param array<string, ScopedKnowledgeDocument> $documents */
    private function fallback(array $documents, string $query, int $limit): array
    {
        return $this->fallback->search(array_values($documents), $query, $limit);
    }
}
