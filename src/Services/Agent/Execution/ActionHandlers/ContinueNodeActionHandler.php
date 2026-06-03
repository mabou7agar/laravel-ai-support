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

class ContinueNodeActionHandler implements RoutingActionHandlerContract
{
    public function __construct(
        protected readonly NodeSessionManager $nodeSessionManager,
        /**
         * Resolves the dispatcher lazily so the degraded local-RAG fallback on a
         * failed continuation reuses the dispatcher's RAG search (policy + audit)
         * exactly, avoiding a constructor dependency cycle. Optional so existing
         * call sites that register the handler without a dispatcher keep working
         * (no fallback is attempted when it is absent).
         *
         * @var (callable(): AgentExecutionDispatcher)|null
         */
        protected $dispatcher = null
    ) {
    }

    public function action(): string
    {
        return RoutingDecisionAction::CONTINUE_NODE;
    }

    public function handle(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options = [],
        ?callable $reroute = null
    ): AgentResponse {
        $response = $this->nodeSessionManager->continueSession($message, $context, $options)
            ?? AgentResponse::failure(
                message: 'No routed node session is available to continue.',
                context: $context
            );

        if ($this->dispatcher !== null && $this->shouldFallbackToLocalRag($response, $options)) {
            Log::channel('ai-engine')->warning('Remote node follow-up failed; attempting degraded local fallback', [
                'session_id' => $context->sessionId,
                'message' => substr($message, 0, 120),
            ]);

            $context->forget('routed_to_node');
            $context->forget('remote_pending_action');
            if (is_array($context->pendingAction) && ($context->pendingAction['type'] ?? null) === 'remote_node_session') {
                $context->pendingAction = null;
            }

            $dispatcher = ($this->dispatcher)();
            $fallback = $dispatcher->searchRag($message, $context, array_merge($options, [
                'local_only' => true,
            ]), $reroute);

            if ($fallback->success) {
                $fallback->message = $this->fallbackNotice() . "\n\n" . $fallback->message;
                $fallback->metadata = array_merge($fallback->metadata ?? [], [
                    'fallback_mode' => true,
                    'fallback_reason' => 'remote_node_unreachable',
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
