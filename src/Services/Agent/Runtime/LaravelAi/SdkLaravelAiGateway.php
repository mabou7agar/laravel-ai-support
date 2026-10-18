<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse as SdkAgentResponse;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

/**
 * The real LaravelAiGateway: runs a turn through a laravel/ai agent.
 *
 * Only constructed when laravel/ai is installed (LaravelAiSdk::installed()).
 */
class SdkLaravelAiGateway implements LaravelAiGateway
{
    public function run(LaravelAiInvocation $invocation, ?callable $onEvent = null): LaravelAiTurn
    {
        $agent = $this->agent();
        $this->bindConversation($agent, $invocation);

        $prompt = $invocation->decisions !== null
            ? Decisions::from($invocation->decisions)
            : $invocation->message;

        if ($invocation->stream) {
            return $this->stream($agent, $prompt, $invocation, $onEvent);
        }

        $response = $agent->prompt(
            $prompt,
            provider: $invocation->provider,
            model: $invocation->model,
            timeout: $invocation->timeout
        );

        return $this->fromResponse($response);
    }

    protected function stream(Agent $agent, Decisions|string $prompt, LaravelAiInvocation $invocation, ?callable $onEvent): LaravelAiTurn
    {
        $streamable = $agent->stream(
            $prompt,
            provider: $invocation->provider,
            model: $invocation->model,
            timeout: $invocation->timeout
        );

        $events = [];
        $cancelled = false;

        foreach ($streamable as $event) {
            $events[] = $event;

            if ($onEvent !== null && $onEvent($event->toArray()) === false) {
                $cancelled = true;

                break;
            }
        }

        $events = new Collection($events);
        $start = $events->last(static fn (object $event): bool => $event instanceof StreamStart);

        return new LaravelAiTurn(
            text: TextDelta::combine($events),
            usage: StreamEnd::combineUsage($events)->toArray(),
            invocationId: $streamable->invocationId,
            // A turn abandoned mid-stream was never stored, so keep the conversation we started from.
            conversationId: $cancelled ? $invocation->conversationId : ($streamable->conversationId ?? $invocation->conversationId),
            provider: $start instanceof StreamStart ? $start->provider : null,
            model: $start instanceof StreamStart ? $start->model : null,
            toolCalls: $events
                ->filter(static fn (object $event): bool => $event instanceof ToolCallEvent)
                ->map(fn (ToolCallEvent $event): array => $this->toolCall($event->toolCall))
                ->values()
                ->all(),
            toolResults: $events
                ->filter(static fn (object $event): bool => $event instanceof ToolResultEvent)
                ->map(fn (ToolResultEvent $event): array => $this->toolResult($event->toolResult))
                ->values()
                ->all(),
            pendingApprovals: $events
                ->filter(static fn (object $event): bool => $event instanceof ToolApprovalRequest)
                ->flatMap(fn (ToolApprovalRequest $event): array => $event->pendingApprovals
                    ->map(fn (PendingApproval $approval): array => $this->pendingApproval($approval))
                    ->all())
                ->values()
                ->all(),
            reasoning: ReasoningDelta::combine($events),
            cancelled: $cancelled
        );
    }

    protected function fromResponse(SdkAgentResponse $response): LaravelAiTurn
    {
        return new LaravelAiTurn(
            text: $response->text,
            usage: $response->usage->toArray(),
            invocationId: $response->invocationId,
            conversationId: $response->conversationId,
            provider: $response->meta->provider,
            model: $response->meta->model,
            toolCalls: $response->toolCalls->map(fn (ToolCallData $call): array => $this->toolCall($call))->values()->all(),
            toolResults: $response->toolResults->map(fn (ToolResultData $result): array => $this->toolResult($result))->values()->all(),
            pendingApprovals: $response->pendingApprovals->map(fn (PendingApproval $approval): array => $this->pendingApproval($approval))->values()->all(),
            reasoning: $response->reasoning
        );
    }

    protected function agent(): Agent
    {
        $class = config('ai-agent.runtime.laravel_ai.agent');

        if (is_string($class) && trim($class) !== '') {
            $agent = app()->make(ltrim(trim($class), '\\'));

            if (!$agent instanceof Agent) {
                throw new \InvalidArgumentException(
                    'ai-agent.runtime.laravel_ai.agent must reference a class implementing ' . Agent::class . '.'
                );
            }

            return $agent;
        }

        return new EngineAgent(
            instructions: (string) config('ai-agent.runtime.laravel_ai.instructions', ''),
            tools: $this->tools()
        );
    }

    /**
     * @return array<int, Tool>
     */
    protected function tools(): array
    {
        $tools = [];

        foreach ((array) config('ai-agent.runtime.laravel_ai.tools', []) as $class) {
            if (!is_string($class) || trim($class) === '') {
                continue;
            }

            $tool = app()->make(ltrim(trim($class), '\\'));
            if ($tool instanceof Tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    protected function bindConversation(Agent $agent, LaravelAiInvocation $invocation): void
    {
        if (!$this->remembersConversations($agent)) {
            return;
        }

        $userId = $invocation->userId;
        $participant = is_int($userId) || (is_string($userId) && trim($userId) !== '')
            ? new LaravelAiParticipant($userId)
            : null;

        if ($invocation->conversationId !== null) {
            $agent->continue($invocation->conversationId, as: $participant);

            return;
        }

        if ($participant !== null) {
            $agent->forParticipant($participant);
        }
    }

    protected function remembersConversations(Agent $agent): bool
    {
        return $agent instanceof RemembersConversationsContract
            || in_array(RemembersConversations::class, class_uses_recursive($agent), true);
    }

    /**
     * @return array{id:string,name:string,arguments:array<string,mixed>}
     */
    protected function toolCall(ToolCallData $call): array
    {
        return [
            'id' => $call->id,
            'name' => $call->name,
            'arguments' => $call->arguments,
        ];
    }

    /**
     * @return array{id:string,name:string,result:mixed,successful:bool,denied:bool}
     */
    protected function toolResult(ToolResultData $result): array
    {
        return [
            'id' => $result->id,
            'name' => $result->name,
            'result' => $result->result,
            'successful' => $result->successful(),
            'denied' => $result->denied,
        ];
    }

    /**
     * @return array{id:string,tool:string,arguments:array<string,mixed>,reason:?string}
     */
    protected function pendingApproval(PendingApproval $approval): array
    {
        return [
            'id' => $approval->id,
            'tool' => $approval->tool,
            'arguments' => $approval->arguments,
            'reason' => $approval->reason,
        ];
    }
}
