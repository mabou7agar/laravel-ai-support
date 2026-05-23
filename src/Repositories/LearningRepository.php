<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Database\Eloquent\Builder;
use LaravelAIEngine\DTOs\LearnedItemRecord;
use LaravelAIEngine\DTOs\LearningSearchResult;
use LaravelAIEngine\DTOs\LearningSourcePayload;
use LaravelAIEngine\DTOs\LearningSourceRecord;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Models\AILearnedItem;
use LaravelAIEngine\Models\AILearnSource;

class LearningRepository
{
    /**
     * @param array<int, array{kind: string, title?: string|null, content: string, metadata?: array, confidence?: float, position?: int}> $items
     */
    public function store(LearningSourceRequest $request, LearningSourcePayload $payload, array $items): LearningSourceRecord
    {
        $source = AILearnSource::query()->create([
            'source_type' => $request->sourceType,
            'source' => $request->source,
            'adapter' => $request->adapter,
            'type' => $request->type,
            'title' => $request->title ?? $payload->title,
            'content' => $payload->content,
            'summary' => mb_substr(trim(strip_tags($payload->content)), 0, 1000),
            'metadata' => array_replace_recursive($request->metadata, $payload->metadata),
            ...$request->scope(),
        ]);

        foreach ($items as $position => $item) {
            $source->items()->create([
                'kind' => (string) ($item['kind'] ?? 'section'),
                'title' => $item['title'] ?? null,
                'content' => (string) $item['content'],
                'metadata' => (array) ($item['metadata'] ?? []),
                'confidence' => (float) ($item['confidence'] ?? 0.7),
                'position' => (int) ($item['position'] ?? $position),
                ...$request->scope(),
            ]);
        }

        return $this->toSourceRecord($source->fresh('items'));
    }

    public function markIndexed(string $sourceId, string $vectorStoreId): void
    {
        AILearnSource::query()
            ->where('source_id', $sourceId)
            ->update([
                'vector_store_id' => $vectorStoreId,
                'indexed_at' => now(),
            ]);
    }

    /**
     * @return array<int, LearningSearchResult>
     */
    public function search(string $query, array $scope = [], int $limit = 5, ?string $type = null): array
    {
        $minimumScore = (float) config('ai-engine.learning.min_search_score', 0.0);
        $records = $this->scopedItemsQuery($scope, $type)
            ->with('source')
            ->latest('updated_at')
            ->limit(max(1, $limit * 6))
            ->get();

        $results = [];
        foreach ($records as $record) {
            if (!$record instanceof AILearnedItem || !$record->source instanceof AILearnSource) {
                continue;
            }

            $score = $this->score($query, $record);
            if ($score <= $minimumScore) {
                continue;
            }

            $item = $this->toItemRecord($record);
            $results[] = new LearningSearchResult(
                source: $this->toSourceRecord($record->source),
                item: $item,
                score: $score,
            );
        }

        usort($results, static fn (LearningSearchResult $a, LearningSearchResult $b): int => $b->score <=> $a->score);

        return array_slice($results, 0, max(1, $limit));
    }

    protected function scopedItemsQuery(array $scope, ?string $type = null): Builder
    {
        return AILearnedItem::query()
            ->whereHas('source', function (Builder $builder) use ($scope, $type): void {
                if ($type !== null && $type !== '') {
                    $builder->where('type', $type);
                }

                foreach (['user_id', 'tenant_id', 'workspace_id', 'session_id'] as $key) {
                    $value = $scope[$key] ?? null;
                    if ($value === null || $value === '') {
                        $builder->whereNull($key);
                        continue;
                    }

                    if ((bool) config('ai-engine.learning.include_global_in_scoped_search', false)) {
                        $builder->where(fn (Builder $inner): Builder => $inner->whereNull($key)->orWhere($key, $value));
                        continue;
                    }

                    $builder->where($key, $value);
                }
            });
    }

    public function toSourceRecord(AILearnSource $source): LearningSourceRecord
    {
        return new LearningSourceRecord(
            sourceId: (string) $source->source_id,
            sourceType: (string) $source->source_type,
            source: (string) $source->source,
            type: (string) $source->type,
            title: $source->title,
            adapter: $source->adapter,
            metadata: $source->metadata ?? [],
            content: $source->content,
            userId: $source->user_id,
            tenantId: $source->tenant_id,
            workspaceId: $source->workspace_id,
            sessionId: $source->session_id,
            vectorStoreId: $source->vector_store_id,
        );
    }

    protected function toItemRecord(AILearnedItem $item): LearnedItemRecord
    {
        return new LearnedItemRecord(
            itemId: (string) $item->item_id,
            kind: (string) $item->kind,
            content: (string) $item->content,
            title: $item->title,
            metadata: $item->metadata ?? [],
            confidence: (float) $item->confidence,
            position: (int) $item->position,
        );
    }

    protected function score(string $query, AILearnedItem $item): float
    {
        $queryTerms = $this->terms($query);
        $itemTerms = $this->terms(($item->title ?? '') . ' ' . $item->content . ' ' . ($item->source?->title ?? '') . ' ' . ($item->source?->type ?? ''));

        if ($queryTerms === [] || $itemTerms === []) {
            return (float) $item->confidence * 0.25;
        }

        $overlap = count(array_intersect($queryTerms, $itemTerms));
        if ($overlap === 0) {
            return 0.0;
        }

        $lexical = $overlap / max(1, count($queryTerms));

        return min(1.0, ($lexical * 0.75) + ((float) $item->confidence * 0.2) + 0.05);
    }

    /**
     * @return array<int, string>
     */
    protected function terms(string $text): array
    {
        $parts = preg_split('/[^\pL\pN]+/u', mb_strtolower($text)) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) < 3) {
                continue;
            }

            $terms[] = $part;
        }

        return array_values(array_unique($terms));
    }
}
