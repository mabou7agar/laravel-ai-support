<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Routing\RoutingPipeline;

class EvaluateRoutingFixturesCommand extends Command
{
    protected $signature = 'ai-engine:evaluate-routing-fixtures
                            {--path= : JSON fixture file path}
                            {--json : Print JSON report}';

    protected $description = 'Evaluate deterministic orchestration routing fixtures';

    public function handle(RoutingPipeline $pipeline): int
    {
        $fixtures = $this->loadFixtures($this->fixturePath());
        $results = [];

        foreach ($fixtures as $fixture) {
            $context = $this->contextFor($fixture);
            $trace = $pipeline->decide(
                (string) ($fixture['message'] ?? ''),
                $context,
                is_array($fixture['options'] ?? null) ? $fixture['options'] : []
            );

            $results[] = $this->evaluateFixture($fixture, $trace->selected);
        }

        $passed = collect($results)->every(static fn (array $result): bool => $result['passed']);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'passed' => $passed,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Fixture', 'Status', 'Expected', 'Actual'], array_map(
                static fn (array $result): array => [
                    $result['name'],
                    $result['passed'] ? 'PASS' : 'FAIL',
                    $result['expected'],
                    $result['actual'],
                ],
                $results
            ));
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    protected function fixturePath(): string
    {
        $path = trim((string) ($this->option('path') ?? ''));

        return $path !== '' ? $path : dirname(__DIR__, 3) . '/resources/fixtures/orchestration-v2/routing.json';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadFixtures(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Routing fixture file [{$path}] was not found.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['fixtures'] ?? null)) {
            throw new \InvalidArgumentException("Routing fixture file [{$path}] is invalid.");
        }

        return $decoded['fixtures'];
    }

    protected function contextFor(array $fixture): UnifiedActionContext
    {
        $context = new UnifiedActionContext(
            (string) ($fixture['session_id'] ?? 'routing-fixture-' . ($fixture['name'] ?? 'unknown')),
            $fixture['user_id'] ?? null
        );

        foreach ((array) ($fixture['history'] ?? []) as $message) {
            if (is_array($message)) {
                $context->conversationHistory[] = $message;
            }
        }

        return $context;
    }

    protected function evaluateFixture(array $fixture, ?RoutingDecision $decision): array
    {
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $actual = $decision?->toArray() ?? [];
        $payloadMatches = true;

        foreach ((array) ($expected['payload'] ?? []) as $key => $value) {
            if (($actual['payload'][$key] ?? null) !== $value) {
                $payloadMatches = false;
                break;
            }
        }

        $passed = $decision instanceof RoutingDecision
            && ($expected['action'] ?? null) === $decision->action
            && ($expected['source'] ?? null) === $decision->source
            && $payloadMatches;

        return [
            'name' => (string) ($fixture['name'] ?? 'unnamed'),
            'passed' => $passed,
            'expected' => trim((string) ($expected['source'] ?? '?') . ':' . (string) ($expected['action'] ?? '?')),
            'actual' => $decision instanceof RoutingDecision ? "{$decision->source}:{$decision->action}" : 'none',
            'decision' => $actual,
        ];
    }
}
