<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

class EvaluationService
{
    protected array $runs = [];

    public function evaluate(string $name, mixed $actual, mixed $expected = null, ?callable $assertion = null): array
    {
        $passed = $assertion !== null
            ? (bool) $assertion($actual, $expected)
            : $actual === $expected;

        $run = [
            'name' => $name,
            'passed' => $passed,
            'actual' => $actual,
            'expected' => $expected,
            'created_at' => now()->toISOString(),
        ];

        $this->runs[] = $run;

        return $run;
    }

    public function assertPassed(string $name, mixed $actual, mixed $expected = null, ?callable $assertion = null): void
    {
        $run = $this->evaluate($name, $actual, $expected, $assertion);

        if (!$run['passed']) {
            throw new \RuntimeException("Evaluation [{$name}] failed.");
        }
    }

    public function runs(): array
    {
        return $this->runs;
    }
}
