<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\Agent\Routing\RoutingPipeline;
use LaravelAIEngine\Services\Agent\RoutingContextResolver;
use LaravelAIEngine\DTOs\RoutingTrace;

/**
 * Native Laravel agent runtime processor.
 */
class LaravelAgentProcessor
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
        protected ?GoalAgentService $goalAgent = null,
        protected ?AgentExecutionDispatcher $executionDispatcher = null,
        protected ?RoutingPipeline $routingPipeline = null
    ) {
        $this->messageClassifier ??= app()->bound(MessageRoutingClassifier::class)
            ? app(MessageRoutingClassifier::class)
            : new MessageRoutingClassifier();
        $this->routingContextResolver ??= app()->bound(RoutingContextResolver::class)
            ? app(RoutingContextResolver::class)
            : new RoutingContextResolver();
        $this->executionDispatcher ??= new AgentExecutionDispatcher($this->execution, $this->goalAgent());
    }

    public function process(
        string $message,
        string $sessionId,
        $userId,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->debug('LaravelAgentProcessor processing', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message' => substr($message, 0, 100),
        ]);

        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        $context->addUserMessage($message);

        if ($this->shouldUseGoalAgent($options)) {
            return $this->finalizeDirect(
                $context,
                $this->dispatchRoutingDecision(new RoutingDecision(
                    action: RoutingDecisionAction::RUN_SUB_AGENT,
                    source: RoutingDecisionSource::EXPLICIT,
                    confidence: 'high',
                    reason: 'Goal-agent execution requested explicitly.',
                    payload: [
                        'target' => (string) ($options['target'] ?? $message),
                        'sub_agents' => $options['sub_agents'] ?? null,
                    ]
                ), $message, $context, $options)
            );
        }

        // RULE 1: Active session? Continue it (no AI needed)
        if ($context->has('autonomous_collector')) {
            Log::channel('ai-engine')->debug('Continuing active collector session');
            return $this->continueCollector($message, $context, $options);
        }

        if ($context->has('routed_to_node')) {
            if ($this->execution->shouldContinueRoutedSession($message, $context)) {
                Log::channel('ai-engine')->debug('Continuing routed node session');
                $response = $this->dispatchRoutingDecision(new RoutingDecision(
                    action: RoutingDecisionAction::CONTINUE_NODE,
                    source: RoutingDecisionSource::SESSION,
                    confidence: 'high',
                    reason: 'Continuing active routed node session.'
                ), $message, $context, $options);
                if ($response->success || $response->message !== 'No routed node session is available to continue.') {
                    if ($this->shouldFallbackToLocalRag($response, $options)) {
                        Log::channel('ai-engine')->warning('Remote node follow-up failed; attempting degraded local fallback', [
                            'session_id' => $context->sessionId,
                            'message' => substr($message, 0, 120),
                        ]);

                        $context->forget('routed_to_node');
                        $context->forget('remote_pending_action');
                        if (is_array($context->pendingAction) && ($context->pendingAction['type'] ?? null) === 'remote_node_session') {
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
            if (is_array($context->pendingAction) && ($context->pendingAction['type'] ?? null) === 'remote_node_session') {
                $context->pendingAction = null;
            }
        }

        return $this->routeThroughPipeline($message, $context, $options);
    }

    protected function continueCollector(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $response = $this->dispatchRoutingDecision(new RoutingDecision(
            action: RoutingDecisionAction::CONTINUE_COLLECTOR,
            source: RoutingDecisionSource::SESSION,
            confidence: 'high',
            reason: 'Continuing active collector session.'
        ), $message, $context, $options);

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
                'matched_skill' => $decision['metadata'] ?? null,
            ]);

            Log::channel('ai-engine')->debug('AI orchestration decision', [
                'message' => substr($message, 0, 100),
                'action' => $decision['action'],
                'resource' => $decision['resource_name'],
                'reason' => substr($decision['reason'] ?? '', 0, 100),
            ]);

            $response = $this->dispatchRoutingDecision(
                $this->routingDecisionFromPlannerDecision($decision),
                $message,
                $context,
                $decisionOptions
            );

            return $this->responseFinalizer->finalize($context, $response);

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AI orchestration failed', [
                'error' => $e->getMessage(),
            ]);

            $response = $this->searchRag($message, $context, $options);

            return $this->responseFinalizer->finalize($context, $response);
        }
    }

    protected function routeThroughPipeline(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        if (!$this->routingPipeline instanceof RoutingPipeline) {
            return $this->heuristicRoute($message, $context, $options);
        }

        try {
            $options = $this->routingContextResolver->mergeConversationContext($context, $options);
            $trace = $this->routingPipeline->decide($message, $context, $options);
            $decision = $trace->selected ?? new RoutingDecision(
                action: RoutingDecisionAction::CONVERSATIONAL,
                source: RoutingDecisionSource::FALLBACK,
                confidence: 'high',
                reason: 'Routing pipeline produced no selected decision.'
            );

            Log::channel('ai-engine')->debug('Routing pipeline selected decision', [
                'session_id' => $context->sessionId,
                'action' => $decision->action,
                'source' => $decision->source,
                'reason' => substr($decision->reason, 0, 120),
            ]);

            return $this->finalizeDirect(
                $context,
                $this->dispatchRoutingDecision(
                    $decision,
                    $message,
                    $context,
                    $this->optionsForRoutingDecision($decision, $options),
                    $trace
                )
            );
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Routing pipeline failed', [
                'error' => $e->getMessage(),
            ]);

            $response = $this->searchRag($message, $context, $options);

            return $this->responseFinalizer->finalize($context, $response);
        }
    }

    protected function heuristicRoute(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        if (!empty($options['force_rag'])) {
            Log::channel('ai-engine')->debug('Force RAG enabled, bypassing intent router', [
                'session_id' => $context->sessionId,
                'message' => substr($message, 0, 120),
            ]);

            return $this->finalizeDirect(
                $context,
                $this->dispatchRoutingDecision(new RoutingDecision(
                    action: RoutingDecisionAction::SEARCH_RAG,
                    source: RoutingDecisionSource::EXPLICIT,
                    confidence: 'high',
                    reason: 'RAG was forced by caller options.'
                ), $message, $context, array_merge($options, [
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
                $this->dispatchRoutingDecision(new RoutingDecision(
                    action: RoutingDecisionAction::CONVERSATIONAL,
                    source: RoutingDecisionSource::CLASSIFIER,
                    confidence: 'high',
                    reason: (string) $classification['reason']
                ), $message, $context, array_merge($options, [
                    'decision_path' => 'heuristic_conversational',
                    'decision_source' => $classification['source'],
                ]))
            );
        }

        if ($classification['route'] === 'search_rag') {
            return $this->finalizeDirect(
                $context,
                $this->dispatchRoutingDecision(new RoutingDecision(
                    action: RoutingDecisionAction::SEARCH_RAG,
                    source: RoutingDecisionSource::CLASSIFIER,
                    confidence: 'high',
                    reason: (string) $classification['reason'],
                    payload: ['route_mode' => $classification['mode']]
                ), $message, $context, array_merge($options, [
                    'preclassified_route_mode' => $classification['mode'],
                    'decision_path' => 'heuristic_' . $classification['mode'],
                    'decision_source' => $classification['source'],
                ]))
            );
        }

        return $this->askAI($message, $context, $options);
    }

    protected function searchRag(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->dispatchRoutingDecision(new RoutingDecision(
            action: RoutingDecisionAction::SEARCH_RAG,
            source: RoutingDecisionSource::RUNTIME,
            confidence: 'high',
            reason: 'RAG execution requested by orchestrator callback.'
        ), $message, $context, $options);
    }

    protected function routeToNode(
        string $resourceName,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->dispatchRoutingDecision(new RoutingDecision(
            action: RoutingDecisionAction::ROUTE_TO_NODE,
            source: RoutingDecisionSource::RUNTIME,
            confidence: 'high',
            reason: 'Node routing requested by orchestrator callback.',
            payload: ['resource_name' => $resourceName]
        ), $message, $context, $options);
    }

    protected function dispatchRoutingDecision(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options = [],
        ?RoutingTrace $trace = null
    ): AgentResponse {
        $response = $this->executionDispatcher->dispatch(
            $decision,
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

        $response->metadata = array_merge($response->metadata ?? [], [
            'routing_decision' => $decision->toArray(),
            'routing_trace' => $trace instanceof RoutingTrace
                ? array_map(static fn (RoutingDecision $candidate): array => $candidate->toArray(), $trace->decisions)
                : [$decision->toArray()],
            'route_explanation' => $this->routeExplanation($decision, $options),
        ]);

        return $response;
    }

    protected function optionsForRoutingDecision(RoutingDecision $decision, array $options): array
    {
        $classification = is_array($decision->metadata['classification'] ?? null)
            ? $decision->metadata['classification']
            : [];

        $decisionPath = match ($decision->source) {
            RoutingDecisionSource::EXPLICIT => $decision->action === RoutingDecisionAction::SEARCH_RAG
                ? 'forced_rag'
                : 'explicit_' . $decision->action,
            RoutingDecisionSource::CLASSIFIER => $decision->action === RoutingDecisionAction::CONVERSATIONAL
                ? 'heuristic_conversational'
                : 'heuristic_' . (string) ($decision->payload['mode'] ?? $decision->payload['route_mode'] ?? 'classified'),
            RoutingDecisionSource::AI_ROUTER => 'router_ai_' . (string) ($decision->payload['route_action'] ?? $decision->action),
            RoutingDecisionSource::FALLBACK => 'fallback_' . $decision->action,
            default => (string) ($options['decision_path'] ?? $decision->source . '_' . $decision->action),
        };

        return array_merge($options, array_filter([
            'decision_path' => $options['decision_path'] ?? $decisionPath,
            'decision_source' => $options['decision_source'] ?? $classification['source'] ?? $decision->metadata['decision_source'] ?? $decision->source,
            'preclassified_route_mode' => $options['preclassified_route_mode'] ?? $decision->payload['mode'] ?? $decision->payload['route_mode'] ?? null,
            'matched_skill' => $options['matched_skill'] ?? $decision->metadata['matched_skill'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    protected function routeExplanation(RoutingDecision $decision, array $options): array
    {
        return array_filter([
            'action' => $decision->action,
            'source' => $decision->source,
            'confidence' => $decision->confidence,
            'reason' => $decision->reason,
            'decision_path' => $options['decision_path'] ?? null,
            'decision_source' => $options['decision_source'] ?? $decision->metadata['decision_source'] ?? null,
            'route_mode' => $decision->payload['route_mode'] ?? $options['preclassified_route_mode'] ?? null,
            'skipped_stages' => $decision->metadata['skipped_stages'] ?? null,
            'payload_keys' => array_keys($decision->payload),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    protected function routingDecisionFromPlannerDecision(array $decision): RoutingDecision
    {
        $plan = $this->planner->plan($decision);

        return new RoutingDecision(
            action: $this->routingActionFromPlannerAction((string) $plan['action']),
            source: $this->routingSourceFromDecisionSource((string) ($decision['decision_source'] ?? 'router_ai')),
            confidence: 'high',
            reason: (string) ($decision['reasoning'] ?? $decision['reason'] ?? $plan['reasoning']),
            payload: array_merge($plan, [
                'original_action' => $decision['action'] ?? null,
                'metadata' => $decision['metadata'] ?? null,
            ]),
            metadata: [
                'decision_source' => $decision['decision_source'] ?? null,
            ]
        );
    }

    protected function routingActionFromPlannerAction(string $action): string
    {
        return match ($action) {
            'start_collector' => RoutingDecisionAction::START_COLLECTOR,
            'use_tool' => RoutingDecisionAction::USE_TOOL,
            'resume_session' => RoutingDecisionAction::CONTINUE_RUN,
            'pause_and_handle' => RoutingDecisionAction::PAUSE_AND_HANDLE,
            'route_to_node' => RoutingDecisionAction::ROUTE_TO_NODE,
            'conversational' => RoutingDecisionAction::CONVERSATIONAL,
            'search_rag' => RoutingDecisionAction::SEARCH_RAG,
            default => RoutingDecisionAction::SEARCH_RAG,
        };
    }

    protected function routingSourceFromDecisionSource(string $source): string
    {
        return match ($source) {
            'forced' => RoutingDecisionSource::EXPLICIT,
            'heuristic', 'classifier' => RoutingDecisionSource::CLASSIFIER,
            'fallback', 'heuristic_fallback' => RoutingDecisionSource::FALLBACK,
            default => RoutingDecisionSource::AI_ROUTER,
        };
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

    protected function shouldUseGoalAgent(array $options): bool
    {
        if (!config('ai-agent.goal_agent.enabled', true)) {
            return false;
        }

        return !empty($options['agent_goal'])
            || !empty($options['goal_agent'])
            || !empty($options['sub_agents']);
    }

    protected function goalAgent(): GoalAgentService
    {
        if ($this->goalAgent === null) {
            $this->goalAgent = app(GoalAgentService::class);
        }

        return $this->goalAgent;
    }

}
