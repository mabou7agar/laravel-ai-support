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
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Contracts\Federation\NodeSessionContract;
use LaravelAIEngine\DTOs\RoutingTrace;

/**
 * Native Laravel agent runtime processor.
 */
class LaravelAgentProcessor
{
    public function __construct(
        protected ContextManager $contextManager,
        protected AgentResponseFinalizer $responseFinalizer,
        protected ?NodeSessionContract $nodeSession = null,
        protected ?AgentExecutionDispatcher $executionDispatcher = null,
        protected ?AiNativeRuntime $aiNativeRuntime = null
    ) {
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

        // Federation: an active routed_to_node session is the ONLY path that runs before
        // AiNative. It either continues the remote session (and returns), or detects a new
        // topic, clears the session, and falls through to AiNative below.
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

        // Goal-agent: a turn that explicitly declares a goal/sub-agents runs deterministically
        // through the intact GoalAgentService (plan -> sub-agents -> summary) and returns. This
        // is a single explicit capability dispatch inside the AiNative-owned processor — a
        // sibling to the federation branch above, NOT a revived routing brain. It runs without
        // an LLM round-trip so a declared goal executes the same way every time.
        if ($this->goalAgentRequested($options)) {
            $decision = new RoutingDecision(
                action: RoutingDecisionAction::RUN_SUB_AGENT,
                source: RoutingDecisionSource::EXPLICIT,
                confidence: 'high',
                reason: 'Request explicitly declared a goal/sub-agents.',
                payload: array_filter([
                    'target' => $this->goalTarget($message, $options),
                    'sub_agents' => $options['sub_agents'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== '')
            );

            return $this->finalizeDirect(
                $context,
                $this->dispatchRoutingDecision($decision, $message, $context, $options),
                $options
            );
        }

        // AiNative owns every turn that is not an active routed_to_node continuation.
        return $this->finalizeDirect(
            $context,
            $this->aiNativeRuntime()->process($message, $context, $options),
            $options
        );
    }

    protected function goalAgentRequested(array $options): bool
    {
        if (!(bool) config('ai-agent.goal_agent.enabled', true)) {
            return false;
        }

        return !empty($options['agent_goal'])
            || !empty($options['goal_agent'])
            || !empty($options['sub_agents']);
    }

    protected function goalTarget(string $message, array $options): string
    {
        foreach (['target', 'goal'] as $key) {
            $value = $options[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return trim($message);
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

    protected function searchRag(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?RoutingTrace $priorTrace = null
    ): AgentResponse {
        // No use_rag gate: AiNative owns the retrieval decision (the search_knowledge tool
        // is always available and the planner calls it when warranted). This method is the
        // degraded local-RAG fallback for a failed node continuation, so a caller-supplied
        // use_rag=false must not suppress it — the agent, not the flag, decides.
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

    protected function aiNativeRuntime(): AiNativeRuntime
    {
        return $this->aiNativeRuntime ??= app(AiNativeRuntime::class);
    }

}
