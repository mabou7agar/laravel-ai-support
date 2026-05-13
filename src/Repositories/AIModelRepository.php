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
}
