<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class AgentOrchestrationReport
{
    /**
     * @param array<string, array<int, string>> $nodes
     * @param array<int, array{from:string,to:string,type:string,metadata:array<string,mixed>}> $links
     * @param array<int, array{severity:string,code:string,message:string,subject:string}> $issues
     * @param array<string, int|float> $metrics
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $links,
        public readonly array $issues,
        public readonly array $metrics
    ) {
    }

    public function passed(bool $failOnWarning = false): bool
    {
        foreach ($this->issues as $issue) {
            if (($issue['severity'] ?? 'error') === 'error') {
                return false;
            }

            if ($failOnWarning && ($issue['severity'] ?? null) === 'warning') {
                return false;
            }
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'nodes' => $this->nodes,
            'links' => $this->links,
            'issues' => $this->issues,
            'metrics' => $this->metrics,
        ];
    }
}
