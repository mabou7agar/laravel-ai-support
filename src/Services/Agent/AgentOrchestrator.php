<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Support\Facades\Log;

/**
 * AI-Driven Orchestrator
 *
 * Reduces hardcoded logic by 75% - AI handles all routing decisions.
 *
 * Only 2 hardcoded rules:
 * 1. If active session exists → continue it
 * 2. Otherwise → ask AI everything
 */
class AgentOrchestrator
{
    public function __construct(
        protected ContextManager $contextManager,
        protected IntentRouter $intentRouter,
        protected AgentPlanner $planner,
        protected AgentResponseFinalizer $responseFinalizer,
        protected AgentSelectionService $selectionService,
        protected AgentExecutionFacade $execution,
        protected ?MessageRoutingClassifier $messageClassifier = null,
        protected ?RoutingContextResolver $routingContextResolver = null,
        protected ?DeterministicAgentHandlerRegistry $deterministicHandlers = null
    ) {
        $this->messageClassifier ??= app()->bound(MessageRoutingClassifier::class)
            ? app(MessageRoutingClassifier::class)
            : new MessageRoutingClassifier();
        $this->routingContextResolver ??= app()->bound(RoutingContextResolver::class)
            ? app(RoutingContextResolver::class)
            : new RoutingContextResolver();
        $this->deterministicHandlers ??= app()->bound(DeterministicAgentHandlerRegistry::class)
            ? app(DeterministicAgentHandlerRegistry::class)
            : null;
    }

    public function process(
        string $message,
        string $sessionId,
        $userId,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->debug('AgentOrchestrator processing', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message' => substr($message, 0, 100),
        ]);

        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        $context->addUserMessage($message);

        if ($this->deterministicHandlers) {
            $deterministicResponse = $this->deterministicHandlers->handle($message, $context, $options);
            if ($deterministicResponse) {
                Log::channel('ai-engine')->debug('Deterministic agent handler matched', [
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'strategy' => $deterministicResponse->strategy,
                ]);

                return $this->finalizeDirect($context, $deterministicResponse);
            }
        }

        // RULE 0: Check for option selection from previous message
        // This must come before other routing to avoid treating "1" as a position query
        if ($this->selectionService->detectsOptionSelection($message, $context)) {
            Log::channel('ai-engine')->debug('Detected option selection from previous message');
            $response = $this->selectionService->handleOptionSelection(
                $message,
                $context,
                $options,
                fn (string $searchMessage, UnifiedActionContext $searchContext, array $searchOptions) => $this->searchRag(
                    $searchMessage,
                    $searchContext,
                    $searchOptions
                ),
                fn (string $resourceName, string $routeMessage, UnifiedActionContext $routeContext, array $routeOptions) => $this->execution->routeToNode(
                    $resourceName,
                    $routeMessage,
                    $routeContext,
                    $routeOptions
                )
            );
            if ($response) {
                $this->contextManager->save($context);
                return $response;
            }
        }

        // RULE 1: Active session? Continue it (no AI needed)
        if ($context->has('autonomous_collector')) {
            Log::channel('ai-engine')->debug('Continuing active collector session');
            return $this->continueCollector($message, $context, $options);
        }

        if ($context->has('routed_to_node')) {
            if ($this->execution->shouldContinueRoutedSession($message, $context)) {
                Log::channel('ai-engine')->debug('Continuing routed node session');
                $response = $this->execution->continueRoutedSession($message, $context, $options);
                if ($response) {
                    if ($this->shouldFallbackToLocalRag($response, $options)) {
                        Log::channel('ai-engine')->warning('Remote node follow-up failed; attempting degraded local fallback', [
                            'session_id' => $context->sessionId,
                            'message' => substr($message, 0, 120),
                        ]);

                        $context->forget('routed_to_node');
                        $context->forget('remote_pending_action');
                        if (is_array($context->pendingAction) && ($context->pendingAction['type'] ?? null) === 'remote_node_workflow') {
                            $context->pendingAction = null;
                        }
                        $fallback = $this->searchRag($message, $context, array_merge($options, [
                            'local_only' => true,
                        ]));

                        if ($fallback->success) {
                            $fallback->message = $this->fallbackNotice() . "\n\n" . $fallback->message;
                            return $this->responseFinalizer->finalize($context, $fallback);
                        }
                    }

                    return $response;
                }
            }

            Log::channel('ai-engine')->debug('New topic detected, clearing routed node session', [
                'previous_node' => $context->get('routed_to_node')['node_slug'] ?? 'unknown',
                'new_message' => substr($message, 0, 100),
            ]);
            $context->forget('routed_to_node');
            $context->forget('remote_pending_action');
            if (is_array($context->pendingAction) && ($context->pendingAction['type'] ?? null) === 'remote_node_workflow') {
                $context->pendingAction = null;
            }
        }

        // RULE 2a: Skip AI decision if flagged (from RAG exit_to_orchestrator)
        if (!empty($options['skip_ai_decision'])) {
            Log::channel('ai-engine')->debug('Skipping AI decision and delegating to collector matcher', [
                'message' => substr($message, 0, 100),
            ]);

            return $this->execution->handleSkipDecision($message, $context, $options);
        }

        // RULE 2b: Check for positional reference to previous list
        if ($this->selectionService->detectsPositionalReference($message, $context)) {
            Log::channel('ai-engine')->debug('Detected positional reference to previous list');
            return $this->selectionService->handlePositionalReference(
                $message,
                $context,
                $options,
                fn (string $searchMessage, UnifiedActionContext $searchContext, array $searchOptions) => $this->searchRag(
                    $searchMessage,
                    $searchContext,
                    $searchOptions
                ),
                fn (string $nodeSlug, string $nodeMessage, UnifiedActionContext $nodeContext, array $nodeOptions) => $this->execution->routeToNode(
                    $nodeSlug,
                    $nodeMessage,
                    $nodeContext,
                    $nodeOptions
                )
            );
        }

        if (!empty($options['force_rag'])) {
            Log::channel('ai-engine')->debug('Force RAG enabled, bypassing intent router', [
                'session_id' => $context->sessionId,
                'message' => substr($message, 0, 120),
            ]);

            return $this->finalizeDirect(
                $context,
                $this->searchRag($message, $context, array_merge($options, [
                    'preclassified_route_mode' => 'semantic_retrieval',
                    'decision_path' => 'forced_rag',
                    'decision_source' => 'forced',
                ]))
            );
        }

        $options = $this->routingContextResolver->mergeConversationContext($context, $options);
        $classification = $this->messageClassifier->classify(
            $message,
            $this->routingContextResolver->signalsFromContext($context, $options)
        );

        Log::channel('ai-engine')->debug('Deterministic message classification', [
            'session_id' => $context->sessionId,
            'route' => $classification['route'],
            'mode' => $classification['mode'],
            'reason' => $classification['reason'],
        ]);

        if ($classification['route'] === 'conversational') {
            return $this->finalizeDirect(
                $context,
                $this->executeConversational($message, $context, array_merge($options, [
                    'decision_path' => 'heuristic_conversational',
                    'decision_source' => $classification['source'],
                ]))
            );
        }

        if ($classification['route'] === 'search_rag') {
            return $this->finalizeDirect(
                $context,
                $this->searchRag($message, $context, array_merge($options, [
                    'preclassified_route_mode' => $classification['mode'],
                    'decision_path' => 'heuristic_' . $classification['mode'],
                    'decision_source' => $classification['source'],
                ]))
            );
        }

        // RULE 2: No active session? Ask AI everything
        return $this->askAI($message, $context, $options);
    }

    protected function continueCollector(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $response = $this->execution->continueCollectorSession($message, $context, $options);

        // Check if collector wants to exit and reroute
        if ($response->message === 'exit_and_reroute') {
            Log::channel('ai-engine')->debug('Collector exited - rerouting message', [
                'original_message' => $message,
            ]);

            // Re-process the message as a fresh query (collector already cleared state)
            return $this->askAI($message, $context, $options);
        }

        return $this->responseFinalizer->finalize($context, $response);
    }

    protected function askAI(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        try {
            $decision = $this->intentRouter->route($message, $context, $options);
            $decisionOptions = array_merge($options, [
                'decision_path' => 'router_ai_' . ($decision['action'] ?? 'unknown'),
                'decision_source' => $decision['decision_source'] ?? 'router_ai',
            ]);

            Log::channel('ai-engine')->debug('AI orchestration decision', [
                'message' => substr($message, 0, 100),
                'action' => $decision['action'],
                'resource' => $decision['resource_name'],
                'reason' => substr($decision['reason'] ?? '', 0, 100),
            ]);

            $response = $this->planner->dispatch($decision, [
                'start_collector' => fn (array $plan) => $this->executeStartCollector($plan, $message, $context, $decisionOptions),
                'use_tool' => fn (array $plan) => $this->executeUseTool($plan, $message, $context, $decisionOptions),
                'resume_session' => fn (array $plan) => $this->executeResumeSession($message, $context, $decisionOptions),
                'pause_and_handle' => fn (array $plan) => $this->executePauseAndHandle($message, $context, $decisionOptions),
                'route_to_node' => fn (array $plan) => $this->executeRouteToNode($plan, $message, $context, $decisionOptions),
                'search_rag' => fn (array $plan) => $this->searchRag($message, $context, $decisionOptions),
                'conversational' => fn (array $plan) => $this->executeConversational($message, $context, $decisionOptions),
            ]);

            return $this->responseFinalizer->finalize($context, $response);

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AI orchestration failed', [
                'error' => $e->getMessage(),
            ]);

            $response = $this->searchRag($message, $context, $options);

            return $this->responseFinalizer->finalize($context, $response);
        }
    }

    protected function executeUseTool(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->execution->executeUseTool(
            (string) ($decision['resource_name'] ?? ''),
            $message,
            $context,
            array_merge($options, [
                'tool_params' => is_array($decision['params'] ?? null) ? $decision['params'] : [],
            ]),
            fn (string $searchMessage, UnifiedActionContext $searchContext, array $searchOptions) => $this->searchRag(
                $searchMessage,
                $searchContext,
                $searchOptions
            )
        );
    }

    protected function executeStartCollector(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->execution->executeStartCollector(
            (string) ($decision['resource_name'] ?? ''),
            $message,
            $context,
            $options,
            fn (string $resourceName, string $routeMessage, UnifiedActionContext $routeContext, array $routeOptions) => $this->execution->routeToNode(
                $resourceName,
                $routeMessage,
                $routeContext,
                $routeOptions
            )
        );
    }

    protected function executeResumeSession(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->execution->executeResumeSession($context);
    }

    protected function executePauseAndHandle(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->execution->executePauseAndHandle(
            $message,
            $context,
            $options,
            fn (string $searchMessage, UnifiedActionContext $searchContext, array $searchOptions) => $this->searchRag(
                $searchMessage,
                $searchContext,
                $searchOptions
            )
        );
    }

    protected function executeRouteToNode(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        if (!empty($options['local_only'])) {
            Log::channel('ai-engine')->info('Local-only mode enabled, skipping remote routing', [
                'message' => substr($message, 0, 120),
                'session_id' => $context->sessionId,
            ]);
            return $this->searchRag($message, $context, $options);
        }

        $requestedResource = trim((string) ($decision['resource_name'] ?? ''));
        if ($requestedResource === '' || $requestedResource === 'local') {
            Log::channel('ai-engine')->warning('route_to_node decision without resource_name, falling back to RAG', [
                'message' => substr($message, 0, 120),
                'session_id' => $context->sessionId,
            ]);
            return $this->searchRag($message, $context, $options);
        }

        Log::channel('ai-engine')->info('Routing message to remote node', [
            'requested_resource' => $requestedResource,
            'session_id' => $context->sessionId,
        ]);

        $response = $this->execution->routeToNode($requestedResource, $message, $context, $options);
        if ($this->shouldFallbackToLocalRag($response, $options)) {
            Log::channel('ai-engine')->warning('Remote node routing failed; attempting degraded local fallback', [
                'requested_resource' => $requestedResource,
                'session_id' => $context->sessionId,
            ]);

            $fallback = $this->searchRag($message, $context, array_merge($options, [
                'local_only' => true,
            ]));

            if ($fallback->success) {
                $fallback->message = $this->fallbackNotice() . "\n\n" . $fallback->message;
                return $fallback;
            }
        }

        return $response;
    }

    protected function searchRag(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->execution->executeSearchRag(
            $message,
            $context,
            $options,
            fn (string $rerouteMessage, string $sessionId, $userId, array $rerouteOptions) => $this->process(
                $rerouteMessage,
                $sessionId,
                $userId,
                $rerouteOptions
            )
        );
    }

    protected function executeConversational(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->execution->executeConversational($message, $context, $options);
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

    protected function finalizeDirect(UnifiedActionContext $context, AgentResponse $response): AgentResponse
    {
        return $this->responseFinalizer->finalize($context, $response);
    }

}
