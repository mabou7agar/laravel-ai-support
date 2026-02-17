<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Agent\Handlers\ToolParameterExtractor;

/**
 * Dispatches AI-selected tools to the correct executor and handles
 * the unified node-routing fallback in a single place.
 *
 * Routing eligibility is declared per-tool rather than duplicated
 * inside each tool method. After any tool returns, this dispatcher
 * checks the result once and signals node routing if needed.
 */
class RAGToolDispatcher
{
    /**
     * Tools that may signal `should_route_to_node` when the model
     * is not available locally. Checked in a single place after execution.
     */
    protected const NODE_ROUTABLE_TOOLS = [
        'db_query',
        'db_count',
        'db_aggregate',
        'model_tool',
    ];

    public function __construct(
        protected RAGQueryExecutor $queryExecutor,
        protected RAGModelDiscovery $modelDiscovery,
        protected ?IntelligentRAGService $ragService = null
    ) {
    }

    /**
     * Dispatch a tool decision to the appropriate executor.
     *
     * Returns a standardised result array. If the tool signals
     * `should_route_to_node`, the caller (AutonomousRAGAgent) can
     * hand off to the orchestrator's NodeRoutingCoordinator — no
     * duplicate routing logic lives here.
     */
    public function dispatch(
        array $decision,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $tool = strtolower((string) ($decision['tool'] ?? 'db_query'));
        $params = isset($decision['parameters']) && is_array($decision['parameters'])
            ? $decision['parameters']
            : [];

        $options['session_id'] = $sessionId;
        $params = $this->prepareParams($tool, $params, $message, $conversationHistory);

        Log::channel('ai-engine')->info('RAGToolDispatcher: executing', [
            'tool' => $tool,
            'session_id' => $sessionId,
        ]);

        $result = $this->executeLocally(
            $tool, $params, $message, $sessionId, $userId, $conversationHistory, $options
        );

        // ── vector_search → db_query fallback ───────────────────
        if ($tool === 'vector_search' && $this->isEmptyVectorResult($result) && !empty($params['model'])) {
            Log::channel('ai-engine')->info('RAGToolDispatcher: vector_search returned no results, falling back to db_query', [
                'model' => $params['model'],
                'session_id' => $sessionId,
            ]);

            $result = $this->queryExecutor->dbQuery($params, $userId, $options);
            $result['fallback_from'] = 'vector_search';
        }

        // ── Unified node-routing check ─────────────────────────
        if ($this->shouldRouteToNode($tool, $result)) {
            Log::channel('ai-engine')->info('RAGToolDispatcher: tool signalled node routing', [
                'tool' => $tool,
                'model' => $params['model'] ?? 'unknown',
            ]);

            // Return the signal — the orchestrator layer handles actual routing
            $result['should_route_to_node'] = true;
            $result['route_model'] = $params['model'] ?? null;
        }

        return $result;
    }

    // ──────────────────────────────────────────────
    //  Tool execution
    // ──────────────────────────────────────────────

    protected function executeLocally(
        string $tool,
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        return match ($tool) {
            'answer_from_context' => $this->answerFromContext($params),
            'db_query'           => $this->queryExecutor->dbQuery($params, $userId, $options),
            'db_count'           => $this->queryExecutor->dbCount($params, $userId, $options),
            'db_query_next'      => $this->queryExecutor->dbQueryNext($params, $userId, $options),
            'db_aggregate'       => $this->queryExecutor->dbAggregate($params, $userId, $options),
            'vector_search'      => $this->vectorSearch($params, $message, $sessionId, $userId, $conversationHistory, $options),
            'model_tool'         => $this->executeModelTool($params, $userId, $options),
            'exit_to_orchestrator' => $this->exitToOrchestrator($params, $message),
            default              => $this->queryExecutor->dbQuery($params, $userId, $options),
        };
    }

    // ──────────────────────────────────────────────
    //  answer_from_context
    // ──────────────────────────────────────────────

    protected function answerFromContext(array $params): array
    {
        $answer = $params['answer'] ?? null;

        if ($answer) {
            return [
                'success' => true,
                'response' => $answer,
                'tool' => 'answer_from_context',
                'fast_path' => true,
            ];
        }

        return ['success' => false, 'error' => 'No answer provided in parameters'];
    }

    // ──────────────────────────────────────────────
    //  vector_search
    // ──────────────────────────────────────────────

    protected function vectorSearch(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        if (!$this->ragService) {
            return ['success' => false, 'error' => 'RAG service not available'];
        }

        $modelName = $params['model'] ?? null;
        $query = $params['query'] ?? $message;

        $collections = $options['rag_collections'] ?? [];
        if ($modelName) {
            $modelClass = $this->modelDiscovery->resolveModelClass($modelName, $options);
            if ($modelClass) {
                $collections = [$modelClass];
            }
        }

        try {
            $response = $this->ragService->processMessage(
                $query,
                $sessionId,
                $collections,
                $conversationHistory,
                array_merge($options, [
                    'user_id' => $userId,
                    'rag_collections' => $collections,
                ]),
                $userId
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

    // ──────────────────────────────────────────────
    //  model_tool — execute CRUD tool from AutonomousModelConfig
    // ──────────────────────────────────────────────

    protected function executeModelTool(array $params, $userId, array $options): array
    {
        $modelName = $params['model'] ?? null;
        $toolName = $params['tool_name'] ?? null;
        $toolParams = $params['tool_params'] ?? [];
        $message = $params['message'] ?? '';
        $conversationHistory = $params['conversation_history'] ?? [];

        if (!$modelName || !$toolName) {
            return ['success' => false, 'error' => 'Model and tool_name required'];
        }

        $sessionId = $options['session_id'] ?? null;

        // Check if data came from a remote node
        if ($sessionId) {
            $queryState = Cache::get("rag_query_state:{$sessionId}");
            if ($queryState && isset($queryState['from_node'])) {
                return [
                    'success' => false,
                    'error' => "Model {$modelName} data is on remote node",
                    'should_route_to_node' => true,
                ];
            }
        }

        $modelClass = $this->modelDiscovery->resolveModelClass($modelName, $options);
        if (!$modelClass) {
            return ['success' => false, 'error' => "Model {$modelName} not found"];
        }

        if (!class_exists($modelClass)) {
            return [
                'success' => false,
                'error' => "Model {$modelName} not available locally",
                'should_route_to_node' => true,
            ];
        }

        $configClass = $this->modelDiscovery->findConfigByClass($modelClass);
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

            // Permission check — prefer explicit 'operation' metadata on the tool,
            // fall back to name-based detection only as a last resort.
            $allowedOps = $configClass::getAllowedOperations($userId);
            $requiredOp = $this->detectRequiredOperation($toolName, $tool);

            if ($requiredOp !== null && !in_array($requiredOp, $allowedOps, true)) {
                return ['success' => false, 'error' => "Permission denied: {$requiredOp}"];
            }

            // Extract and merge parameters
            $toolSchema = $tool['parameters'] ?? [];
            $queryState = $sessionId ? Cache::get("rag_query_state:{$sessionId}") : null;

            $extractedParams = ToolParameterExtractor::extract(
                $message,
                $conversationHistory,
                $toolSchema,
                $modelName,
                $queryState
            );

            $finalParams = array_merge($extractedParams, $toolParams);

            // Bind selected entity data
            $selectedEntity = $options['selected_entity'] ?? $options['selected_entity_context'] ?? ($queryState['selected_entity_context'] ?? null);
            if ($selectedEntity && !empty($selectedEntity['entity_data'])) {
                $finalParams['entity_data'] = $selectedEntity['entity_data'];
            }

            Log::channel('ai-engine')->info('RAGToolDispatcher: executing model tool', [
                'model' => $modelName,
                'tool' => $toolName,
                'final_params' => $finalParams,
            ]);

            $result = $handler($finalParams);

            if (is_array($result)) {
                $success = $result['success'] ?? true;
                $msg = $result['message'] ?? ($success ? 'Operation completed' : 'Operation failed');

                $response = [
                    'success' => $success,
                    'response' => $msg,
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

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'tool' => 'model_tool',
                'tool_name' => $toolName,
            ];
        }
    }

    // ──────────────────────────────────────────────
    //  exit_to_orchestrator
    // ──────────────────────────────────────────────

    protected function exitToOrchestrator(array $params, string $originalMessage): array
    {
        return [
            'success' => true,
            'exit_to_orchestrator' => true,
            'message' => $params['message'] ?? $originalMessage,
            'tool' => 'exit_to_orchestrator',
        ];
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Enrich params for model_tool with message/history so the
     * tool handler can extract missing values from conversation context.
     */
    protected function prepareParams(string $tool, array $params, string $message, array $conversationHistory): array
    {
        if ($tool === 'model_tool') {
            $params['message'] = $message;
            $params['conversation_history'] = $conversationHistory;
        }

        return $params;
    }

    /**
     * Detect the CRUD operation a tool requires.
     *
     * Prefers explicit `operation` key in tool config (e.g. 'create', 'update', 'delete').
     * Falls back to name-based heuristic only when metadata is absent.
     */
    protected function detectRequiredOperation(string $toolName, array $toolConfig): ?string
    {
        // Prefer explicit metadata
        if (!empty($toolConfig['operation'])) {
            return strtolower((string) $toolConfig['operation']);
        }

        // Fallback: infer from tool name
        $name = strtolower($toolName);
        foreach (['delete', 'create', 'update'] as $op) {
            if (str_contains($name, $op)) {
                return $op;
            }
        }

        return null; // read-only / unknown — no permission gate
    }

    /**
     * Check if a vector_search result is effectively empty / no results.
     * Used to trigger automatic fallback to db_query.
     */
    protected function isEmptyVectorResult(array $result): bool
    {
        // Explicit failure (e.g. Qdrant down, no RAG service)
        if (!($result['success'] ?? false)) {
            return true;
        }

        $response = trim((string) ($result['response'] ?? ''));

        // Completely empty response
        if ($response === '') {
            return true;
        }

        // Check metadata for zero context items
        $metadata = $result['metadata'] ?? [];
        $contextCount = $metadata['context_count'] ?? null;
        if ($contextCount === 0) {
            return true;
        }

        return false;
    }

    /**
     * Single check: did the tool signal that execution should be
     * retried on a remote node?
     */
    protected function shouldRouteToNode(string $tool, array $result): bool
    {
        if (!in_array($tool, self::NODE_ROUTABLE_TOOLS, true)) {
            return false;
        }

        return !($result['success'] ?? false)
            && (($result['should_route_to_node'] ?? false) === true);
    }
}
