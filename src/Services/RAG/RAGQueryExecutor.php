<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Executes structured database queries (list, count, aggregate, paginate)
 * against Eloquent models discovered by RAGModelDiscovery.
 *
 * Each public method returns a standardised result array:
 *   ['success' => bool, 'response' => string, 'tool' => string, ...]
 *
 * Pagination state is persisted in cache so follow-up "next page" requests
 * can continue from where the user left off.
 */
class RAGQueryExecutor
{
    public function __construct(
        protected RAGModelDiscovery $modelDiscovery,
        protected RAGFilterService $filterService
    ) {
    }

    protected function perPage(): int
    {
        return max(1, (int) config('ai-agent.autonomous_rag.per_page', 10));
    }

    protected function cacheTtlMinutes(): int
    {
        return max(1, (int) config('ai-agent.autonomous_rag.query_state_ttl_minutes', 30));
    }

    protected function currencySymbol(): string
    {
        return (string) config('ai-agent.autonomous_rag.currency_symbol', '$');
    }

    // ──────────────────────────────────────────────
    //  db_query — list / fetch with pagination
    // ──────────────────────────────────────────────

    public function dbQuery(array $params, $userId, array $options, int $page = 1): array
    {
        $modelName = $params['model'] ?? null;
        $sessionId = $options['session_id'] ?? null;

        if (!$modelName) {
            return $this->fail('No model specified');
        }

        $modelClass = $this->modelDiscovery->resolveModelClass($modelName, $options);
        if (!$modelClass) {
            return $this->fail("Model {$modelName} not found");
        }

        if (!class_exists($modelClass)) {
            return $this->routeToNode("Model {$modelName} not available locally");
        }

        try {
            $query = $modelClass::query();
            $filterConfig = $this->modelDiscovery->getFilterConfig($modelClass);

            // Eager-load relationships
            if (!empty($filterConfig['eager_load'])) {
                $query->with($filterConfig['eager_load']);
            }

            // User scoping
            $this->filterService->applyUserScope($query, $modelClass, $userId, $filterConfig);

            // AI-generated filters
            $filters = $params['filters'] ?? [];
            $query = $this->filterService->apply($query, $filters, $modelClass, $options);

            // Pagination math
            $perPage = $this->perPage();
            $totalCount = (clone $query)->count();
            $totalPages = (int) ceil($totalCount / $perPage);
            $offset = ($page - 1) * $perPage;

            $items = $query->latest()->skip($offset)->take($perPage)->get();

            if ($items->isEmpty()) {
                return $this->emptyResult($modelName, $page, $totalPages, $totalCount);
            }

            // Persist pagination state
            if ($sessionId) {
                $this->storeQueryState($sessionId, $modelName, $modelClass, $filters, $userId, $options, $page, $totalPages, $totalCount, $items, $offset);
            }

            // Format response
            $response = $this->formatQueryResponse($items, $modelName, $offset, $totalCount, $page, $totalPages, $filters);

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
                'entity_ids' => $items->pluck('id')->toArray(),
                'entity_type' => $modelName,
                'items' => $items->toArray(),
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_query failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->fail($e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  db_query_next — next page of previous query
    // ──────────────────────────────────────────────

    public function dbQueryNext(array $params, $userId, array $options): array
    {
        $sessionId = $options['session_id'] ?? null;

        if (!$sessionId) {
            return $this->fail('No session ID provided for pagination.', 'db_query_next');
        }

        $state = Cache::get("rag_query_state:{$sessionId}");
        if (empty($state)) {
            return $this->fail('No previous query to continue. Please make a query first.', 'db_query_next');
        }

        $nextPage = ($state['page'] ?? 1) + 1;

        if ($nextPage > ($state['total_pages'] ?? 1)) {
            return [
                'success' => true,
                'response' => "You've reached the end. All {$state['total_count']} {$state['model']}s have been shown.",
                'tool' => 'db_query_next',
                'fast_path' => true,
                'count' => 0,
                'page' => $state['page'],
                'total_pages' => $state['total_pages'],
            ];
        }

        return $this->dbQuery(
            ['model' => $state['model'], 'filters' => $state['filters'] ?? []],
            $state['user_id'],
            $state['options'],
            $nextPage
        );
    }

    // ──────────────────────────────────────────────
    //  db_count
    // ──────────────────────────────────────────────

    public function dbCount(array $params, $userId, array $options): array
    {
        $modelName = $params['model'] ?? null;
        if (!$modelName) {
            return $this->fail('No model specified');
        }

        $modelClass = $this->modelDiscovery->resolveModelClass($modelName, $options);
        if (!$modelClass) {
            return $this->fail("Model {$modelName} not found");
        }

        if (!class_exists($modelClass)) {
            return $this->routeToNode("Model {$modelName} not available locally");
        }

        try {
            $query = $modelClass::query();
            $filterConfig = $this->modelDiscovery->getFilterConfig($modelClass);

            $this->filterService->applyUserScope($query, $modelClass, $userId, $filterConfig);

            $filters = $params['filters'] ?? [];
            $query = $this->filterService->apply($query, $filters, $modelClass, $options);

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
            return $this->fail($e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  db_aggregate — sum, avg, min, max, count
    // ──────────────────────────────────────────────

    public function dbAggregate(array $params, $userId, array $options): array
    {
        $modelName = $params['model'] ?? null;
        if (!$modelName) {
            return $this->fail('No model specified');
        }

        $modelClass = $this->modelDiscovery->resolveModelClass($modelName, $options);
        if (!$modelClass) {
            return $this->fail("Model {$modelName} not found");
        }

        if (!class_exists($modelClass)) {
            return $this->routeToNode("Model {$modelName} not available locally");
        }

        $aggregate = $params['aggregate'] ?? [];
        $operation = $aggregate['operation'] ?? 'sum';
        $field = $aggregate['field'] ?? null;

        $filterConfig = $this->modelDiscovery->getFilterConfig($modelClass);
        if (!$field && !empty($filterConfig['amount_field'])) {
            $field = $filterConfig['amount_field'];
        }

        if (!$field) {
            return $this->fail('No field specified for aggregation');
        }

        $validOps = ['sum', 'avg', 'min', 'max', 'count'];
        if (!in_array($operation, $validOps, true)) {
            $operation = 'sum';
        }

        try {
            $query = $modelClass::query();
            $instance = new $modelClass;
            $table = $instance->getTable();

            if (!empty($filterConfig['eager_load'])) {
                $query->with($filterConfig['eager_load']);
            }

            $this->filterService->applyUserScope($query, $modelClass, $userId, $filterConfig);

            $filters = $params['filters'] ?? [];
            $query = $this->filterService->apply($query, $filters, $modelClass, $options);

            $isDbField = \Schema::hasColumn($table, $field);
            $methodName = 'get' . ucfirst($field);
            $hasMethod = method_exists($instance, $methodName);

            $result = null;
            $count = 0;
            $calculationMethod = null;

            if ($isDbField) {
                // Fast path: database aggregation
                $result = $query->$operation($field);
                $count = (clone $query)->count();
                $calculationMethod = 'database';
            } elseif ($hasMethod) {
                // Slow path: in-memory via model accessor
                $records = $query->get();
                $count = $records->count();

                if ($count === 0) {
                    $result = 0;
                } else {
                    $values = $records->map(fn($r) => $r->$methodName())->filter('is_numeric');
                    $result = match ($operation) {
                        'sum' => $values->sum(),
                        'avg' => $values->avg(),
                        'min' => $values->min(),
                        'max' => $values->max(),
                        'count' => $values->count(),
                    };
                }

                $calculationMethod = 'model_method';
            } else {
                return $this->fail("Field '{$field}' not found in database and no method '{$methodName}' exists on {$modelName}");
            }

            $labels = ['sum' => 'Total', 'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum', 'count' => 'Count'];
            $label = $labels[$operation] ?? 'Result';
            $formatted = is_numeric($result) ? number_format($result, 2) : $result;

            Log::channel('ai-engine')->info('Aggregate completed', [
                'operation' => $operation,
                'field' => $field,
                'result' => $result,
                'count' => $count,
                'method' => $calculationMethod,
            ]);

            $currency = $this->currencySymbol();

            return [
                'success' => true,
                'response' => "**{$label} {$field}**: {$currency}{$formatted} (from {$count} {$modelName}s)",
                'tool' => 'db_aggregate',
                'fast_path' => $calculationMethod === 'database',
                'result' => $result,
                'count' => $count,
                'operation' => $operation,
                'field' => $field,
                'calculation_method' => $calculationMethod,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_aggregate failed', ['error' => $e->getMessage()]);
            return $this->fail($e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    protected function fail(string $error, string $tool = ''): array
    {
        $result = ['success' => false, 'error' => $error];
        if ($tool !== '') {
            $result['tool'] = $tool;
        }
        return $result;
    }

    /**
     * Signal that the tool cannot execute locally and the caller
     * should route to a remote node.
     */
    protected function routeToNode(string $reason): array
    {
        Log::channel('ai-engine')->info('RAGQueryExecutor: model not local, signalling node routing', [
            'reason' => $reason,
        ]);

        return [
            'success' => false,
            'error' => $reason,
            'should_route_to_node' => true,
        ];
    }

    protected function emptyResult(string $modelName, int $page, int $totalPages, int $totalCount): array
    {
        if ($page > 1) {
            return [
                'success' => true,
                'response' => "No more {$modelName}s to show. You've seen all {$totalCount} results.",
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
            'response' => "No {$modelName}s found matching your criteria.",
            'tool' => 'db_query',
            'fast_path' => true,
            'count' => 0,
        ];
    }

    protected function storeQueryState(
        string $sessionId,
        string $modelName,
        string $modelClass,
        array $filters,
        $userId,
        array $options,
        int $page,
        int $totalPages,
        int $totalCount,
        $items,
        int $offset
    ): void {
        $startPosition = $offset + 1;
        $endPosition = $offset + $items->count();

        $entityIds = $items->pluck('id')->toArray();
        $entityData = $items->map(function ($item, $index) use ($startPosition) {
            $position = $startPosition + $index;
            $summary = method_exists($item, 'toRAGSummary') ? $item->toRAGSummary() : (string) $item;

            return [
                'position' => $position,
                'id' => $item->id,
                'summary' => $summary,
            ];
        })->toArray();

        Cache::put("rag_query_state:{$sessionId}", [
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
            'start_position' => $startPosition,
            'end_position' => $endPosition,
            'current_page' => $page,
        ], now()->addMinutes($this->cacheTtlMinutes()));

        Log::channel('ai-engine')->info('Stored query state for pagination', [
            'session_id' => $sessionId,
            'model' => $modelName,
            'page' => $page,
            'total_pages' => $totalPages,
            'positions' => "{$startPosition}-{$endPosition}",
        ]);
    }

    protected function formatQueryResponse($items, string $modelName, int $offset, int $totalCount, int $page, int $totalPages, array $filters): string
    {
        $isSingleRecord = $totalCount === 1 && !empty($filters['id']);

        if ($isSingleRecord) {
            $item = $items->first();
            if (method_exists($item, 'toRAGContent')) {
                return $item->toRAGContent();
            }
            if (method_exists($item, '__toString')) {
                return $item->__toString();
            }
            return json_encode($item->toArray(), JSON_PRETTY_PRINT);
        }

        // List view with numbering
        $startNum = $offset + 1;
        $endNum = $offset + $items->count();
        $response = "**{$modelName}s** (showing {$startNum}-{$endNum} of {$totalCount}):\n\n";

        foreach ($items as $i => $item) {
            $num = $offset + $i + 1;

            if (method_exists($item, 'toRAGContent')) {
                $response .= "{$num}. " . $item->toRAGContent() . "\n\n";
            } elseif (method_exists($item, '__toString')) {
                $response .= "{$num}. " . $item->__toString() . "\n";
            } else {
                $response .= "{$num}. " . json_encode($item->toArray()) . "\n";
            }
        }

        if ($page < $totalPages) {
            $response .= "\n---\n*Say \"show more\" or \"next\" to see more results.*";
        }

        return $response;
    }
}
