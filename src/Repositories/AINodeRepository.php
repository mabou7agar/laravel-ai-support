<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Database\Eloquent\Collection;
use LaravelAIEngine\Models\AINode;

class AINodeRepository
{
    public function recent(int $limit = 100): Collection
    {
        return AINode::query()
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function all(): Collection
    {
        return AINode::query()->get();
    }

    public function active(): Collection
    {
        return AINode::active()->get();
    }

    public function find(int $id): ?AINode
    {
        return AINode::query()->find($id);
    }

    public function findBySlugOrFail(string $slug): AINode
    {
        return AINode::query()->where('slug', $slug)->firstOrFail();
    }

    public function save(AINode $node): AINode
    {
        $node->save();

        return $node;
    }
}
