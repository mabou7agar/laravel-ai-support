<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Database\Eloquent\Collection;
use LaravelAIEngine\Models\AIModel;

class AIModelRepository
{
    public function active(): Collection
    {
        return AIModel::active()->get();
    }

    public function findActiveByProviderAndModel(string $provider, string $modelId): ?AIModel
    {
        return AIModel::query()
            ->where('provider', $provider)
            ->where('model_id', $modelId)
            ->where('is_active', true)
            ->where('is_deprecated', false)
            ->first();
    }
}
