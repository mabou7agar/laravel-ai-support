<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use LaravelAIEngine\Services\Summary\EntitySummaryService;

class RAGStructuredDataService
{
    public function __construct(
        protected RAGDecisionStateService $stateService,
        protected RAGDecisionPolicy $policy,
        protected ?RAGAggregateService $aggregateService = null,
        protected ?LocaleResourceService $locale = null,
        protected ?EntitySummaryService $entitySummaryService = null,
        protected ?Neo4jRetrievalService $graphRetrieval = null,
        protected ?RAGModelScopeGuard $scopeGuard = null
    ) {
        $this->scopeGuard = $this->scopeGuard ?? new RAGModelScopeGuard();
        $this->aggregateService = $aggregateService ?? new RAGAggregateService($this->policy, $this->scopeGuard);
        $this->locale = $locale ?? (
            app()->bound(LocaleResourceService::class)
                ? app(LocaleResourceService::class)
                : new LocaleResourceService()
        );
        $this->entitySummaryService = $entitySummaryService ?? new EntitySummaryService();
        $this->graphRetrieval = $graphRetrieval ?? (
            app()->bound(Neo4jRetrievalService::class)
                ? app(Neo4jRetrievalService::class)
                : null
        );
    }

    public function query(array $params, $userId, array $options, array $dependencies, int $page = 1, ?string $message = null): array
    {
        $modelName = $params['model'] ?? null;
        $sessionId = $options['session_id'] ?? null;

        if (!$modelName) {
            return ['success' => false, 'error' => 'No model specified'];
        }

        $modelClass = $dependencies['findModelClass']($modelName, $options);
        if (!$modelClass) {
            return [
                'success' => false,
                'error' => "Model {$modelName} not found locally",
                'should_route_to_node' => true,
            ];
        }

        if (!class_exists($modelClass)) {
            Log::channel('ai-engine')->info('Model not found locally, should route to remote node', [
                'model' => $modelName,
                'model_class' => $modelClass,
            ]);

            return [
                'success' => false,
                'error' => "Model {$modelName} not available locally",
                'should_route_to_node' => true,
            ];
        }

        try {
            $query = $modelClass::query();
            $instance = new $modelClass();
            $table = $instance->getTable();
            $filterConfig = $dependencies['getFilterConfigForModel']($modelClass);

            if (!empty($filterConfig['eager_load'])) {
                $query->with($filterConfig['eager_load']);
            }

            $scope = $this->scopeGuard->apply($query, $modelClass, $userId, $filterConfig, $options);
            if (($scope['allowed'] ?? false) !== true) {
                return [
                    'success' => false,
                    'error' => $scope['error'] ?? 'Structured RAG access is blocked for this model.',
                    'tool' => 'db_query',
                    'scope_blocked' => true,
                ];
            }
            $query = $scope['query'];

            $filters = $params['filters'] ?? [];
            $query = $dependencies['applyFilters']($query, $filters, $modelClass, $options);

            $perPage = $this->policy->itemsPerPage();
            $totalCount = (clone $query)->count();
            $totalPages = max(1, (int) ceil($totalCount / $perPage));
            $offset = max(0, ($page - 1) * $perPage);
            $items = $query->latest()->skip($offset)->take($perPage)->get();

            if ($items->isEmpty()) {
                if ($page > 1) {
                    return [
                        'success' => true,
                        'response' => $this->translate(
                            'ai-engine::messages.agent.no_more_results',
                            "No more results to show. You've seen all :count results.",
                            ['count' => $totalCount]
                        ),
                        'tool' => 'db_query',
                        'fast_path' => true,
                        'count' => 0,
                        'page' => $page,
                        'total_pages' => $totalPages,
                        'total_count' => $totalCount,
                    ];
                }

                return [
                    'success' => true,
                    'response' => $this->translate(
                        'ai-engine::messages.agent.no_results_found',
                        'No results found.'
                    ),
                    'tool' => 'db_query',
                    'fast_path' => true,
                    'count' => 0,
                ];
            }

            if ($sessionId) {
                $startPosition = $offset + 1;
                $entityIds = $items->pluck('id')->toArray();
                $documentBuilder = app(\LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder::class);
                $entityData = $items->map(function ($item, $index) use ($startPosition) {
                    $position = $startPosition + $index;
                    $summary = method_exists($item, 'toRAGSummary') ? $item->toRAGSummary() : (string) $item;

                    return [
                        'position' => $position,
                        'id' => $item->id,
                        'summary' => $summary,
                    ];
                })->toArray();

                $this->stateService->storeQueryState($sessionId, [
                    'model' => $modelName,
                    'model_class' => $modelClass,
                    'filters' => $filters,
                    'user_id' => $userId,
                    'options' => $options,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'total_count' => $totalCount,
                    'entity_ids' => $entityIds,
                    'entity_data' => $entityData,
                    'entity_refs' => $items->map(fn ($item) => $documentBuilder->buildEntityRef($item))->toArray(),
                    'objects' => $items->map(fn ($item) => $documentBuilder->buildGraphObject($item))->toArray(),
                    'start_position' => $startPosition,
                    'end_position' => $offset + $items->count(),
                    'current_page' => $page,
                ]);
            }

            $isSingleRecord = $totalCount === 1 && !empty($filters['id']);

            if ($isSingleRecord) {
                $item = $items->first();
                $this->entitySummaryService?->summaryForDisplay($item, (string) ($options['locale'] ?? app()->getLocale()));
                if (method_exists($item, 'toRAGDetail')) {
                    $response = $item->toRAGDetail();
                } elseif (method_exists($item, 'toRAGContent')) {
                    $response = $item->toRAGContent();
                } elseif (method_exists($item, '__toString')) {
                    $response = $item->__toString();
                } else {
                    $response = json_encode($item->toArray(), JSON_PRETTY_PRINT);
                }
            } else {
                $startNum = $offset + 1;
                $endNum = $offset + $items->count();
                $modelLabel = $this->resolveModelLabel($modelClass, $modelName);
                $response = "**{$modelLabel}** (showing {$startNum}-{$endNum} of {$totalCount}):\n\n";

                foreach ($items as $index => $item) {
                    $num = $offset + $index + 1;
                    $locale = (string) ($options['locale'] ?? app()->getLocale());
                    if (method_exists($item, 'toRAGListPreview')) {
                        $preview = trim((string) $item->toRAGListPreview($locale));
                        if ($preview !== '') {
                            $response .= $this->formatNumberedPreview($num, $preview) . "\n\n";
                            continue;
                        }
                    }
                    $summaryText = config('ai-engine.entity_summaries.use_in_list_responses', true)
                        ? $this->entitySummaryService?->summaryForDisplay($item, $locale)
                        : null;
                    if (is_string($summaryText) && trim($summaryText) !== '') {
                        $response .= "{$num}. {$summaryText}\n\n";
                    } elseif (method_exists($item, 'toRAGContent')) {
                        $response .= "{$num}. " . $item->toRAGContent() . "\n\n";
                    } elseif (method_exists($item, '__toString')) {
                        $response .= "{$num}. " . $item->__toString() . "\n\n";
                    } else {
                        $response .= "{$num}. " . $this->formatModelPreview($item) . "\n\n";
                    }
                }
            }

            $metadata = [
                'entity_ids' => $items->pluck('id')->toArray(),
                'entity_refs' => $items->map(fn ($item) => app(\LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder::class)->buildEntityRef($item))->toArray(),
                'entity_type' => $modelName,
            ];

            $graphContext = $this->buildStructuredGraphContext($message, $modelClass, $items, $userId, $options);
            if ($graphContext !== null) {
                if ($graphContext['context_text'] !== '') {
                    $response .= "\n\nRelated graph context:\n" . $graphContext['context_text'];
                }

                $metadata = array_merge($metadata, [
                    'sources' => $graphContext['sources'],
                    'graph_planned' => $graphContext['graph_planned'],
                    'planner_strategy' => $graphContext['planner_strategy'],
                    'planner_query_kind' => $graphContext['planner_query_kind'],
                ]);
            }

            if ($page < $totalPages) {
                $response .= "\n---\n*Say \"show more\" or \"next\" to see more results.*";
            }

            return [
                'success' => true,
                'response' => trim($response),
                'tool' => 'db_query',
                'fast_path' => true,
                'count' => $items->count(),
                'page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'has_more' => $page < $totalPages,
                'metadata' => $metadata,
                'entity_ids' => $metadata['entity_ids'],
                'entity_refs' => $metadata['entity_refs'],
                'entity_type' => $metadata['entity_type'],
                'items' => $items->toArray(),
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_query failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($this->isMissingTableException($e)) {
                return [
                    'success' => false,
                    'error' => "Model {$modelName} table is not available locally",
                    'should_route_to_node' => true,
                ];
            }

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function formatNumberedPreview(int $number, string $preview): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($preview)) ?: [];
        if ($lines === []) {
            return "{$number}.";
        }

        $first = array_shift($lines);
        $formatted = "{$number}. {$first}";
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }
            $formatted .= "\n   {$trimmed}";
        }

        return $formatted;
    }

    protected function formatModelPreview(mixed $item): string
    {
        $values = method_exists($item, 'toArray') ? (array) $item->toArray() : (array) $item;
        $values = $this->flattenPreviewValues($values);

        if ($values === []) {
            return class_basename(is_object($item) ? $item::class : 'record');
        }

        return collect($values)
            ->take(6)
            ->map(fn (mixed $value, string|int $key): string => Str::headline((string) $key) . ': ' . $this->previewValue($value))
            ->implode('; ');
    }

    /**
     * @param array<string|int, mixed> $values
     * @return array<string|int, mixed>
     */
    protected function flattenPreviewValues(array $values): array
    {
        $preferred = [
            'invoice_number',
            'proposal_number',
            'ticket_id',
            'code',
            'sku',
            'name',
            'title',
            'company_name',
            'status',
            'invoice_date',
            'due_date',
            'total_amount',
            'balance_amount',
            'created_at',
        ];

        $result = [];
        foreach ($preferred as $key) {
            if (array_key_exists($key, $values) && $this->isPreviewScalar($values[$key])) {
                $result[$key] = $values[$key];
            }
        }

        foreach ($values as $key => $value) {
            if (isset($result[$key]) || !$this->isPreviewScalar($value)) {
                continue;
            }

            $keyString = (string) $key;
            if (preg_match('/(password|secret|token|key|credential|remember)/i', $keyString) === 1) {
                continue;
            }

            $result[$key] = $value;
            if (count($result) >= 6) {
                break;
            }
        }

        return $result;
    }

    protected function isPreviewScalar(mixed $value): bool
    {
        return $value === null || is_scalar($value) || $value instanceof \DateTimeInterface;
    }

    protected function previewValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return Str::limit((string) $value, 80);
    }

    protected function shouldEnrichWithGraph(?string $message, array $options): bool
    {
        if ($message === null || trim($message) === '') {
            return false;
        }

        if ($this->graphRetrieval === null || !$this->graphRetrieval->enabled()) {
            return false;
        }

        $normalized = strtolower(trim($message));

        return preg_match('/\b(related|relationship|connected|context|owner|owns|dependency|dependencies|linked|who is involved|what changed|what happened)\b/i', $normalized) === 1
            || (($options['preclassified_route_mode'] ?? null) === 'structured_query' && preg_match('/\b(with|for|around)\b/i', $normalized) === 1);
    }

    protected function buildStructuredGraphContext(?string $message, string $modelClass, $items, $userId, array $options): ?array
    {
        if (!$this->shouldEnrichWithGraph($message, $options) || $items->isEmpty()) {
            return null;
        }

        $documentBuilder = app(\LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder::class);
        $entityRefs = $items->map(fn ($item) => $documentBuilder->buildEntityRef($item))->filter()->values()->all();
        if ($entityRefs === []) {
            return null;
        }

        $collections = array_values(array_filter(
            (array) ($options['rag_collections'] ?? [$modelClass]),
            static fn ($collection): bool => is_string($collection) && trim($collection) !== ''
        ));

        $context = $this->graphRetrieval->retrieveRelevantContext(
            [$message],
            $collections,
            min(3, max(1, count($entityRefs))),
            array_merge($options, [
                'last_entity_list' => [
                    'entity_type' => class_basename($modelClass),
                    'entity_refs' => $entityRefs,
                ],
            ]),
            $userId
        );

        if ($context->isEmpty()) {
            return null;
        }

        $sources = $context->map(function ($item): array {
            $meta = is_array($item->vector_metadata ?? null) ? $item->vector_metadata : [];

            return [
                'model_id' => $meta['model_id'] ?? $item->id ?? null,
                'model_class' => $meta['model_class'] ?? null,
                'model_type' => $meta['model_type'] ?? null,
                'title' => $item->title ?? null,
                'entity_ref' => $meta['entity_ref'] ?? null,
                'object' => $meta['object'] ?? [],
                'graph_planned' => (bool) ($meta['graph_planned'] ?? false),
                'planner_strategy' => $meta['planner_strategy'] ?? null,
                'planner_query_kind' => $meta['planner_query_kind'] ?? null,
                'relation_path' => $meta['relation_path'] ?? null,
                'path_length' => $meta['path_length'] ?? null,
            ];
        })->values()->all();

        $contextText = $context->map(function ($item): string {
            $title = $item->title ?? $item->name ?? ('Entity #' . ($item->id ?? '?'));
            $path = is_array($item->vector_metadata['relation_path'] ?? null)
                ? implode(' -> ', $item->vector_metadata['relation_path'])
                : null;
            $summary = trim((string) ($item->matched_chunk_text ?? $item->content ?? ''));

            return $path
                ? "- {$title} ({$path}): {$summary}"
                : "- {$title}: {$summary}";
        })->implode("\n");

        return [
            'context_text' => $contextText,
            'sources' => $sources,
            'graph_planned' => collect($sources)->contains(static fn (array $source): bool => ($source['graph_planned'] ?? false) === true),
            'planner_strategy' => collect($sources)->pluck('planner_strategy')->filter()->first(),
            'planner_query_kind' => collect($sources)->pluck('planner_query_kind')->filter()->first(),
        ];
    }

    protected function resolveModelLabel(string $modelClass, string $fallbackModelName): string
    {
        $displayName = '';

        if (class_exists($modelClass)) {
            try {
                $instance = new $modelClass();
                if (method_exists($instance, 'getRAGDisplayName')) {
                    $displayName = trim((string) $instance->getRAGDisplayName());
                }
            } catch (\Throwable) {
                $displayName = '';
            }
        }

        if ($displayName === '') {
            $displayName = Str::headline(str_replace('_', ' ', (string) $fallbackModelName));
        }

        return Str::plural($displayName);
    }

    public function queryNext(array $params, $userId, array $options, callable $queryHandler): array
    {
        $sessionId = $options['session_id'] ?? null;
        if (!$sessionId) {
            return [
                'success' => false,
                'error' => 'No session ID provided for pagination.',
                'tool' => 'db_query_next',
            ];
        }

        $state = $this->stateService->getQueryState($sessionId);
        if (empty($state)) {
            return [
                'success' => false,
                'error' => 'No previous query to continue. Please make a query first.',
                'tool' => 'db_query_next',
            ];
        }

        $nextPage = ($state['page'] ?? 1) + 1;
        if ($nextPage > ($state['total_pages'] ?? 1)) {
            return [
                'success' => true,
                'response' => $this->translate(
                    'ai-engine::messages.agent.reached_end_of_results',
                    "You've reached the end. All :count results have been shown.",
                    ['count' => (int) ($state['total_count'] ?? 0)]
                ),
                'tool' => 'db_query_next',
                'fast_path' => true,
                'count' => 0,
                'page' => $state['page'],
                'total_pages' => $state['total_pages'],
            ];
        }

        return $queryHandler([
            'model' => $state['model'],
            'filters' => $state['filters'] ?? [],
        ], $state['user_id'], $state['options'], $nextPage);
    }

    public function count(array $params, $userId, array $options, array $dependencies): array
    {
        $modelName = $params['model'] ?? null;
        if (!$modelName) {
            return ['success' => false, 'error' => 'No model specified'];
        }

        $modelClass = $dependencies['findModelClass']($modelName, $options);
        if (!$modelClass) {
            return [
                'success' => false,
                'error' => "Model {$modelName} not found locally",
                'should_route_to_node' => true,
            ];
        }

        if (!class_exists($modelClass)) {
            return [
                'success' => false,
                'error' => "Model {$modelName} not available locally",
                'should_route_to_node' => true,
            ];
        }

        try {
            $query = $modelClass::query();
            $instance = new $modelClass();
            $table = $instance->getTable();
            $filterConfig = $dependencies['getFilterConfigForModel']($modelClass);

            $scope = $this->scopeGuard->apply($query, $modelClass, $userId, $filterConfig, $options);
            if (($scope['allowed'] ?? false) !== true) {
                return [
                    'success' => false,
                    'error' => $scope['error'] ?? 'Structured RAG access is blocked for this model.',
                    'tool' => 'db_count',
                    'scope_blocked' => true,
                ];
            }
            $query = $scope['query'];

            $query = $dependencies['applyFilters']($query, $params['filters'] ?? [], $modelClass, $options);
            $count = $query->count();

            return [
                'success' => true,
                'response' => "You have **{$count}** {$modelName}(s).",
                'tool' => 'db_count',
                'fast_path' => true,
                'count' => $count,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_count failed', ['error' => $e->getMessage()]);

            if ($this->isMissingTableException($e)) {
                return [
                    'success' => false,
                    'error' => "Model {$modelName} table is not available locally",
                    'should_route_to_node' => true,
                ];
            }

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function aggregate(array $params, $userId, array $options, array $dependencies): array
    {
        return $this->aggregateService->aggregate($params, $userId, $options, $dependencies);
    }

    public function executeModelTool(array $params, $userId, array $options, array $dependencies): array
    {
        $modelName = $params['model'] ?? null;
        $toolName = $params['tool_name'] ?? null;
        $toolParams = $params['tool_params'] ?? [];
        $message = $params['message'] ?? '';
        $conversationHistory = $params['conversation_history'] ?? [];
        $sessionId = $options['session_id'] ?? null;

        if (!$modelName || !$toolName) {
            return ['success' => false, 'error' => 'Model and tool_name required'];
        }

        $queryState = $this->stateService->getQueryState($sessionId);
        if ($queryState && isset($queryState['from_node'])) {
            return [
                'success' => false,
                'error' => "Model {$modelName} data is on remote node",
                'should_route_to_node' => true,
            ];
        }

        $modelClass = $dependencies['findModelClass']($modelName, $options);
        if (!$modelClass) {
            return [
                'success' => false,
                'error' => "Model {$modelName} not found locally",
                'should_route_to_node' => true,
            ];
        }

        if (!class_exists($modelClass)) {
            return [
                'success' => false,
                'error' => "Model {$modelName} not available locally",
                'should_route_to_node' => true,
            ];
        }

        $configClass = $dependencies['findModelConfigClass']($modelClass);
        if (!$configClass) {
            return ['success' => false, 'error' => "No config found for {$modelName}"];
        }

        try {
            $tools = $configClass::getTools();
            if (!isset($tools[$toolName])) {
                return ['success' => false, 'error' => "Tool {$toolName} not found for {$modelName}"];
            }

            $tool = $tools[$toolName];
            $handler = $tool['handler'] ?? null;
            if (!$handler || !is_callable($handler)) {
                return ['success' => false, 'error' => "Tool {$toolName} has no handler"];
            }

            $allowedOps = $configClass::getAllowedOperations($userId);
            $requiresCreate = stripos($toolName, 'create') !== false;
            $requiresUpdate = stripos($toolName, 'update') !== false;
            $requiresDelete = stripos($toolName, 'delete') !== false;

            if ($requiresCreate && !in_array('create', $allowedOps, true)) {
                return ['success' => false, 'error' => 'Permission denied: create'];
            }
            if ($requiresUpdate && !in_array('update', $allowedOps, true)) {
                return ['success' => false, 'error' => 'Permission denied: update'];
            }
            if ($requiresDelete && !in_array('delete', $allowedOps, true)) {
                return ['success' => false, 'error' => 'Permission denied: delete'];
            }

            $toolSchema = $tool['parameters'] ?? [];
            $extractedParams = \LaravelAIEngine\Services\Agent\Handlers\ToolParameterExtractor::extract(
                $message,
                $conversationHistory,
                $toolSchema,
                $modelName,
                $queryState
            );

            $finalParams = array_merge($extractedParams, $toolParams);
            $selectedEntity = $this->stateService->resolveSelectedEntity($options);
            if ($selectedEntity && !empty($selectedEntity['entity_data'])) {
                $finalParams['entity_data'] = $selectedEntity['entity_data'];
            }

            $result = $handler($finalParams);

            if (is_array($result)) {
                $response = [
                    'success' => $result['success'] ?? true,
                    'response' => $result['message'] ?? (($result['success'] ?? true) ? 'Operation completed' : 'Operation failed'),
                    'tool' => 'model_tool',
                    'tool_name' => $toolName,
                    'fast_path' => true,
                    'data' => $result,
                ];

                if (isset($result['suggested_actions']) && is_array($result['suggested_actions'])) {
                    $response['suggested_actions'] = $result['suggested_actions'];
                }

                return $response;
            }

            return [
                'success' => true,
                'response' => "Tool {$toolName} executed successfully",
                'tool' => 'model_tool',
                'tool_name' => $toolName,
                'fast_path' => true,
                'result' => $result,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Model tool execution failed', [
                'model' => $modelName,
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            if ($this->isMissingTableException($e)) {
                return [
                    'success' => false,
                    'error' => "Model {$modelName} table is not available locally",
                    'tool' => 'model_tool',
                    'tool_name' => $toolName,
                    'should_route_to_node' => true,
                ];
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'tool' => 'model_tool',
                'tool_name' => $toolName,
            ];
        }
    }

    protected function translate(string $key, string $fallback, array $replace = []): string
    {
        $translated = $this->locale?->translation($key, $replace);
        if (is_string($translated) && $translated !== '') {
            return $translated;
        }

        return strtr(
            $fallback,
            collect($replace)->mapWithKeys(static fn ($value, $name) => [':' . $name => (string) $value])->all()
        );
    }

    protected function isMissingTableException(\Exception $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'base table or view not found')
            || str_contains($message, 'no such table')
            || str_contains($message, 'doesn\'t exist');
    }
}
