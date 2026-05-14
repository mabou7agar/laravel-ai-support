<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\RAGSource;
use LaravelAIEngine\Services\RAG\RAGContextBuilder;

class EvaluateRagFixturesCommand extends Command
{
    protected $signature = 'ai-engine:evaluate-rag-fixtures
                            {--path= : JSON fixture file path}
                            {--json : Print JSON report}';

    protected $description = 'Evaluate deterministic RAG context and citation fixtures';

    public function handle(RAGContextBuilder $contextBuilder): int
    {
        $results = [];

        foreach ($this->loadFixtures($this->fixturePath()) as $fixture) {
            $sources = array_map(static fn (mixed $source): RAGSource => RAGSource::fromMixed($source), (array) ($fixture['sources'] ?? []));
            $context = $contextBuilder->build($sources);
            $results[] = $this->evaluateFixture($fixture, $context, $sources);
        }

        $passed = collect($results)->every(static fn (array $result): bool => $result['passed']);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'passed' => $passed,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Fixture', 'Status', 'Citations', 'Source Types', 'Missing Text'], array_map(
                static fn (array $result): array => [
                    $result['name'],
                    $result['passed'] ? 'PASS' : 'FAIL',
                    (string) $result['citation_count'],
                    implode(', ', $result['source_types']),
                    implode(', ', $result['missing_text']),
                ],
                $results
            ));
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    protected function fixturePath(): string
    {
        $path = trim((string) ($this->option('path') ?? ''));

        return $path !== '' ? $path : dirname(__DIR__, 3) . '/resources/fixtures/orchestration-v2/rag.json';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadFixtures(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("RAG fixture file [{$path}] was not found.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['fixtures'] ?? null)) {
            throw new \InvalidArgumentException("RAG fixture file [{$path}] is invalid.");
        }

        return $decoded['fixtures'];
    }

    /**
     * @param array<int, RAGSource> $sources
     */
    protected function evaluateFixture(array $fixture, array $context, array $sources): array
    {
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $contextText = (string) ($context['context'] ?? '');
        $missingText = array_values(array_filter(
            (array) ($expected['context_contains'] ?? []),
            static fn (mixed $needle): bool => !str_contains($contextText, (string) $needle)
        ));
        $sourceTypes = array_values(array_unique(array_map(static fn (RAGSource $source): string => $source->type, $sources)));
        $expectedTypes = array_values((array) ($expected['source_types'] ?? []));
        $citationCount = count((array) ($context['citations'] ?? []));

        $passed = $missingText === []
            && $citationCount === (int) ($expected['citation_count'] ?? $citationCount)
            && array_values(array_diff($expectedTypes, $sourceTypes)) === [];

        return [
            'name' => (string) ($fixture['name'] ?? 'unnamed'),
            'passed' => $passed,
            'citation_count' => $citationCount,
            'source_types' => $sourceTypes,
            'missing_text' => $missingText,
        ];
    }
}
