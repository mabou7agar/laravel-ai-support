<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Support\Stubs;

use Closure;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiGateway;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiInvocation;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiTurn;

/**
 * Deterministic LaravelAiGateway for runtime parity tests.
 *
 * Plays back scripted turns (or throws scripted exceptions). When the
 * invocation streams, it emits SDK-shaped stream events for each turn
 * (tool_call, tool_result, tool_approval_request, text_delta per word,
 * stream_end) and honours a listener returning false the way the real
 * gateway does.
 */
final class FakeLaravelAiGateway implements LaravelAiGateway
{
    /** @var array<int, LaravelAiInvocation> */
    public array $invocations = [];

    /** @var array<int, int> events handed to the listener, per invocation */
    public array $consumedEvents = [];

    /** @var (Closure(int, array<string, mixed>): void)|null runs before each streamed event */
    public ?Closure $beforeEvent = null;

    /**
     * @param array<int, LaravelAiTurn|\Throwable|Closure(LaravelAiInvocation): LaravelAiTurn> $script
     */
    public function __construct(private array $script = [])
    {
    }

    public function push(LaravelAiTurn|\Throwable|Closure $next): self
    {
        $this->script[] = $next;

        return $this;
    }

    public function run(LaravelAiInvocation $invocation, ?callable $onEvent = null): LaravelAiTurn
    {
        $this->invocations[] = $invocation;
        $index = count($this->invocations) - 1;
        $this->consumedEvents[$index] = 0;

        $next = array_shift($this->script);
        if ($next === null) {
            throw new \RuntimeException('FakeLaravelAiGateway has no scripted turn left.');
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        $turn = $next instanceof Closure ? $next($invocation) : $next;

        if ($onEvent === null || !$invocation->stream) {
            return $turn;
        }

        $streamedText = '';

        foreach ($this->events($turn) as $position => $event) {
            if ($this->beforeEvent !== null) {
                ($this->beforeEvent)($position, $event);
            }

            $this->consumedEvents[$index]++;
            if (($event['type'] ?? null) === 'text_delta') {
                $streamedText .= $event['delta'];
            }

            if ($onEvent($event) === false) {
                return new LaravelAiTurn(
                    text: $streamedText,
                    usage: $turn->usage,
                    invocationId: $turn->invocationId,
                    conversationId: $invocation->conversationId,
                    provider: $turn->provider,
                    model: $turn->model,
                    cancelled: true
                );
            }
        }

        return $turn;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(LaravelAiTurn $turn): array
    {
        $events = [['type' => 'stream_start', 'provider' => $turn->provider, 'model' => $turn->model]];

        foreach ($turn->toolCalls as $call) {
            $events[] = ['type' => 'tool_call', 'tool_id' => $call['id'], 'tool_name' => $call['name'], 'arguments' => $call['arguments']];
        }

        foreach ($turn->toolResults as $result) {
            $events[] = [
                'type' => 'tool_result',
                'tool_id' => $result['id'],
                'tool_name' => $result['name'],
                'result' => $result['result'],
                'successful' => $result['successful'],
                'denied' => $result['denied'],
                'error' => $result['successful'] ? null : 'Tool failed.',
            ];
        }

        foreach (preg_split('/(?= )/', $turn->text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $events[] = ['type' => 'text_delta', 'delta' => $word];
        }

        if ($turn->hasPendingApprovals()) {
            $events[] = ['type' => 'tool_approval_request', 'approvals' => $turn->pendingApprovals];
        }

        $events[] = ['type' => 'stream_end', 'reason' => 'stop', 'usage' => $turn->usage];

        return $events;
    }
}
