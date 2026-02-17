<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeForwarder;
use LaravelAIEngine\Services\Node\NodeHttpClient;
use LaravelAIEngine\Services\Node\NodeNameMatcher;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class NodeRoutingCoordinator
{
    public function __construct(
        protected ?NodeRegistryService $nodeRegistry = null,
        protected ?NodeForwarder $nodeForwarder = null,
        protected ?AgentPolicyService $policyService = null
    ) {
    }

    public function routeDecision(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $fallbackToRag
    ): AgentResponse {
        $requestedResource = trim((string) ($decision['resource_name'] ?? ''));
        if ($requestedResource === '' || $requestedResource === 'local') {
            Log::channel('ai-engine')->warning('route_to_node decision without resource_name, falling back to RAG', [
                'message' => substr($message, 0, 120),
                'session_id' => $context->sessionId,
            ]);

            return $fallbackToRag($message, $context, $options);
        }

        $node = $this->resolveNodeForRouting($requestedResource);
        if (!$node) {
            Log::channel('ai-engine')->warning('route_to_node could not resolve target node', [
                'resource_name' => $requestedResource,
                'session_id' => $context->sessionId,
            ]);

            return AgentResponse::failure(
                message: $this->getPolicyService()->nodeNotFoundMessage($requestedResource),
                context: $context
            );
        }

        Log::channel('ai-engine')->info('Routing message to remote node', [
            'requested_resource' => $requestedResource,
            'resolved_node' => $node->slug,
            'session_id' => $context->sessionId,
        ]);

        return $this->forwardAsAgentResponse($node, $message, $context, $options, markContextAsRouted: true);
    }

    public function forwardAsAgentResponse(
        AINode $node,
        string $message,
        UnifiedActionContext $context,
        array $options,
        bool $markContextAsRouted = false
    ): AgentResponse {
        $forwardOptions = $this->buildNodeForwardOptions($options);
        $response = $this->getNodeForwarder()->forwardChat($node, $message, $context->sessionId, $forwardOptions, $context->userId);

        if ($response['success'] ?? false) {
            if ($markContextAsRouted) {
                $context->set('routed_to_node', [
                    'node_slug' => $node->slug,
                    'node_name' => $node->name,
                ]);
            }

            // Preserve entity metadata from child node response
            $nodeMetadata = $response['metadata'] ?? [];
            if (!empty($nodeMetadata['entity_ids']) && is_array($nodeMetadata['entity_ids'])) {
                $context->metadata['last_entity_list'] = [
                    'entity_ids' => $nodeMetadata['entity_ids'],
                    'entity_type' => $nodeMetadata['entity_type'] ?? 'item',
                    'entity_data' => $nodeMetadata['entity_data'] ?? [],
                    'start_position' => $nodeMetadata['start_position'] ?? 1,
                    'end_position' => $nodeMetadata['end_position'] ?? count($nodeMetadata['entity_ids']),
                    'from_node' => $node->slug,
                ];

                Log::channel('ai-engine')->info('NodeRoutingCoordinator: preserved entity metadata from child node', [
                    'node' => $node->slug,
                    'entity_count' => count($nodeMetadata['entity_ids']),
                    'entity_type' => $nodeMetadata['entity_type'] ?? 'item',
                ]);
            }

            $agentResponse = AgentResponse::success(
                message: (string) ($response['response'] ?? ''),
                context: $context,
                data: $response
            );

            // Attach entity metadata so AgentResponseConverter can propagate it
            if (!empty($nodeMetadata['entity_ids'])) {
                $agentResponse->metadata = array_merge($agentResponse->metadata ?? [], [
                    'entity_ids' => $nodeMetadata['entity_ids'],
                    'entity_type' => $nodeMetadata['entity_type'] ?? 'item',
                ]);
            }

            return $agentResponse;
        }

        Log::channel('ai-engine')->warning('route_to_node failed; skipping local fallback to avoid mixed-domain results', [
            'resolved_node' => $node->slug,
            'node_url' => $node->url,
            'error' => $response['error'] ?? 'Unknown error',
            'session_id' => $context->sessionId,
        ]);

        return AgentResponse::failure(
            message: $this->formatNodeRoutingFailureMessage($node->slug, $node->url, $response['error'] ?? null),
            data: $response,
            context: $context
        );
    }

    public function resolveNodeForRouting(string $resourceName): ?AINode
    {
        $resourceName = trim($resourceName);
        if ($resourceName === '') {
            return null;
        }

        // 1. Exact slug match
        $node = $this->getNodeRegistry()->getNode($resourceName);
        if ($node) {
            return $node;
        }

        // 2. Normalized slug/name match via NodeNameMatcher
        $nodes = $this->getNodeRegistry()->getAllNodes();

        $matchedNode = $nodes->first(function ($candidate) use ($resourceName) {
            return NodeNameMatcher::normalizedMatch((string) $candidate->slug, $resourceName)
                || NodeNameMatcher::normalizedMatch((string) $candidate->name, $resourceName);
        });
        if ($matchedNode) {
            return $matchedNode;
        }

        // 3. Collection ownership (handles singular/plural via NodeNameMatcher)
        $matchedByCollection = $this->getNodeRegistry()->findNodeForCollection($resourceName);
        if ($matchedByCollection) {
            return $matchedByCollection;
        }

        return null;
    }

    public function formatNodeRoutingFailureMessage(string $nodeSlug, ?string $nodeUrl, ?string $error = null): string
    {
        return $this->getPolicyService()->nodeUnreachableMessage($nodeSlug, $nodeUrl, $error);
    }

    protected function buildNodeForwardOptions(array $options): array
    {
        $userToken = $this->resolveUserTokenFromRequest();
        $forwardHeaders = $this->extractForwardableHeaders();

        $options['headers'] = array_merge($forwardHeaders, [
            'X-Forwarded-From-Node' => config('app.name'),
            'X-User-Token' => $userToken,
        ]);
        $options['user_token'] = $userToken;

        return $options;
    }

    protected function extractForwardableHeaders(): array
    {
        try {
            return NodeHttpClient::extractForwardableHeaders();
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->debug('NodeRoutingCoordinator: unable to extract forwardable headers', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    protected function resolveUserTokenFromRequest(): ?string
    {
        try {
            $request = request();
            if (!$request) {
                return null;
            }

            return $request->bearerToken();
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->debug('NodeRoutingCoordinator: request context unavailable for bearer token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function getNodeRegistry(): NodeRegistryService
    {
        if ($this->nodeRegistry === null) {
            $this->nodeRegistry = app(NodeRegistryService::class);
        }

        return $this->nodeRegistry;
    }

    protected function getNodeForwarder(): NodeForwarder
    {
        if ($this->nodeForwarder === null) {
            $this->nodeForwarder = app(NodeForwarder::class);
        }

        return $this->nodeForwarder;
    }

    protected function getPolicyService(): AgentPolicyService
    {
        if ($this->policyService === null) {
            try {
                $this->policyService = app(AgentPolicyService::class);
            } catch (\Throwable $e) {
                $this->policyService = new AgentPolicyService();
            }
        }

        return $this->policyService;
    }
}
