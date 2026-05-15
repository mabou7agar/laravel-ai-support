<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Support\Facades\Cache;

class GraphBenchmarkHistoryService
{
    public function record(string $type, array $payload): void
    {
        $type = $this->normalizeType($type);
        $entry = array_merge([
            'recorded_at' => now()->toIso8601String(),
            'type' => $type,
        ], $payload);

        $key = $this->historyKey($type);
        $history = Cache::get($key, []);
        $history = is_array($history) ? $history : [];
        array_unshift($history, $entry);
        $history = array_slice($history, 0, $this->historyLimit());

        Cache::put($key, $history, now()->addSeconds($this->historyTtl()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(string $type, int $limit = 10): array
    {
        $history = Cache::get($this->historyKey($type), []);
        if (!is_array($history)) {
            return [];
        }

        return array_slice($history, 0, max(1, $limit));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestAll(int $limitPerType = 10): array
    {
        $rows = [];
        foreach (['retrieval', 'chat', 'indexing'] as $type) {
            foreach ($this->latest($type, $limitPerType) as $entry) {
                $rows[] = $entry;
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));

        return $rows;
    }

    protected function historyKey(string $type): string
    {
        return 'ai_engine:graph:benchmark_history:' . $this->normalizeType($type);
    }

    protected function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['retrieval', 'chat', 'indexing'], true) ? $type : 'retrieval';
    }

    protected function historyLimit(): int
    {
        return max(10, (int) config('ai-engine.graph.benchmark.history_limit', 100));
    }

    protected function historyTtl(): int
    {
        return max(3600, (int) config('ai-engine.graph.benchmark.history_ttl', 604800));
    }
}
