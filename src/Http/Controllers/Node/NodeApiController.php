<?php

namespace LaravelAIEngine\Http\Controllers\Node;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\ActionExecutionService;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Node\NodeAuthService;
use LaravelAIEngine\Services\Node\NodeManifestService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;

class NodeApiController extends Controller
{
    public function __construct(
        protected NodeManifestService $manifestService,
        protected ActionExecutionService $actionExecutionService,
        protected ChatService $chatService,
        protected InfrastructureHealthService $infrastructureHealth
    ) {
    }

    public function health()
    {
        $health = $this->manifestService->health();
        $statusCode = (($health['status'] ?? 'healthy') === 'healthy' && ($health['ready'] ?? true))
            ? 200
            : 503;

        return response()->json($health, $statusCode);
    }

    public function manifest()
    {
        return response()->json($this->manifestService->manifest());
    }

    public function autonomousCollectors()
    {
        return response()->json([
            'collectors' => $this->manifestService->autonomousCollectors(),
            'count' => count($this->manifestService->autonomousCollectors()),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function collections()
    {
        return response()->json([
            'collections' => $this->manifestService->collections(),
            'count' => count($this->manifestService->collections()),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Search endpoint (for remote nodes to call)
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string',
            'limit' => 'integer|min:1|max:100',
            'options' => 'array',
        ]);

        $startTime = microtime(true);

        try {
            $collections = $validated['options']['collections'] ?? [];
            $userId = $validated['options']['user_id'] ?? null;
            $filters = $validated['options']['filters'] ?? [];
            $results = [];

            foreach ($collections as $collection) {
                if (!class_exists($collection)) {
                    continue;
                }

                $searchResults = $collection::vectorSearch(
                    query: $validated['query'],
                    limit: $validated['limit'] ?? 10,
                    threshold: $validated['options']['threshold'] ?? 0.0,
                    filters: $filters,
                    userId: $userId
                );

                Log::info('NodeApiController: vectorSearch returned', [
                    'collection' => $collection,
                    'count' => count($searchResults),
                    'is_collection' => $searchResults instanceof \Illuminate\Support\Collection,
                    'first_item_class' => $searchResults->first() ? get_class($searchResults->first()) : null,
                ]);

                foreach ($searchResults as $result) {
                    $results[] = [
                        'id' => $result->id ?? null,
                        'content' => $this->extractContent($result),
                        'score' => $result->vector_score ?? 0,
                        'model_class' => $collection,
                        'model_type' => class_basename($collection),
                        'metadata' => [
                            'model_class' => $collection,
                            'model_type' => class_basename($collection),
                            'model_id' => $result->id ?? null,
                        ],
                        'vector_metadata' => $result->vector_metadata ?? [
                            'model_class' => $collection,
                            'model_type' => class_basename($collection),
                        ],
                        'title' => $result->title ?? $result->name ?? $result->subject ?? null,
                        'name' => $result->name ?? null,
                        'body' => $result->body ?? null,
                    ];
                }
            }

            $duration = (microtime(true) - $startTime) * 1000;

            return response()->json([
                'results' => $results,
                'count' => count($results),
                'duration_ms' => round($duration, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Aggregate data endpoint
     */
    public function aggregate(Request $request)
    {
        $validated = $request->validate([
            'collections' => 'required|array',
            'collections.*' => 'string',
            'user_id' => 'nullable|integer',
        ]);

        $startTime = microtime(true);

        try {
            $collections = $validated['collections'];
            $userId = $validated['user_id'] ?? null;
            $aggregateData = [];

            $vectorSearch = app(VectorSearchService::class);

            foreach ($collections as $collection) {
                if (!class_exists($collection)) {
                    continue;
                }

                try {
                    $instance = new $collection();
                    $displayName = class_basename($collection);
                    $description = '';

                    if (method_exists($instance, 'getRAGDisplayName')) {
                        $displayName = $instance->getRAGDisplayName();
                    }
                    if (method_exists($instance, 'getRAGDescription')) {
                        $description = $instance->getRAGDescription();
                    }

                    $filters = [];
                    if ($userId !== null) {
                        $filters['user_id'] = $userId;
                    }

                    $vectorCount = $vectorSearch->getIndexedCountWithFilters($collection, $filters);

                    $aggregateData[$collection] = [
                        'count' => $vectorCount,
                        'indexed_count' => $vectorCount,
                        'display_name' => $displayName,
                        'description' => $description,
                        'source' => 'vector_database',
                    ];
                } catch (\Exception $e) {
                    Log::warning('Failed to get aggregate for collection', [
                        'collection' => $collection,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $duration = (microtime(true) - $startTime) * 1000;

            return response()->json([
                'success' => true,
                'aggregate_data' => $aggregateData,
                'count' => count($aggregateData),
                'duration_ms' => round($duration, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Aggregate query failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Chat endpoint - forward entire chat/workflow to this node.
     * The forwarded request policy is enforced by the chat/orchestration layer.
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'session_id' => 'required|string',
            'user_id' => 'nullable',
            'token' => 'nullable|string',
            'options' => 'array',
        ]);

        $startTime = microtime(true);

        try {
            $chatGuard = $this->infrastructureHealth->chatGuardStatus();
            if (!(bool) ($chatGuard['healthy'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Infrastructure not ready',
                    'code' => 'infra.migrations_missing',
                    'message' => $chatGuard['message'] ?? 'Remote node migration guard failed.',
                    'missing_tables' => $chatGuard['missing_tables'] ?? [],
                ], (int) config('ai-engine.infrastructure.remote_node_migration_guard.status_code', 503));
            }

            $userToken = $request->header('X-User-Token') ?? $validated['token'] ?? null;

            Log::channel('ai-engine')->info('NodeApiController: Attempting CheckAuth', [
                'session_id' => $validated['session_id'],
                'has_user_token' => !empty($userToken),
                'token_preview' => $userToken ? substr($userToken, 0, 20) . '...' : null,
                'checkauth_exists' => class_exists('\\App\\Http\\Middleware\\CheckAuth'),
            ]);

            $userId = Auth::check() ? Auth::id() : ($validated['user_id'] ?? null);

            if (!$userId) {
                Log::channel('ai-engine')->warning('NodeApiController: user_id is null', [
                    'auth_check' => Auth::check(),
                    'auth_id' => Auth::id(),
                    'passed_user_id' => $validated['user_id'] ?? null,
                    'has_user_token' => !empty($userToken),
                ]);
            }

            CreditManager::startAccumulating();

            $options = $validated['options'] ?? [];

            $response = $this->chatService->processMessage(
                message: $validated['message'],
                sessionId: $validated['session_id'],
                engine: $options['engine'] ?? 'openai',
                model: $options['model'] ?? 'gpt-4o-mini',
                useMemory: $options['use_memory'] ?? true,
                useActions: $options['use_actions'] ?? true,
                useIntelligentRAG: $options['use_intelligent_rag'] ?? true,
                ragCollections: $options['rag_collections'] ?? [],
                userId: $userId,
                searchInstructions: $options['search_instructions'] ?? null,
                conversationHistory: $options['conversation_history'] ?? [],
                extraOptions: $this->extractForwardedContextOptions($options)
            );

            $duration = (microtime(true) - $startTime) * 1000;
            $fullMetadata = $response->getMetadata();
            $essentialMetadata = [
                'workflow_active' => $fullMetadata['workflow_active'] ?? false,
                'workflow_class' => $fullMetadata['workflow_class'] ?? null,
                'workflow_completed' => $fullMetadata['workflow_completed'] ?? false,
                'current_step' => $fullMetadata['current_step'] ?? null,
                'agent_strategy' => $fullMetadata['agent_strategy'] ?? null,
                'entity_ids' => $fullMetadata['entity_ids'] ?? null,
                'entity_type' => $fullMetadata['entity_type'] ?? null,
            ];
            $creditsUsed = CreditManager::stopAccumulating();

            return response()->json([
                'success' => true,
                'response' => $response->getContent(),
                'metadata' => $essentialMetadata,
                'credits_used' => $creditsUsed,
                'duration_ms' => round($duration, 2),
            ]);
        } catch (\Exception $e) {
            CreditManager::stopAccumulating();

            Log::channel('ai-engine')->error('NodeApiController: Chat failed', [
                'session_id' => $validated['session_id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Chat processing failed',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Tool execution endpoint for remote nodes.
     */
    public function executeTool(Request $request)
    {
        $validated = $request->validate([
            'action_type' => 'required|string',
            'data' => 'required|array',
            'session_id' => 'nullable|string',
            'user_id' => 'nullable',
        ]);

        try {
            $result = $this->actionExecutionService->execute(
                actionType: $validated['action_type'],
                data: $validated['data'],
                userId: $request->user()?->id ?? ($validated['user_id'] ?? null),
                sessionId: $validated['session_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'action_type' => $validated['action_type'],
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Node tool execution failed', [
                'action_type' => $validated['action_type'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Backward-compatible wrapper for older node clients.
     */
    public function executeAction(Request $request)
    {
        return $this->executeTool($request);
    }

    public function register(Request $request, NodeRegistryService $registry, NodeAuthService $authService)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'url' => 'required|url',
            'capabilities' => 'array',
            'metadata' => 'array',
            'version' => 'string',
        ]);

        try {
            $node = $registry->register($validated);
            $authResponse = $authService->generateAuthResponse($node);

            return response()->json([
                'success' => true,
                'node' => [
                    'id' => $node->id,
                    'slug' => $node->slug,
                    'name' => $node->name,
                ],
                'auth' => $authResponse,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function status(Request $request, NodeRegistryService $registry)
    {
        $node = $request->attributes->get('node');

        if (!$node) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json($registry->getHealthReport($node));
    }

    public function refreshToken(Request $request, NodeAuthService $authService)
    {
        $validated = $request->validate([
            'refresh_token' => 'required|string',
        ]);

        $result = $authService->refreshAccessToken($validated['refresh_token']);

        if (!$result) {
            return response()->json([
                'error' => 'Invalid refresh token',
            ], 401);
        }

        return response()->json($result);
    }

    protected function extractContent($model): string
    {
        if (method_exists($model, 'getVectorContent')) {
            return $model->getVectorContent();
        }

        $fields = ['content', 'body', 'description', 'text', 'title', 'name'];
        $content = [];

        foreach ($fields as $field) {
            if (isset($model->$field)) {
                $content[] = $model->$field;
            }
        }

        return implode(' ', $content);
    }

    protected function extractForwardedContextOptions(array $options): array
    {
        $forwarded = [];

        if (!empty($options['selected_entity']) && is_array($options['selected_entity'])) {
            $forwarded['selected_entity'] = $options['selected_entity'];
        } elseif (!empty($options['selected_entity_context']) && is_array($options['selected_entity_context'])) {
            $context = $options['selected_entity_context'];
            $entityId = isset($context['entity_id']) ? (int) $context['entity_id'] : 0;
            $entityType = isset($context['entity_type']) ? trim((string) $context['entity_type']) : '';
            if ($entityId > 0 && $entityType !== '') {
                $forwarded['selected_entity'] = [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'model_class' => $context['model_class'] ?? null,
                    'source_node' => $context['source_node'] ?? null,
                ];
            }
        }

        return $forwarded;
    }
}
