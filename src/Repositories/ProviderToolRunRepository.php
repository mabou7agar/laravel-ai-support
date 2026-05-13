<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use LaravelAIEngine\Models\AIProviderToolRun;

class ProviderToolRunRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return AIProviderToolRun::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['provider'] ?? null, fn ($query, string $provider) => $query->where('provider', $provider))
            ->when($filters['user_id'] ?? null, fn ($query, string $userId) => $query->where('user_id', $userId))
            ->latest()
            ->paginate(max(1, min(100, $perPage)));
    }

    public function create(array $attributes): AIProviderToolRun
    {
        return AIProviderToolRun::create($attributes);
    }

    public function find(int|string|null $id): ?AIProviderToolRun
    {
        if ($id === null || $id === '') {
            return null;
        }

        return AIProviderToolRun::query()
            ->where('id', $id)
            ->orWhere('uuid', (string) $id)
            ->first();
    }

    public function findOrFail(int|string $id): AIProviderToolRun
    {
        $run = $this->find($id);
        if ($run === null) {
            throw new \InvalidArgumentException("Provider tool run [{$id}] was not found.");
        }

        return $run;
    }

    public function update(AIProviderToolRun $run, array $attributes): AIProviderToolRun
    {
        $run->update($attributes);

        return $run->refresh();
    }
}
