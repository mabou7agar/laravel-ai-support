<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\RAGSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\Agent\Routing\RoutingPipeline;
use LaravelAIEngine\Services\RAG\RAGContextBuilder;

class BenchmarkOrchestrationV2Command extends Command
{
    protected $signature = 'ai:benchmark-orchestration-v2
                            {--path= : JSON benchmark fixture file path}
                            {--iterations=25 : Iterations per benchmark group}
                            {--json : Print JSON report}';

    protected $description = 'Benchmark v2 routing and RAG context construction with threshold reporting';

    public function handle(RoutingPipeline $pipeline, RAGContextBuilder $contextBuilder, MessageRoutingClassifier $classifier): int
    {
        $fixture = $this->loadFixture($this->fixturePath());
        $iterations = max(1, (int) $this->option('iterations'));

        $classifierBaseline = $this->benchmarkClassifierBaseline($classifier, (array) ($fixture['routing_messages'] ?? []), $iterations);
        $routing = $this->benchmarkRouting($pipeline, (array) ($fixture['routing_messages'] ?? []), $iterations);
        $rag = $this->benchmarkRag($contextBuilder, (array) ($fixture['rag_sources'] ?? []), $iterations);
        $thresholds = is_array($fixture['thresholds'] ?? null) ? $fixture['thresholds'] : [];

        $report = [
            'iterations' => $iterations,
            'classifier_baseline' => $classifierBaseline,
            'routing' => $routing,
            'rag_context' => $rag,
            'thresholds' => [
                'routing_avg_ms' => [
                    'value' => $routing['avg_ms'],
                    'threshold' => $thresholds['routing_avg_ms'] ?? null,
                    'passed' => !isset($thresholds['routing_avg_ms']) || $routing['avg_ms'] <= (float) $thresholds['routing_avg_ms'],
                ],
                'rag_context_avg_ms' => [
                    'value' => $rag['avg_ms'],
                    'threshold' => $thresholds['rag_context_avg_ms'] ?? null,
                    'passed' => !isset($thresholds['rag_context_avg_ms']) || $rag['avg_ms'] <= (float) $thresholds['rag_context_avg_ms'],
                ],
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Benchmark', 'Total ms', 'Avg ms', 'Min ms', 'Max ms'], [
                ['classifier baseline', $classifierBaseline['total_ms'], $classifierBaseline['avg_ms'], $classifierBaseline['min_ms'], $classifierBaseline['max_ms']],
                ['v2 runtime routing', $routing['total_ms'], $routing['avg_ms'], $routing['min_ms'], $routing['max_ms']],
                ['v2 RAG context', $rag['total_ms'], $rag['avg_ms'], $rag['min_ms'], $rag['max_ms']],
            ]);

            $this->table(['Threshold', 'Value', 'Limit', 'Status'], array_map(
                static fn (string $name, array $threshold): array => [
                    $name,
                    (string) $threshold['value'],
                    $threshold['threshold'] === null ? 'not set' : (string) $threshold['threshold'],
                    $threshold['passed'] ? 'PASS' : 'WARN',
                ],
                array_keys($report['thresholds']),
                $report['thresholds']
            ));
        }

        return self::SUCCESS;
    }

    protected function fixturePath(): string
    {
        $path = trim((string) ($this->option('path') ?? ''));

        return $path !== '' ? $path : dirname(__DIR__, 3) . '/resources/fixtures/orchestration-v2/benchmark.json';
    }

    protected function loadFixture(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Benchmark fixture file [{$path}] was not found.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException("Benchmark fixture file [{$path}] is invalid.");
        }

        return $decoded;
    }

    protected function benchmarkRouting(RoutingPipeline $pipeline, array $messages, int $iterations): array
    {
        return $this->measure($iterations, function () use ($pipeline, $messages): void {
            foreach ($messages as $index => $message) {
                $pipeline->decide((string) $message, new UnifiedActionContext('benchmark-routing-' . $index), [
                    'force_rag' => true,
                    'rag_collections' => ['App\\Models\\Document'],
                ]);
            }
        });
    }

    protected function benchmarkClassifierBaseline(MessageRoutingClassifier $classifier, array $messages, int $iterations): array
    {
        return $this->measure($iterations, static function () use ($classifier, $messages): void {
            foreach ($messages as $message) {
                $classifier->classify((string) $message, ['rag_collections' => ['App\\Models\\Document']]);
            }
        });
    }

    protected function benchmarkRag(RAGContextBuilder $contextBuilder, array $sources, int $iterations): array
    {
        $ragSources = array_map(static fn (mixed $source): RAGSource => RAGSource::fromMixed($source), $sources);

        return $this->measure($iterations, static function () use ($contextBuilder, $ragSources): void {
            $contextBuilder->build($ragSources);
        });
    }

    protected function measure(int $iterations, callable $callback): array
    {
        $samples = [];
        $startedTotal = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $started = microtime(true);
            $callback();
            $samples[] = round((microtime(true) - $started) * 1000, 4);
        }

        $total = round((microtime(true) - $startedTotal) * 1000, 4);

        return [
            'total_ms' => $total,
            'avg_ms' => round(array_sum($samples) / max(1, count($samples)), 4),
            'min_ms' => min($samples),
            'max_ms' => max($samples),
        ];
    }
}
