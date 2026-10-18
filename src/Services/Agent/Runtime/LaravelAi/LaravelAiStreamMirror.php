<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;

/**
 * Mirrors laravel/ai stream events into the package's agent run event stream
 * and implements cooperative cancellation.
 *
 * Event mapping (SDK `type` => package event):
 * - text_delta  => final_response.token_streamed
 * - tool_call   => tool.started
 * - tool_result => tool.completed | tool.failed
 * and final_response.stream_completed once the turn finishes.
 *
 * The SDK stream is consumed inside RunAgentJob while the run is locked, so a
 * cancel request only flips the run's status. The listener re-reads that
 * status on the first event, on every tool event and every N text deltas, and
 * returns false once the run is cancelled or expired, telling the gateway to
 * stop consuming the provider stream.
 */
final class LaravelAiStreamMirror
{
    private int $tokens = 0;

    private int $events = 0;

    private bool $cancelled = false;

    public function __construct(
        private readonly AgentRunEventStreamService $stream,
        private readonly array $options,
        private readonly int $checkEvery = 20
    ) {
    }

    /**
     * @return callable(array<string, mixed>): bool
     */
    public function listener(): callable
    {
        return function (array $event): bool {
            $this->events++;
            $type = (string) ($event['type'] ?? '');

            match ($type) {
                'text_delta' => $this->token((string) ($event['delta'] ?? '')),
                'tool_call' => $this->emit(AgentRunEventStreamService::TOOL_STARTED, [
                    'tool_name' => (string) ($event['tool_name'] ?? ''),
                    'tool_call_id' => $event['tool_id'] ?? null,
                    'arguments' => is_array($event['arguments'] ?? null) ? $event['arguments'] : [],
                ]),
                'tool_result' => $this->emit(
                    ($event['successful'] ?? false) === true
                        ? AgentRunEventStreamService::TOOL_COMPLETED
                        : AgentRunEventStreamService::TOOL_FAILED,
                    array_filter([
                        'tool_name' => (string) ($event['tool_name'] ?? ''),
                        'tool_call_id' => $event['tool_id'] ?? null,
                        'success' => ($event['successful'] ?? false) === true,
                        'denied' => ($event['denied'] ?? false) === true ? true : null,
                        'error' => $event['error'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null)
                ),
                default => null,
            };

            if ($this->shouldCheckCancellation($type) && $this->runWasCancelled()) {
                $this->cancelled = true;

                return false;
            }

            return true;
        };
    }

    public function finish(LaravelAiTurn $turn): void
    {
        if ($this->tokens === 0) {
            return;
        }

        $this->emit(AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED, array_filter([
            'content' => $turn->text,
            'token_count' => $this->tokens,
            'length' => strlen($turn->text),
            'cancelled' => ($turn->cancelled || $this->cancelled) ? true : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    private function token(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->tokens++;

        $this->emit(AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED, [
            'token' => $delta,
            'index' => $this->tokens,
            'length' => strlen($delta),
        ]);
    }

    private function emit(string $name, array $payload): void
    {
        $runId = $this->options['agent_run_id'] ?? null;
        if ($runId === null) {
            return;
        }

        try {
            $this->stream->emit(
                $name,
                $runId,
                $this->options['agent_run_step_id'] ?? null,
                $payload,
                array_filter([
                    'trace_id' => $this->options['trace_id'] ?? null,
                    'runtime' => LaravelAiSdk::RUNTIME,
                ], static fn (mixed $value): bool => $value !== null)
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('ai-engine')->warning('laravel_ai stream event mirroring failed', [
                'run_id' => $runId,
                'event' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldCheckCancellation(string $type): bool
    {
        if (($this->options['agent_run_id'] ?? null) === null) {
            return false;
        }

        return $this->events === 1
            || in_array($type, ['tool_call', 'tool_result', 'tool_approval_request'], true)
            || ($type === 'text_delta' && $this->tokens % max(1, $this->checkEvery) === 0);
    }

    private function runWasCancelled(): bool
    {
        $status = AIAgentRun::query()->whereKey($this->options['agent_run_id'])->value('status');

        return in_array($status, [AIAgentRun::STATUS_CANCELLED, AIAgentRun::STATUS_EXPIRED], true);
    }
}
