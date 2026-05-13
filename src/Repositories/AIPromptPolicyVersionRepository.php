<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Database\Eloquent\Collection;
use LaravelAIEngine\Models\AIPromptPolicyVersion;

class AIPromptPolicyVersionRepository
{
    public function recent(int $limit = 100): Collection
    {
        return AIPromptPolicyVersion::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
