<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Scope\AIScopeOptionsService;

/**
 * RAG decision engine for structured-data and vector-retrieval tool selection.
 */
class RAGDecisionEngine
{
    protected ?RAGPipelineContract $ragPipeline;
    protected ?RAGCollectionDiscovery $discovery;
    protected RAGDecisionStateService $stateService;
    protected RAGPlannerService $decisionService;
    protected RAGToolExecutionService $executionService;
    protected RAGStructuredDataService $structuredDataService;
    protected RAGDecisionPolicy $policy;
    protected RAGContextService $contextService;
    protected RAGModelMetadataService $modelMetadata;
    protected ?AIScopeOptionsService $scopeOptions;

    public function __construct(
        AIEngineService $ai,
        ?RAGPipelineContract $ragPipeline = null,
        ?RAGCollectionDiscovery $discovery = null,
        ?RAGDecisionStateService $stateService = null,
        ?RAGPlannerService $decisionService = null,
        ?RAGToolExecutionService $executionService = null,
        ?RAGStructuredDataService $structuredDataService = null,
        ?RAGDecisionPolicy $policy = null,
        ?RAGContextService $contextService = null,
        ?RAGModelMetadataService $modelMetadata = null,
        ?AIScopeOptionsService $scopeOptions = null
    ) {
        $this->ragPipeline = $ragPipeline;
        $this->discovery = $discovery ?? (app()->bound(RAGCollectionDiscovery::class) ? app(
            RAGCollectionDiscovery::class
        ) : null);
        $this->policy = $policy ?? new RAGDecisionPolicy();
        $this->stateService = $stateService ?? new RAGDecisionStateService($this->policy);
        $this->decisionService = $decisionService ?? new RAGPlannerService($ai, $this->policy);
        $this->executionService = $executionService ?? new RAGToolExecutionService();
        $this->structuredDataService = $structuredDataService ?? new RAGStructuredDataService(
            $this->stateService,
            $this->policy
        );
        $this->modelMetadata = $modelMetadata ?? new RAGModelMetadataService(
            $this->discovery,
            $this->stateService
        );
        $this->contextService = $contextService ?? new RAGContextService(
            $this->modelMetadata,
            $this->policy
        );
        $this->scopeOptions = $scopeOptions ?? (app()->bound(AIScopeOptionsService::class)
            ? app(AIScopeOptionsService::class)
            : null);
    }

    /**
     * Process a message - AI decides everything
     */
    public function process(
        string $message,
        string $sessionId,
        $userId = null,
        array $conversationHistory = [],
        array $options = []
    ): array {
        Log::channel('ai-engine')->info('RAG User ID:' . $userId);
        $startTime = microtime(true);

        $options = $this->scopeOptions?->merge($userId, $options) ?? $options;
        $options['session_id'] = $sessionId;
        $options = $this->stateService->hydrateOptionsWithLastEntityList($sessionId, $options);
        $routeMode = (string) ($options['preclassified_route_mode'] ?? '');

        if ($routeMode === 'structured_query') {
            $context = $this->contextService->build($message, $conversationHistory, $userId, $options);
            $decision = $this->decisionService->fallbackDecisionForMessage(
                $message,
                $context,
                [],
                'preclassified structured_query; bypassing AI tool selection'
            );
            $decision['decision_source'] = 'heuristic';

            Log::channel('ai-engine')->info('RAGDecisionEngine: bypassed to structured tool', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'tool' => $decision['tool'] ?? 'db_query',
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ]);

            return $this->executeTool($decision, $message, $sessionId, $userId, $conversationHistory, $options);
        }

        if (!empty($options['force_rag']) || in_array($routeMode, ['semantic_retrieval', 'contextual_follow_up'], true)) {
            $decision = [
                'tool' => 'vector_search',
                'reasoning' => !empty($options['force_rag'])
                    ? 'force_rag enabled; bypassing AI tool selection'
                    : "preclassified {$routeMode}; bypassing AI tool selection",
                'parameters' => [
                    'query' => $message,
                    'limit' => $this->policy->itemsPerPage(),
                ],
                'decision_source' => !empty($options['force_rag']) ? 'forced' : 'heuristic',
            ];

            Log::channel('ai-engine')->info('RAGDecisionEngine: bypassed to vector_search', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'route_mode' => $routeMode,
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ]);

            return $this->executeTool($decision, $message, $sessionId, $userId, $conversationHistory, $options);
        }

        // Build context for AI with available tools and models
        $context = $this->contextService->build($message, $conversationHistory, $userId, $options);

        $model = $options['model'] ?? $this->policy->decisionModel();
        $decision = $this->decisionService->decide($message, $context, $model);

        Log::channel('ai-engine')->info('RAGDecisionEngine: AI decision', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'tool' => $decision['tool'] ?? 'unknown',
            'reasoning' => $decision['reasoning'] ?? '',
            'duration_ms' => round((microtime(true) - $startTime) * 1000),
        ]);

        return $this->executeTool($decision, $message, $sessionId, $userId, $conversationHistory, $options);
    }

    /**
     * Execute the tool AI chose
     */
    protected function executeTool(
        array $decision,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        // Ensure session_id is in options for pagination state persistence
        $options['session_id'] = $sessionId;

        $normalizedDecision = $this->executionService->normalize($decision);

        Log::channel('ai-engine')->info('Execute tool : ' . $normalizedDecision['tool'], [
            'session_id' => $sessionId,
            'tool' => $normalizedDecision['tool'],
        ]);

        $result = $this->executionService->execute($normalizedDecision, [
            'answer_from_context' => fn (array $plan) => $this->answerFromContext(
                $plan['parameters'],
                $conversationHistory,
                $message,
                $sessionId,
                $userId,
                $options
            ),
            'db_query' => fn (array $plan) => $this->executeDbQueryWithRouting(
                $plan['parameters'],
                $message,
                $sessionId,
                $userId,
                $conversationHistory,
                $options
            ),
            'db_count' => fn (array $plan) => $this->executeDbCountWithRouting(
                $plan['parameters'],
                $message,
                $sessionId,
                $userId,
                $conversationHistory,
                $options
            ),
            'db_query_next' => fn (array $plan) => $this->executeDbQueryNext(
                $plan['parameters'],
                $userId,
                $options
            ),
            'db_aggregate' => fn (array $plan) => $this->executeDbAggregateWithRouting(
                $plan['parameters'],
                $message,
                $sessionId,
                $userId,
                $conversationHistory,
                $options
            ),
            'vector_search' => fn (array $plan) => $this->vectorSearch(
                $plan['parameters'],
                $message,
                $sessionId,
                $userId,
                $conversationHistory,
                $options
            ),
            'model_tool' => fn (array $plan) => $this->executeModelToolWithRouting(
                $plan['parameters'],
                $message,
                $sessionId,
                $userId,
                $conversationHistory,
                $options
            ),
            'exit_to_orchestrator' => fn (array $plan) => $this->exitToOrchestrator($plan['parameters'], $message),
        ]);

        $this->decisionService->recordExecutionOutcome($decision, $result, [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'tenant_id' => $options['tenant_id'] ?? null,
            'app_id' => $options['app_id'] ?? null,
            'policy' => is_array($decision['policy'] ?? null) ? $decision['policy'] : null,
            'metadata' => [
                'message_excerpt' => substr(trim($message), 0, 180),
            ],
        ]);

        return $result;
    }

    protected function executeDbQueryWithRouting(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $result = $this->dbQuery($params, $userId, array_merge($options, [
            'original_message' => $message,
        ]));
        if (!$result['success'] && ($result['should_route_to_node'] ?? false)) {
            return $this->routeToNodeForModel($params, $message, $sessionId, $userId, $conversationHistory, $options);
        }

        return $result;
    }

    protected function executeDbCountWithRouting(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $result = $this->dbCount($params, $userId, $options);
        if (!$result['success'] && ($result['should_route_to_node'] ?? false)) {
            return $this->routeToNodeForModel($params, $message, $sessionId, $userId, $conversationHistory, $options);
        }

        return $result;
    }

    protected function executeDbAggregateWithRouting(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $result = $this->dbAggregate($params, $userId, $options);
        if (!$result['success'] && ($result['should_route_to_node'] ?? false)) {
            return $this->routeToNodeForModel($params, $message, $sessionId, $userId, $conversationHistory, $options);
        }

        return $result;
    }

    protected function executeDbQueryNext(array $params, $userId, array $options): array
    {
        $response = $this->dbQueryNext($params, $userId, $options);
        Log::channel('ai-engine')->info('dbQueryNext : ' . json_encode($response));

        return $response;
    }

    protected function executeModelToolWithRouting(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $params['message'] = $message;
        $params['conversation_history'] = $conversationHistory;

        $result = $this->executeModelTool($params, $userId, $options);
        if (!$result['success'] && ($result['should_route_to_node'] ?? false)) {
            return $this->routeToNodeForModel($params, $message, $sessionId, $userId, $conversationHistory, $options);
        }

        return $result;
    }

    /**
     * Tool: Answer from conversation context
     */
    protected function answerFromContext(
        array $params,
        array $conversationHistory,
        string $message,
        string $sessionId,
        $userId,
        array $options
    ): array
    {
        $answer = $params['answer'] ?? null;
        Log::channel('ai-engine')->info('Answer from context', ['answer' => $answer]);
        if ($answer) {
            return [
                'success' => true,
                'response' => $answer,
                'tool' => 'answer_from_context',
                'fast_path' => true,
            ];
        }

        $selected = is_array($options['selected_entity'] ?? null) ? $options['selected_entity'] : [];
        $selectedId = isset($selected['entity_id']) ? (int) $selected['entity_id'] : 0;
        $selectedType = isset($selected['entity_type']) ? strtolower(trim((string) $selected['entity_type'])) : '';

        if ($selectedId > 0 && $selectedType !== '') {
            Log::channel('ai-engine')->info('answer_from_context missing answer; falling back to selected entity query', [
                'session_id' => $sessionId,
                'entity_id' => $selectedId,
                'entity_type' => $selectedType,
            ]);

            return $this->executeDbQueryWithRouting(
                [
                    'model' => $selectedType,
                    'filters' => ['id' => $selectedId],
                    'limit' => 1,
                ],
                $message,
                $sessionId,
                $userId,
                $conversationHistory,
                $options
            );
        }

        return [
            'success' => false,
            'error' => 'No answer provided in parameters',
        ];
    }

    protected function structuredDataDependencies(): array
    {
        return [
            'findModelClass' => fn (string $modelName, array $options) => $this->modelMetadata->findModelClass($modelName, $options),
            'getFilterConfigForModel' => fn (string $modelClass) => $this->modelMetadata->getFilterConfigForModel($modelClass),
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $this->modelMetadata->applyFilters(
                $query,
                $filters,
                $modelClass,
                $options
            ),
            'findModelConfigClass' => fn (string $modelClass) => $this->modelMetadata->findModelConfigClass($modelClass),
        ];
    }

    protected function dbQuery(array $params, $userId, array $options, int $page = 1): array
    {
        return $this->structuredDataService->query(
            $params,
            $userId,
            $options,
            $this->structuredDataDependencies(),
            $page,
            $params['query'] ?? ($options['original_message'] ?? null)
        );
    }

    protected function dbQueryNext(array $params, $userId, array $options): array
    {
        return $this->structuredDataService->queryNext(
            $params,
            $userId,
            $options,
            fn (array $queryParams, $stateUserId, array $stateOptions, int $nextPage) => $this->dbQuery(
                $queryParams,
                $stateUserId,
                $stateOptions,
                $nextPage
            )
        );
    }

    protected function dbCount(array $params, $userId, array $options): array
    {
        return $this->structuredDataService->count(
            $params,
            $userId,
            $options,
            $this->structuredDataDependencies()
        );
    }

    protected function dbAggregate(array $params, $userId, array $options): array
    {
        return $this->structuredDataService->aggregate(
            $params,
            $userId,
            $options,
            $this->structuredDataDependencies()
        );
    }

    protected function executeModelTool(array $params, $userId, array $options): array
    {
        return $this->structuredDataService->executeModelTool(
            $params,
            $userId,
            $options,
            $this->structuredDataDependencies()
        );
    }

    /**
     * Tool: Vector search (RAG)
     */
    protected function vectorSearch(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        if (!$this->ragPipeline) {
            return ['success' => false, 'error' => 'RAG service not available'];
        }

        $modelName = $params['model'] ?? null;
        $query = $params['query'] ?? $message;

        // Filter collections if model specified
        $collections = $options['rag_collections'] ?? [];
        if ($modelName) {
            $modelClass = $this->modelMetadata->findModelClass($modelName, $options);
            if ($modelClass) {
                $collections = [$modelClass];
            }
        }

        try {
            $response = $this->ragPipeline->process(
                $query,
                $sessionId,
                $collections, // availableCollections (3rd arg)
                $conversationHistory, // conversationHistory (4th arg)
                array_merge($options, [ // options (5th arg)
                    'user_id' => $userId,
                    'rag_collections' => $collections,
                ]),
                $userId // userId (6th arg)
            );

            return [
                'success' => true,
                'response' => $response->getContent(),
                'tool' => 'vector_search',
                'metadata' => $response->getMetadata(),
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('vector_search failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Route to node that has the requested model
     */
    protected function routeToNodeForModel(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $modelName = $params['model'] ?? null;
        if (!$modelName) {
            return ['success' => false, 'error' => 'No model specified'];
        }

        $modelClass = $this->modelMetadata->findModelClass($modelName, $options);
        $targetNode = null;

        if (app()->bound(\LaravelAIEngine\Services\Node\NodeOwnershipResolver::class)) {
            try {
                $resolver = app(\LaravelAIEngine\Services\Node\NodeOwnershipResolver::class);

                foreach (array_filter([$modelClass, $modelName]) as $candidate) {
                    $node = $resolver->resolveForCollection($candidate);
                    if ($node) {
                        $targetNode = $node->slug;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('Node ownership lookup failed', [
                    'model' => $modelName,
                    'model_class' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$targetNode) {
            Log::channel('ai-engine')->warning('No node found for model routing', [
                'model' => $modelName,
                'model_class' => $modelClass,
            ]);

            return ['success' => false, 'error' => "No node found with model {$modelName}"];
        }

        Log::channel('ai-engine')->info('Routing to node for model', [
            'model' => $modelName,
            'model_class' => $modelClass,
            'node' => $targetNode,
        ]);

        // Route to the node with the model
        return $this->routeToNode(
            ['node' => $targetNode],
            $message,
            $sessionId,
            $userId,
            $conversationHistory,
            $options
        );
    }

    /**
     * Tool: Route to node
     */
    protected function routeToNode(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $nodeSlug = $params['node'] ?? null;
        if (!$nodeSlug) {
            return ['success' => false, 'error' => 'No node specified'];
        }

        $node = AINode::where('slug', $nodeSlug)->first();
        if (!$node) {
            return ['success' => false, 'error' => "Node {$nodeSlug} not available"];
        }

        if (!$node->isHealthy() && !app()->environment('local')) {
            return ['success' => false, 'error' => "Node {$nodeSlug} not available"];
        }

        if (!$node->isHealthy() && app()->environment('local')) {
            Log::channel('ai-engine')->warning('Routing to node despite unhealthy status in local environment', [
                'node' => $nodeSlug,
            ]);
        }

        try {
            // Extract user token and forwardable headers for authentication
            $userToken = request()->bearerToken() ?? request()->header('X-User-Token');
            $forwardHeaders = \LaravelAIEngine\Services\Node\NodeHttpClient::extractForwardableHeaders();

            // Merge authentication headers
            $headers = array_merge($forwardHeaders, [
                'X-Forwarded-From-Node' => config('app.name'),
                'X-User-Token' => $userToken,
            ]);

            $router = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);

            $response = $router->forwardChat(
                $node,
                $message,
                $sessionId,
                array_merge($options, [
                    'conversation_history' => $conversationHistory,
                    'headers' => $headers,
                    'user_token' => $userToken,
                ]),
                $userId
            );

            if ($response['success']) {
                Cache::put("session_last_node:{$sessionId}", $nodeSlug, now()->addMinutes(30));

                $result = [
                    'success' => true,
                    'response' => $response['response'],
                    'tool' => 'route_to_node',
                    'node' => $nodeSlug,
                    'metadata' => $response['metadata'] ?? [],
                ];

                // Extract entity_ids and entity_type from metadata for follow-up tracking
                if (isset($response['metadata']['entity_ids'])) {
                    $result['entity_ids'] = $response['metadata']['entity_ids'];
                }
                if (isset($response['metadata']['entity_type'])) {
                    $result['entity_type'] = $response['metadata']['entity_type'];
                }

                return $result;
            }

            return ['success' => false, 'error' => $response['error'] ?? 'Routing failed'];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('route_to_node failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tool: Exit to orchestrator for CRUD operations
     * Returns control to the orchestrator which will start the appropriate autonomous collector
     */
    protected function exitToOrchestrator(array $params, string $originalMessage): array
    {
        $message = $params['message'] ?? $originalMessage;

        return [
            'success' => true,
            'exit_to_orchestrator' => true,
            'message' => $message,
            'tool' => 'exit_to_orchestrator',
        ];
    }
}
