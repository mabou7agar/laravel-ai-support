<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Contracts\Federation\NodeSessionContract;
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
        protected ?NodeSessionContract $nodeSession = null,
        protected ?MessageRoutingClassifier $messageClassifier = null,
        protected ?RoutingContextResolver $routingContextResolver = null,
        protected ?GoalAgentService $goalAgent = null,
        protected ?AgentExecutionDispatcher $executionDispatcher = null,
        protected ?RoutingPipeline $routingPipeline = null,
        protected ?AiNativeRuntime $aiNativeRuntime = null
    ) {
        $this->messageClassifier ??= app()->bound(MessageRoutingClassifier::class)
            ? app(MessageRoutingClassifier::class)
            : new MessageRoutingClassifier();
        $this->routingContextResolver ??= app()->bound(RoutingContextResolver::class)
            ? app(RoutingContextResolver::class)
            : new RoutingContextResolver();
        $this->executionDispatcher ??= app(AgentExecutionDispatcher::class);
        $this->aiNativeRuntime ??= app()->bound(AiNativeRuntime::class)
            ? app(AiNativeRuntime::class)
            : null;
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

        // Idempotency guard: when the client supplies an explicit idempotency_key, an
        // intentional retry of the same logical request must return the prior response
        // verbatim rather than being silently dropped (or re-executed). The cached
        // response is returned as-is and the retry is NOT treated as a new turn.
        $idempotencyKey = $this->idempotencyKey($options);
        if ($idempotencyKey !== null) {
            $cached = Cache::get($this->idempotencyCacheKey($idempotencyKey, $sessionId));
            if (is_array($cached)) {
                Log::channel('ai-engine')->debug('Returning idempotent replay of prior response', [
                    'session_id' => $sessionId,
                    'idempotency_key' => $idempotencyKey,
                ]);

                return $this->responseFromCache($cached);
            }
        }

        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        $this->hydrateConversationHistory($context, $options);

        // Dedup guard (fallback only — used when no idempotency_key is supplied): if the
        // client already included the current turn as the last entry in
        // conversation_history, skip addUserMessage to avoid a double-user-turn in the
        // context that would confuse the model (stateless multi-turn pattern).
        $lastHydrated = end($context->conversationHistory);
        $trimmedMessage = trim($message);
        $alreadyPresent = is_array($lastHydrated)
            && ($lastHydrated['role'] ?? null) === 'user'
            && $trimmedMessage !== ''
            && trim((string) ($lastHydrated['content'] ?? '')) === $trimmedMessage;

        if (!$alreadyPresent) {
            $context->addUserMessage($message);
        }

        if ($idempotencyKey !== null) {
            return $this->rememberIdempotentResponse(
                $idempotencyKey,
                $sessionId,
                $this->dispatchProcess($message, $context, $options)
            );
        }

        return $this->dispatchProcess($message, $context, $options);
    }

    /**
     * Core routing pipeline for a single turn, shared by direct and idempotent paths.
     */
    protected function dispatchProcess(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {

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
                ), $message, $context, $options),
                $options
            );
        }

        if (
            $this->shouldUseAiNativeRuntime($options)
            && !$context->has('routed_to_node')
        ) {
            return $this->finalizeDirect(
                $context,
                $this->aiNativeRuntime()->process($message, $context, $options),
                $options
            );
        }

        if ($this->nodeSession !== null && $context->has('routed_to_node')) {
            if ($this->nodeSession->shouldContinueSession($message, $context)) {
                Log::channel('ai-engine')->debug('Continuing routed node session');
                $continueDecision = new RoutingDecision(
                    action: RoutingDecisionAction::CONTINUE_NODE,
                    source: RoutingDecisionSource::SESSION,
                    confidence: 'high',
                    reason: 'Continuing active routed node session.'
                );
                $response = $this->dispatchRoutingDecision($continueDecision, $message, $context, $options);
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

                        // Record that CONTINUE_NODE was attempted and failed so the fallback
                        // SEARCH_RAG dispatch carries the full decision chain instead of
                        // appearing as a fresh, context-free RAG search.
                        $failedContinue = new RoutingDecision(
                            action: $continueDecision->action,
                            source: $continueDecision->source,
                            confidence: $continueDecision->confidence,
                            reason: $continueDecision->reason,
                            payload: $continueDecision->payload,
                            metadata: array_merge($continueDecision->metadata, [
                                'failed' => true,
                                'failure_reason' => $response->message,
                            ])
                        );
                        $fallback = $this->searchRag($message, $context, array_merge($options, [
                            'local_only' => true,
                        ]), new RoutingTrace([$failedContinue]));

                        if ($fallback->success) {
                            $fallback->message = $this->fallbackNotice() . "\n\n" . $fallback->message;
                            return $this->responseFinalizer->finalize($context, $fallback, $options);
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

    /**
     * Hydrate the context conversation history from a client-supplied replay array.
     *
     * Caller contract:
     *   - Each entry MUST be an array with a 'role' key (lowercase: 'system', 'user',
     *     'assistant', or 'tool') and a 'content' key.  Entries whose role is not
     *     lowercase will be silently dropped; normalise before passing.
     *   - The current user turn MUST NOT be included as the last entry; if it is, the
     *     dedup guard below will skip addUserMessage to avoid duplicating the turn.
     *
     * Re-entrant calls (e.g. RAG -> orchestrator reroutes) are safe: when NO fresh
     * conversation_history is supplied the existing context history is left untouched,
     * but when the client DOES supply a fresh history it is applied so updated turns
     * are not silently ignored. The dedup guard in process() prevents the current user
     * turn being duplicated after re-hydration.
     */
    protected function hydrateConversationHistory(UnifiedActionContext $context, array $options): void
    {
        $history = $options['conversation_history'] ?? [];

        // No fresh history supplied: keep whatever the context already carries (if any)
        // so re-entrant reroutes that omit conversation_history are non-destructive.
        if (!is_array($history) || $history === []) {
            return;
        }

        // Validate roles, normalise null content to '' and cap at the compactor limit.
        $maxMessages = max(2, (int) config('ai-agent.context_compaction.max_messages', 12));

        $filtered = array_values(array_filter(
            $history,
            static fn (mixed $message): bool => is_array($message)
                && in_array($message['role'] ?? null, ['system', 'user', 'assistant', 'tool'], true)
                && array_key_exists('content', $message)
        ));

        // Normalise null/array content to string so downstream code never sees a null.
        $filtered = array_map(static function (array $message): array {
            if ($message['content'] === null) {
                $message['content'] = '';
            } elseif (is_array($message['content'])) {
                // Multipart / vision content: extract text parts, preserve the rest.
                $text = implode(' ', array_filter(array_map(
                    static fn (mixed $part): string => is_array($part) && ($part['type'] ?? '') === 'text'
                        ? trim((string) ($part['text'] ?? ''))
                        : (is_string($part) ? trim($part) : ''),
                    $message['content']
                )));
                $message['content'] = $text;
            }

            return $message;
        }, $filtered);

        // If every supplied entry was invalid, do not clobber any existing context
        // history with an empty array — leave the prior history intact.
        if ($filtered === []) {
            return;
        }

        // Hard-cap the replayed history to avoid unbounded context growth.
        // Preserve the most-recent messages (tail of the array) so context is current.
        if (count($filtered) > $maxMessages) {
            $filtered = array_slice($filtered, -$maxMessages);
        }

        $context->conversationHistory = $filtered;
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
                'action' => $decision['action'] ?? null,
                'resource' => $decision['resource_name'] ?? null,
                'reason' => substr($decision['reason'] ?? '', 0, 100),
            ]);

            $response = $this->dispatchRoutingDecision(
                $this->routingDecisionFromPlannerDecision($decision),
                $message,
                $context,
                $decisionOptions
            );

            return $this->responseFinalizer->finalize($context, $response, $decisionOptions);

        } catch (\Throwable $e) {
            Log::channel('ai-engine')->error('AI orchestration failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            if (!$this->ragEnabledForRequest($options)) {
                return $this->responseFinalizer->finalize($context, AgentResponse::failure(
                    message: 'I could not route this request to an available action.',
                    context: $context
                ), $options);
            }

            $response = $this->searchRag($message, $context, $options);

            return $this->responseFinalizer->finalize($context, $response, $options);
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
                ),
                $options
            );
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->error('Routing pipeline failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            // The failed pipeline attempt may have merged a selected entity into
            // $options (mergeConversationContext only sets keys when unset). Drop
            // it so the heuristic fallback is not biased by a partial failed run.
            unset($options['selected_entity'], $options['selected_entity_context']);

            return $this->heuristicRoute($message, $context, $options);
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
                ])),
                $options
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
                ])),
                $options
            );
        }

        if ($classification['route'] === 'search_rag') {
            if (!$this->ragEnabledForRequest($options)) {
                return $this->askAI($message, $context, $options);
            }

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
                ])),
                $options
            );
        }

        return $this->askAI($message, $context, $options);
    }

    protected function searchRag(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?RoutingTrace $priorTrace = null
    ): AgentResponse {
        if (!$this->ragEnabledForRequest($options)) {
            return AgentResponse::failure(
                message: 'RAG is disabled for this request.',
                context: $context
            );
        }

        $ragDecision = new RoutingDecision(
            action: RoutingDecisionAction::SEARCH_RAG,
            source: RoutingDecisionSource::RUNTIME,
            confidence: 'high',
            reason: 'RAG execution requested by orchestrator callback.'
        );

        // When the caller supplies a prior trace (e.g. a failed CONTINUE_NODE), prepend
        // those decisions so the dispatched RAG response records the full routing chain.
        $trace = $priorTrace instanceof RoutingTrace
            ? $priorTrace->record($ragDecision)
            : null;

        return $this->dispatchRoutingDecision($ragDecision, $message, $context, $options, $trace);
    }

    protected function ragEnabledForRequest(array $options): bool
    {
        if (!empty($options['force_rag'])) {
            return true;
        }

        return !array_key_exists('use_rag', $options) || (bool) $options['use_rag'];
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
                // Strip the idempotency key on reroutes so a nested process() does not replay
                // or re-cache under the parent turn's key; the outer turn owns the key.
                array_diff_key($rerouteOptions, ['idempotency_key' => true])
            )
        );

        $existingMetadata = $response->metadata ?? [];

        // A nested process()/dispatch() (e.g. a SEARCH_RAG reroute) may have already stamped
        // its own routing_decision/routing_trace onto the response metadata. Preserve that
        // nested decision instead of silently overwriting it with the outer decision: the
        // nested decision is recorded under 'nested_routing_decision' and the outer trace is
        // prepended to the existing trace so the full routing chain is visible.
        $nestedDecision = $existingMetadata['routing_decision'] ?? null;
        $nestedTrace = is_array($existingMetadata['routing_trace'] ?? null)
            ? $existingMetadata['routing_trace']
            : [];

        $outerTrace = $trace instanceof RoutingTrace
            ? array_map(static fn (RoutingDecision $candidate): array => $candidate->toArray(), $trace->decisions)
            : [$decision->toArray()];

        $mergedMetadata = array_merge($existingMetadata, [
            'routing_decision' => $decision->toArray(),
            'routing_trace' => array_merge($outerTrace, $nestedTrace),
            'route_explanation' => $this->routeExplanation($decision, $options),
        ]);

        if ($nestedDecision !== null && $nestedDecision !== $decision->toArray()) {
            // Don't clobber a nested decision recorded by a deeper dispatch; chain it.
            $mergedMetadata['nested_routing_decision'] = $existingMetadata['nested_routing_decision'] ?? $nestedDecision;
        }

        $response->metadata = $mergedMetadata;

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
            'use_tool' => RoutingDecisionAction::USE_TOOL,
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

    /**
     * Extract a non-empty idempotency key from the request options, if supplied.
     */
    protected function idempotencyKey(array $options): ?string
    {
        $key = $options['idempotency_key'] ?? null;
        if (!is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    protected function idempotencyCacheKey(string $key, string $sessionId): string
    {
        return 'ai-agent:processor:idempotency:' . sha1($sessionId . '|' . $key);
    }

    protected function idempotencyTtl(): int
    {
        return max(1, (int) config('ai-agent.idempotency.ttl_seconds', 3600));
    }

    /**
     * Cache the response under the idempotency key so a later retry replays it verbatim.
     */
    protected function rememberIdempotentResponse(
        string $key,
        string $sessionId,
        AgentResponse $response
    ): AgentResponse {
        Cache::put(
            $this->idempotencyCacheKey($key, $sessionId),
            $response->toArray(),
            now()->addSeconds($this->idempotencyTtl())
        );

        return $response;
    }

    /**
     * Rebuild an AgentResponse from its cached array form (context is intentionally dropped;
     * a replay returns the prior payload, not a live context handle).
     *
     * @param array<string, mixed> $cached
     */
    protected function responseFromCache(array $cached): AgentResponse
    {
        $metadata = is_array($cached['metadata'] ?? null) ? $cached['metadata'] : [];
        $metadata['idempotent_replay'] = true;

        return new AgentResponse(
            success: (bool) ($cached['success'] ?? false),
            message: (string) ($cached['message'] ?? ''),
            data: is_array($cached['data'] ?? null) ? $cached['data'] : null,
            strategy: $cached['strategy'] ?? null,
            context: null,
            needsUserInput: (bool) ($cached['needs_user_input'] ?? false),
            actions: is_array($cached['actions'] ?? null) ? $cached['actions'] : null,
            metadata: $metadata,
            isComplete: (bool) ($cached['is_complete'] ?? false),
            nextStep: $cached['next_step'] ?? null,
            requiredInputs: is_array($cached['required_inputs'] ?? null) ? $cached['required_inputs'] : null
        );
    }

    /**
     * @param array<string, mixed> $options Current-request scope threaded into finalization/compaction.
     */
    protected function finalizeDirect(UnifiedActionContext $context, AgentResponse $response, array $options = []): AgentResponse
    {
        return $this->responseFinalizer->finalize($context, $response, $options);
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

    protected function shouldUseAiNativeRuntime(array $options): bool
    {
        if (empty($this->aiNativeRuntime)) {
            return false;
        }

        if (!empty($options['force_rag']) || !empty($options['goal_agent']) || !empty($options['sub_agents'])) {
            return false;
        }

        return (bool) config('ai-agent.ai_native.enabled', false);
    }

    protected function aiNativeRuntime(): AiNativeRuntime
    {
        return $this->aiNativeRuntime ??= app(AiNativeRuntime::class);
    }

    protected function goalAgent(): GoalAgentService
    {
        if ($this->goalAgent === null) {
            $this->goalAgent = app(GoalAgentService::class);
        }

        return $this->goalAgent;
    }

}
