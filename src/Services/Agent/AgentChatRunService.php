<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;

class AgentChatRunService
{
    public function __construct(
        private readonly AgentRunRepository $runs
    ) {}

    public function start(array $payload): array
    {
        if (!config('ai-agent.chat.async_enabled', true)) {
            throw new \RuntimeException('Async agent chat is disabled.');
        }

        $message = trim((string) ($payload['message'] ?? ''));
        $sessionId = trim((string) ($payload['session_id'] ?? ''));

        if ($message === '') {
            throw new \InvalidArgumentException('Agent chat message is required.');
        }

        if ($sessionId === '') {
            throw new \InvalidArgumentException('Agent chat session_id is required.');
        }

        $userId = $payload['user_id'] ?? null;
        $options = (array) ($payload['options'] ?? []);
        $scope = $this->scopeFrom($payload, $options);
        $options = array_merge($options, array_filter($scope, static fn (mixed $value): bool => $value !== null && $value !== ''));
        $options['_idempotency_key'] ??= (string) Str::uuid();

        $run = $this->runs->create([
            'session_id' => $sessionId,
            'user_id' => $userId !== null ? (string) $userId : null,
            'tenant_id' => $scope['tenant_id'] ?? null,
            'workspace_id' => $scope['workspace_id'] ?? null,
            'runtime' => (string) ($options['agent_runtime'] ?? config('ai-agent.runtime.default', 'laravel')),
            'status' => AIAgentRun::STATUS_PENDING,
            'input' => [
                'message' => $message,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'options' => Arr::except($options, ['_idempotency_key']),
            ],
            'metadata' => array_filter([
                'created_from' => 'agent_chat',
                'estimated_tokens' => $options['estimated_tokens'] ?? null,
                'estimated_cost' => $options['estimated_cost'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        ]);

        RunAgentJob::dispatch($run->id, $message, $sessionId, $userId, $options);

        return [
            'queued' => true,
            'run' => $this->runPayload($run),
            'agent_run_id' => $run->uuid,
            'status_url' => $this->runUrl($run),
            'trace_url' => $this->runUrl($run, '/trace'),
            'stream_url' => $this->runUrl($run, '/stream'),
            'broadcast' => $this->broadcastPayload($run),
        ];
    }

    private function scopeFrom(array $payload, array $options): array
    {
        $scope = (array) ($payload['scope'] ?? $options['scope'] ?? []);

        return [
            'tenant_id' => $payload['tenant_id'] ?? $options['tenant_id'] ?? $scope['tenant_id'] ?? null,
            'workspace_id' => $payload['workspace_id'] ?? $options['workspace_id'] ?? $scope['workspace_id'] ?? null,
        ];
    }

    private function runPayload(AIAgentRun $run): array
    {
        return [
            'uuid' => $run->uuid,
            'status' => $run->status,
            'session_id' => $run->session_id,
            'user_id' => $run->user_id,
            'tenant_id' => $run->tenant_id,
            'workspace_id' => $run->workspace_id,
            'runtime' => $run->runtime,
        ];
    }

    private function runUrl(AIAgentRun $run, string $suffix = ''): string
    {
        return "/api/v1/ai/agent-runs/{$run->uuid}{$suffix}";
    }

    private function broadcastPayload(AIAgentRun $run): array
    {
        $enabled = (bool) config('ai-agent.event_stream.broadcast.enabled', false);
        $prefix = trim((string) config('ai-agent.event_stream.broadcast.channel_prefix', 'agent-run'), '.');
        $private = (bool) config('ai-agent.event_stream.broadcast.private', true);
        $channel = "{$prefix}.{$run->uuid}";

        return [
            'enabled' => $enabled,
            'driver' => $enabled ? config('broadcasting.default') : null,
            'channel' => $private ? "private-{$channel}" : $channel,
            'events' => app(AgentRunEventStreamService::class)->names(),
        ];
    }
}
