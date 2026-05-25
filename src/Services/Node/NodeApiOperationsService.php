<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Node;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionOrchestrator;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;

class NodeApiOperationsService
{
    public function __construct(
        private readonly NodeManifestService $manifestService,
        private readonly ActionOrchestrator $actions,
        private readonly ChatService $chatService,
        private readonly InfrastructureHealthService $infrastructureHealth
    ) {}

    public function health(): JsonResponse
    {
        $health = $this->manifestService->health();
        $statusCode = (($health['status'] ?? 'healthy') === 'healthy' && ($health['ready'] ?? true))
            ? 200
            : 503;

        return response()->json($health, $statusCode);
    }

    public function manifest(): JsonResponse
    {
        return response()->json($this->manifestService->manifest());
    }

    public function collections(): JsonResponse
    {
        $collections = $this->manifestService->collections();

        return response()->json([
            'collections' => $collections,
            'count' => count($collections),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function search(array $validated): JsonResponse
    {
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
                    $document = app(SearchDocumentBuilder::class)->build($result);
                    $results[] = [
                        'id' => $result->id ?? null,
                        'content' => $result->matched_chunk_text ?? $this->extractContent($result),
                        'score' => $result->vector_score ?? 0,
                        'model_class' => $collection,
                        'model_type' => class_basename($collection),
                        'metadata' => [
                            'model_class' => $collection,
                            'model_type' => class_basename($collection),
                            'model_id' => $result->id ?? null,
                            'entity_ref' => $document->entityRef(),
                            'object' => $document->object,
                            'source_node' => $document->sourceNode,
                            'app_slug' => $document->appSlug,
                            'scope_type' => $document->scopeType,
                            'scope_id' => $document->scopeId,
                        ],
                        'vector_metadata' => $result->vector_metadata ?? [
                            'model_class' => $collection,
                            'model_type' => class_basename($collection),
                        ],
                        'title' => $result->title ?? $result->name ?? $result->subject ?? null,
                        'name' => $result->name ?? null,
                        'body' => $result->body ?? null,
                        'entity_ref' => $document->entityRef(),
                        'object' => $document->object,
                        'source_node' => $document->sourceNode,
                        'app_slug' => $document->appSlug,
                    ];
                }
            }

            return response()->json([
                'results' => $results,
                'count' => count($results),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function aggregate(array $validated): JsonResponse
    {
        $startTime = microtime(true);

        try {
            $aggregateData = [];
            $vectorSearch = app(VectorSearchService::class);

            foreach ($validated['collections'] as $collection) {
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
                    if (($validated['user_id'] ?? null) !== null) {
                        $filters['user_id'] = $validated['user_id'];
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

            return response()->json([
                'success' => true,
                'aggregate_data' => $aggregateData,
                'count' => count($aggregateData),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Aggregate query failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function chat(Request $request, array $validated): JsonResponse
    {
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
                useRag: $options['use_rag'] ?? true,
                ragCollections: $options['rag_collections'] ?? [],
                userId: $userId,
                searchInstructions: $options['search_instructions'] ?? null,
                conversationHistory: $options['conversation_history'] ?? [],
                extraOptions: $this->extractForwardedContextOptions($options)
            );

            $fullMetadata = $response->getMetadata();
            $essentialMetadata = [
                'session_active' => $fullMetadata['runtime_active'] ?? $fullMetadata['session_active'] ?? false,
                'session_completed' => $fullMetadata['runtime_completed'] ?? $fullMetadata['session_completed'] ?? false,
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
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
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

    public function executeTool(Request $request, array $validated): JsonResponse
    {
        try {
            $context = new UnifiedActionContext(
                sessionId: $validated['session_id'] ?? 'node-action-' . (string) Str::uuid(),
                userId: $request->user()?->id ?? ($validated['user_id'] ?? null)
            );
            $result = $this->actions
                ->execute($validated['action_type'], $validated['data'], true, $context)
                ->toArray();

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

    public function register(array $validated, NodeRegistryService $registry, NodeAuthService $authService): JsonResponse
    {
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

    public function status(Request $request, NodeRegistryService $registry): JsonResponse
    {
        $node = $request->attributes->get('node');

        if (!$node) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json($registry->getHealthReport($node));
    }

    public function refreshToken(array $validated, NodeAuthService $authService): JsonResponse
    {
        $result = $authService->refreshAccessToken($validated['refresh_token']);

        if (!$result) {
            return response()->json([
                'error' => 'Invalid refresh token',
            ], 401);
        }

        return response()->json($result);
    }

    private function extractContent($model): string
    {
        if (!empty($model->matched_chunk_text) && is_string($model->matched_chunk_text)) {
            return $model->matched_chunk_text;
        }

        if (method_exists($model, 'toRAGDetail')) {
            return $model->toRAGDetail();
        }

        $content = [];
        foreach (['content', 'body', 'description', 'text', 'title', 'name'] as $field) {
            if (isset($model->$field)) {
                $content[] = $model->$field;
            }
        }

        return implode(' ', $content);
    }

    private function extractForwardedContextOptions(array $options): array
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
                    'entity_ref' => $context['entity_ref'] ?? null,
                    'object' => $context['object'] ?? null,
                ];
            }
        }

        return $forwarded;
    }
}
