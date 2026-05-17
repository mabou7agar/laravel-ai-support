<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Database\Eloquent\Builder;
use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\DTOs\ConversationMemoryResult;
use LaravelAIEngine\Models\AIConversationMemory;

class ConversationMemoryRepository
{
    public function upsert(ConversationMemoryItem $item): ConversationMemoryItem
    {
        $memory = AIConversationMemory::query()->firstOrNew([
            'namespace' => $item->namespace,
            'key' => $item->key,
            'user_id' => $item->userId,
            'tenant_id' => $item->tenantId,
            'workspace_id' => $item->workspaceId,
            'session_id' => $item->sessionId,
        ]);

        $memory->fill([
            'memory_id' => $item->memoryId ?? $memory->memory_id,
            'value' => $item->value,
            'summary' => $item->summary,
            'metadata' => $item->metadata,
            'confidence' => $item->confidence,
            'last_seen_at' => $item->lastSeenAt ?? now(),
            'expires_at' => $item->expiresAt,
        ]);
        $memory->save();

        return $this->toItem($memory);
    }

    /**
     * @return array<int, ConversationMemoryResult>
     */
    public function search(ConversationMemoryQuery $query): array
    {
        $records = $this->scopedQuery($query)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('confidence')
            ->limit(max(1, $query->limit * 5))
            ->get();

        $results = [];
        foreach ($records as $record) {
            $item = $this->toItem($record);
            $results[] = new ConversationMemoryResult(
                item: $item,
                score: $this->score($query->message, $item),
                reason: 'sql_lexical'
            );
        }

        usort($results, static function (ConversationMemoryResult $a, ConversationMemoryResult $b): int {
            return $b->score <=> $a->score;
        });

        return array_slice($results, 0, max(1, $query->limit));
    }

    public function findByMemoryId(string $memoryId): ?ConversationMemoryItem
    {
        $record = AIConversationMemory::query()->where('memory_id', $memoryId)->first();

        return $record instanceof AIConversationMemory ? $this->toItem($record) : null;
    }

    /**
     * @param array<int, string> $memoryIds
     * @return array<string, ConversationMemoryItem>
     */
    public function findScopedByMemoryIds(array $memoryIds, ConversationMemoryQuery $query): array
    {
        $memoryIds = array_values(array_filter(array_unique(array_map('strval', $memoryIds))));
        if ($memoryIds === []) {
            return [];
        }

        $records = $this->scopedQuery($query)
            ->whereIn('memory_id', $memoryIds)
            ->get();

        $items = [];
        foreach ($records as $record) {
            $item = $this->toItem($record);
            if ($item->memoryId !== null) {
                $items[$item->memoryId] = $item;
            }
        }

        return $items;
    }

    public function forgetScope(ConversationMemoryQuery $query): int
    {
        return $this->scopedQuery($query)->delete();
    }

    protected function scopedQuery(ConversationMemoryQuery $query): Builder
    {
        return AIConversationMemory::query()
            ->when($query->namespace !== null, fn (Builder $builder): Builder => $builder->where('namespace', $query->namespace))
            ->where(fn (Builder $builder): Builder => $builder->whereNull('user_id')->orWhere('user_id', $query->userId))
            ->where(fn (Builder $builder): Builder => $builder->whereNull('tenant_id')->orWhere('tenant_id', $query->tenantId))
            ->where(fn (Builder $builder): Builder => $builder->whereNull('workspace_id')->orWhere('workspace_id', $query->workspaceId))
            ->where(fn (Builder $builder): Builder => $builder->whereNull('session_id')->orWhere('session_id', $query->sessionId))
            ->where(fn (Builder $builder): Builder => $builder->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    protected function toItem(AIConversationMemory $memory): ConversationMemoryItem
    {
        return ConversationMemoryItem::fromArray([
            'id' => $memory->getKey(),
            'memory_id' => $memory->memory_id,
            'namespace' => $memory->namespace,
            'key' => $memory->key,
            'value' => $memory->value,
            'summary' => $memory->summary,
            'user_id' => $memory->user_id,
            'tenant_id' => $memory->tenant_id,
            'workspace_id' => $memory->workspace_id,
            'session_id' => $memory->session_id,
            'confidence' => $memory->confidence,
            'metadata' => $memory->metadata ?? [],
            'last_seen_at' => $memory->last_seen_at,
            'expires_at' => $memory->expires_at,
        ]);
    }

    protected function score(string $message, ConversationMemoryItem $item): float
    {
        $messageTerms = $this->terms($message);
        $memoryTerms = $this->terms($item->summary . ' ' . (string) $item->value . ' ' . $item->key . ' ' . $item->namespace);

        if ($messageTerms === [] || $memoryTerms === []) {
            return $item->confidence * 0.25;
        }

        $overlap = count(array_intersect($messageTerms, $memoryTerms));
        $lexical = $overlap / max(1, count(array_unique($messageTerms)));

        return min(1.0, ($lexical * 0.65) + ($item->confidence * 0.25) + 0.15);
    }

    /**
     * @return array<int, string>
     */
    protected function terms(string $text): array
    {
        $normalized = mb_strtolower($text);
        $parts = preg_split('/[^\pL\pN]+/u', $normalized) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) < 3) {
                continue;
            }

            $terms[] = $this->stem($part);
        }

        return array_values(array_unique($terms));
    }

    protected function stem(string $term): string
    {
        if (str_ends_with($term, 'ies')) {
            return mb_substr($term, 0, -3) . 'y';
        }

        foreach (['ing', 'ed', 'es', 's'] as $suffix) {
            if (mb_strlen($term) > mb_strlen($suffix) + 2 && str_ends_with($term, $suffix)) {
                return mb_substr($term, 0, -mb_strlen($suffix));
            }
        }

        return $term;
    }
}
