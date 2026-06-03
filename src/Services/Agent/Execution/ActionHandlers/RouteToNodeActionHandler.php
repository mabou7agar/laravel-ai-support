<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Execution\ActionHandlers;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Contracts\RoutingActionHandlerContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\NodeSessionManager;

class RouteToNodeActionHandler implements RoutingActionHandlerContract
{
    public function __construct(
        protected readonly NodeSessionManager $nodeSessionManager,
        /**
         * Resolves the dispatcher lazily to reuse its RAG search + policy-blocked
         * delegate methods, keeping that behavior byte-identical (audit, streaming,
         * policy resolution) and avoiding a constructor dependency cycle.
         *
         * @var callable(): AgentExecutionDispatcher
         */
        protected $dispatcher
    ) {
    }

    public function action(): string
    {
        return RoutingDecisionAction::ROUTE_TO_NODE;
    }

    public function handle(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options = [],
        ?callable $reroute = null
    ): AgentResponse {
        $dispatcher = ($this->dispatcher)();

        if (!empty($options['local_only'])) {
            Log::channel('ai-engine')->info('Local-only mode enabled, skipping remote routing', [
                'message' => substr($message, 0, 120),
                'session_id' => $context->sessionId,
            ]);

            return $dispatcher->searchRag($message, $context, $options, $reroute);
        }

        $requestedResource = trim((string) ($decision->payload['resource_name'] ?? $decision->payload['node_slug'] ?? ''));
        if ($requestedResource === '' || $requestedResource === 'local') {
            Log::channel('ai-engine')->warning('route_to_node decision without resource_name, falling back to RAG', [
                'message' => substr($message, 0, 120),
                'session_id' => $context->sessionId,
            ]);

            return $dispatcher->searchRag($message, $context, $options, $reroute);
        }

        if (!$dispatcher->policyService()->canRouteToNode($requestedResource, $options)) {
            return $dispatcher->blockedByPolicy('node', $requestedResource, $context, $options);
        }

        Log::channel('ai-engine')->info('Routing message to remote node', [
            'requested_resource' => $requestedResource,
            'session_id' => $context->sessionId,
        ]);

        $response = $this->nodeSessionManager->routeToNode($requestedResource, $message, $context, $options);
        if ($this->shouldFallbackToLocalRag($response, $options)) {
            Log::channel('ai-engine')->warning('Remote node routing failed; attempting degraded local fallback', [
                'requested_resource' => $requestedResource,
                'session_id' => $context->sessionId,
            ]);

            $fallback = $dispatcher->searchRag($message, $context, array_merge($options, [
                'local_only' => true,
            ]), $reroute);

            if ($fallback->success) {
                $fallback->message = $this->fallbackNotice() . "\n\n" . $fallback->message;
                $fallback->metadata = array_merge($fallback->metadata ?? [], [
                    'fallback_mode' => true,
                    'fallback_reason' => 'remote_node_unreachable',
                    'original_resource' => $requestedResource,
                ]);

                return $fallback;
            }
        }

        return $response;
    }

    protected function shouldFallbackToLocalRag(AgentResponse $response, array $options): bool
    {
        if ($response->success) {
            return false;
        }

        $enabled = array_key_exists('allow_local_fallback_on_node_failure', $options)
            ? (bool) $options['allow_local_fallback_on_node_failure']
            : (bool) config('ai-engine.nodes.routing.local_fallback_on_failure', false);

        if (!$enabled) {
            return false;
        }

        return str_contains(strtolower($response->message), "couldn't reach remote node");
    }

    protected function fallbackNotice(): string
    {
        $notice = config('ai-engine.nodes.routing.local_fallback_notice');
        if (is_string($notice) && trim($notice) !== '') {
            return trim($notice);
        }

        return 'Remote node is unavailable. Showing local results only (degraded mode).';
    }
}
