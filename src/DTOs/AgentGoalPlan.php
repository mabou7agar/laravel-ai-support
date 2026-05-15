<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class AgentGoalPlan
{
    /**
     * @param array<int, SubAgentTask> $tasks
     */
    public function __construct(
        public readonly string $target,
        public readonly array $tasks,
        public readonly array $metadata = []
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'tasks' => array_map(static fn (SubAgentTask $task): array => $task->toArray(), $this->tasks),
            'metadata' => $this->metadata,
        ];
    }
}
