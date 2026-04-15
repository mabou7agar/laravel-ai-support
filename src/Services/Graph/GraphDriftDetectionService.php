<?php

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;

class GraphDriftDetectionService
{
    public function __construct(
        protected SearchDocumentBuilder $documentBuilder,
        protected Neo4jHttpTransport $transport,
        protected Neo4jGraphSyncService $syncService,
        protected ?RAGCollectionDiscovery $discovery = null
    ) {
        if ($this->discovery === null && app()->bound(RAGCollectionDiscovery::class)) {
            $this->discovery = app(RAGCollectionDiscovery::class);
        }
    }

    /**
     * @param array<int, string> $models
     * @return array{models:array<int, array<string, mixed>>, totals:array<string,int>}
     */
    public function scan(array $models = [], ?int $limit = null): array
    {
        $models = $models !== [] ? $models : $this->discoverModels();
        $rows = [];
        $totals = [
            'local_entities' => 0,
            'graph_entities' => 0,
            'missing_in_graph' => 0,
            'stale_in_graph' => 0,
        ];

        foreach ($models as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            $local = $this->localEntityMap($modelClass, $limit);
            $graph = $this->graphEntityKeys($modelClass);

            $missing = array_values(array_diff(array_keys($local), $graph));
            $stale = array_values(array_diff($graph, array_keys($local)));

            $rows[] = [
                'model' => $modelClass,
                'local_total' => count($local),
                'graph_total' => count($graph),
                'missing_in_graph' => count($missing),
                'stale_in_graph' => count($stale),
                'missing_entity_keys' => $missing,
                'stale_entity_keys' => $stale,
                'missing_model_ids' => array_values(array_filter(array_map(
                    static fn (string $entityKey): mixed => $local[$entityKey]['model_id'] ?? null,
                    $missing
                ), static fn ($value): bool => $value !== null)),
            ];

            $totals['local_entities'] += count($local);
            $totals['graph_entities'] += count($graph);
            $totals['missing_in_graph'] += count($missing);
            $totals['stale_in_graph'] += count($stale);
        }

        return [
            'models' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * @param array{models:array<int, array<string, mixed>>, totals:array<string,int>} $report
     * @return array<string, int>
     */
    public function repair(array $report, bool $prune = false): array
    {
        $published = 0;
        $pruned = 0;

        foreach ((array) ($report['models'] ?? []) as $row) {
            $modelClass = $row['model'] ?? null;
            if (!is_string($modelClass) || !class_exists($modelClass)) {
                continue;
            }

            $missingIds = array_values(array_filter((array) ($row['missing_model_ids'] ?? []), static fn ($value): bool => $value !== null && $value !== ''));
            if ($missingIds !== []) {
                $records = $modelClass::query()->whereKey($missingIds)->get();
                foreach ($records as $record) {
                    if ($record instanceof Model && $this->syncService->publish($record)) {
                        $published++;
                    }
                }
            }

            if ($prune) {
                foreach ((array) ($row['stale_entity_keys'] ?? []) as $entityKey) {
                    if (!is_string($entityKey) || trim($entityKey) === '') {
                        continue;
                    }

                    $result = $this->transport->executeStatement([
                        'statement' => <<<'CYPHER'
MATCH (e:Entity {entity_key: $entity_key})
OPTIONAL MATCH (e)-[:HAS_CHUNK]->(c:Chunk)
DETACH DELETE e, c
RETURN 1 AS deleted
CYPHER,
                        'parameters' => ['entity_key' => $entityKey],
                    ]);

                    if ($result['success'] ?? false) {
                        $pruned++;
                    }
                }
            }
        }

        if ($published > 0 || $pruned > 0) {
            app(GraphKnowledgeBaseService::class)->bumpGraphVersion();
        }

        return [
            'published' => $published,
            'pruned' => $pruned,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function discoverModels(): array
    {
        if ($this->discovery === null) {
            return [];
        }

        return $this->discovery->discover(useCache: false, includeFederated: false);
    }

    /**
     * @return array<string, array{model_id:mixed}>
     */
    protected function localEntityMap(string $modelClass, ?int $limit): array
    {
        $query = $modelClass::query();
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $map = [];
        foreach ($query->get() as $record) {
            if (!$record instanceof Model) {
                continue;
            }

            $document = $this->documentBuilder->build($record);
            $entityKey = implode(':', [
                $document->sourceNode ?: 'local',
                $document->modelClass,
                (string) $document->modelId,
            ]);

            $map[$entityKey] = [
                'model_id' => $document->modelId,
            ];
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    protected function graphEntityKeys(string $modelClass): array
    {
        $result = $this->transport->executeStatement([
            'statement' => <<<'CYPHER'
MATCH (e:Entity)
WHERE e.model_class = $model_class
RETURN e.entity_key AS entity_key
ORDER BY entity_key ASC
CYPHER,
            'parameters' => [
                'model_class' => $modelClass,
            ],
        ]);

        if (!($result['success'] ?? false)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $row): ?string => isset($row['entity_key']) ? (string) $row['entity_key'] : null,
            (array) ($result['rows'] ?? [])
        )));
    }
}
