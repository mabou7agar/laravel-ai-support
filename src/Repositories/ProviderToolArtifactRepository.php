<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use LaravelAIEngine\Models\AIProviderToolArtifact;

class ProviderToolArtifactRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return AIProviderToolArtifact::query()
            ->with(['run', 'media'])
            ->when($filters['tool_run_id'] ?? null, fn ($query, int|string $runId) => $query->where('tool_run_id', $runId))
            ->when($filters['provider'] ?? null, fn ($query, string $provider) => $query->where('provider', $provider))
            ->when($filters['artifact_type'] ?? null, fn ($query, string $type) => $query->where('artifact_type', $type))
            ->when($filters['owner_type'] ?? null, fn ($query, string $type) => $query->where('owner_type', $type))
            ->when($filters['owner_id'] ?? null, fn ($query, string $id) => $query->where('owner_id', $id))
            ->when($filters['source'] ?? null, fn ($query, string $source) => $query->where('source', $source))
            ->latest()
            ->paginate(max(1, min(100, $perPage)));
    }

    public function forRun(int $runId): Collection
    {
        return AIProviderToolArtifact::query()
            ->with('media')
            ->where('tool_run_id', $runId)
            ->latest()
            ->get();
    }

    public function find(int|string|null $id): ?AIProviderToolArtifact
    {
        if ($id === null || $id === '') {
            return null;
        }

        return AIProviderToolArtifact::query()
            ->where('id', $id)
            ->orWhere('uuid', (string) $id)
            ->first();
    }

    public function findOrFail(int|string $id): AIProviderToolArtifact
    {
        $artifact = $this->find($id);
        if ($artifact === null) {
            throw new \InvalidArgumentException("Provider tool artifact [{$id}] was not found.");
        }

        return $artifact;
    }

    public function create(array $attributes): AIProviderToolArtifact
    {
        return AIProviderToolArtifact::create($attributes);
    }
}
