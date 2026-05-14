<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Services\Agent\AgentRunPayloadSchemaVersioner;

class AgentRunRepository
{
    public function __construct(
        protected ?AgentRunPayloadSchemaVersioner $schemaVersioner = null
    ) {
    }

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return AIAgentRun::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['session_id'] ?? null, fn ($query, string $sessionId) => $query->where('session_id', $sessionId))
            ->when($filters['user_id'] ?? null, fn ($query, string $userId) => $query->where('user_id', $userId))
            ->when($filters['tenant_id'] ?? null, fn ($query, string $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($filters['workspace_id'] ?? null, fn ($query, string $workspaceId) => $query->where('workspace_id', $workspaceId))
            ->latest()
            ->paginate(max(1, min(100, $perPage)));
    }

    public function create(array $attributes): AIAgentRun
    {
        $attributes['uuid'] ??= (string) Str::uuid();
        $attributes['status'] ??= AIAgentRun::STATUS_PENDING;
        $attributes['runtime'] ??= 'laravel';
        $attributes = $this->schema()->normalizeRunAttributes($attributes);

        return AIAgentRun::create($attributes);
    }

    public function find(int|string|null $id): ?AIAgentRun
    {
        if ($id === null || $id === '') {
            return null;
        }

        return AIAgentRun::query()
            ->where('id', $id)
            ->orWhere('uuid', (string) $id)
            ->first();
    }

    public function findOrFail(int|string $id): AIAgentRun
    {
        $run = $this->find($id);
        if ($run === null) {
            throw new \InvalidArgumentException("Agent run [{$id}] was not found.");
        }

        return $run;
    }

    public function findActiveBySession(string $sessionId, ?string $userId = null): ?AIAgentRun
    {
        return AIAgentRun::query()
            ->where('session_id', $sessionId)
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->whereIn('status', [
                AIAgentRun::STATUS_PENDING,
                AIAgentRun::STATUS_RUNNING,
                AIAgentRun::STATUS_WAITING_APPROVAL,
                AIAgentRun::STATUS_WAITING_INPUT,
            ])
            ->latest()
            ->first();
    }

    public function update(AIAgentRun $run, array $attributes): AIAgentRun
    {
        $attributes = $this->schema()->normalizeRunAttributes(array_merge([
            'schema_version' => $run->schema_version,
        ], $attributes));

        $run->update($attributes);

        return $run->refresh();
    }

    public function transition(AIAgentRun $run, string $status, array $attributes = []): AIAgentRun
    {
        if (!in_array($status, AIAgentRun::STATUSES, true)) {
            throw new \InvalidArgumentException("Unsupported agent run status [{$status}].");
        }

        $attributes['status'] = $status;

        return $this->update($run, $attributes);
    }

    protected function schema(): AgentRunPayloadSchemaVersioner
    {
        return $this->schemaVersioner ??= app()->bound(AgentRunPayloadSchemaVersioner::class)
            ? app(AgentRunPayloadSchemaVersioner::class)
            : new AgentRunPayloadSchemaVersioner();
    }
}
