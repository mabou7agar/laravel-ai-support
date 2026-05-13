<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use LaravelAIEngine\Models\AIProviderToolApproval;

class ProviderToolApprovalRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return AIProviderToolApproval::query()
            ->with('run')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['provider'] ?? null, fn ($query, string $provider) => $query->where('provider', $provider))
            ->when($filters['tool_name'] ?? null, fn ($query, string $toolName) => $query->where('tool_name', $toolName))
            ->latest()
            ->paginate(max(1, min(100, $perPage)));
    }

    public function create(array $attributes): AIProviderToolApproval
    {
        return AIProviderToolApproval::create($attributes);
    }

    public function findByKey(string $approvalKey): ?AIProviderToolApproval
    {
        return AIProviderToolApproval::query()
            ->where('approval_key', $approvalKey)
            ->first();
    }

    public function findByKeyOrFail(string $approvalKey): AIProviderToolApproval
    {
        $approval = $this->findByKey($approvalKey);
        if ($approval === null) {
            throw new \InvalidArgumentException("Provider tool approval [{$approvalKey}] was not found.");
        }

        return $approval;
    }

    public function pendingForRunAndTool(int $runId, string $toolName): ?AIProviderToolApproval
    {
        return AIProviderToolApproval::query()
            ->where('tool_run_id', $runId)
            ->where('tool_name', $toolName)
            ->where('status', 'pending')
            ->first();
    }

    public function approvedForRunAndTool(int $runId, string $toolName): ?AIProviderToolApproval
    {
        return AIProviderToolApproval::query()
            ->where('tool_run_id', $runId)
            ->where('tool_name', $toolName)
            ->where('status', 'approved')
            ->first();
    }

    public function approvedByKeys(int $runId, array $approvalKeys): Collection
    {
        return AIProviderToolApproval::query()
            ->where('tool_run_id', $runId)
            ->whereIn('approval_key', array_values(array_filter($approvalKeys)))
            ->where('status', 'approved')
            ->get();
    }

    public function update(AIProviderToolApproval $approval, array $attributes): AIProviderToolApproval
    {
        $approval->update($attributes);

        return $approval->refresh();
    }
}
