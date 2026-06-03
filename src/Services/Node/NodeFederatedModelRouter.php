<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Contracts\RAG\FederatedModelRouter;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\RAG\RAGModelMetadataService;

/**
 * Core node-aware model router for RAG structured-data failover.
 *
 * Holds the node ownership lookup + remote chat forwarding logic that was
 * formerly inlined in RAGDecisionEngine. Behavior is byte-identical.
 */
class NodeFederatedModelRouter implements FederatedModelRouter
{
    public function __construct(
        protected RAGModelMetadataService $modelMetadata
    ) {
    }

    /**
     * Route to node that has the requested model
     */
    public function routeForModel(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): ?array {
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
}
