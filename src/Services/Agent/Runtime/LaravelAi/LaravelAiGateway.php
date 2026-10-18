<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

/**
 * The seam between the `laravel_ai` agent runtime and the laravel/ai SDK.
 *
 * LaravelAiAgentRuntime owns everything this package is responsible for (run
 * ownership, approvals, event mirroring, usage, cancellation); a gateway only
 * talks to the SDK. SdkLaravelAiGateway is the real implementation. Binding
 * this interface in the container replaces it, which is how tests run the
 * runtime deterministically without the SDK installed.
 */
interface LaravelAiGateway
{
    /**
     * Run one agent turn.
     *
     * When streaming, every SDK stream event is handed to $onEvent as its
     * array form (the SDK's StreamEvent::toArray() shape, keyed by `type`:
     * text_delta, tool_call, tool_result, tool_approval_request, stream_end,
     * error, ...). If $onEvent returns false the gateway must stop consuming
     * the stream and return what it has, with LaravelAiTurn::$cancelled set.
     *
     * Provider failures are thrown, not swallowed, so the queue job can fail
     * the run and retry it.
     *
     * @param (callable(array<string, mixed>): (bool|null))|null $onEvent
     */
    public function run(LaravelAiInvocation $invocation, ?callable $onEvent = null): LaravelAiTurn;
}
