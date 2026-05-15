<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class SubAgentTask
{
    public function __construct(
        public readonly string $id,
        public readonly string $agentId,
        public readonly string $name,
        public readonly string $objective,
        public readonly array $input = [],
        public readonly array $dependsOn = [],
        public readonly bool $critical = true,
        public readonly int $order = 0,
        public readonly array $metadata = []
    ) {
    }

    public static function fromArray(array $data, int $index = 0): self
    {
        $agentId = trim((string) ($data['agent_id'] ?? $data['agent'] ?? $data['role'] ?? 'general'));
        $id = trim((string) ($data['id'] ?? 'task_' . ($index + 1)));
        $name = trim((string) ($data['name'] ?? $data['title'] ?? $agentId));
        $objective = trim((string) ($data['objective'] ?? $data['target'] ?? $data['prompt'] ?? ''));

        return new self(
            id: $id !== '' ? $id : 'task_' . ($index + 1),
            agentId: $agentId !== '' ? $agentId : 'general',
            name: $name !== '' ? $name : ($agentId !== '' ? $agentId : 'general'),
            objective: $objective,
            input: is_array($data['input'] ?? null) ? $data['input'] : [],
            dependsOn: array_values((array) ($data['depends_on'] ?? $data['dependsOn'] ?? [])),
            critical: array_key_exists('critical', $data) ? (bool) $data['critical'] : true,
            order: (int) ($data['order'] ?? $index),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agentId,
            'name' => $this->name,
            'objective' => $this->objective,
            'input' => $this->input,
            'depends_on' => $this->dependsOn,
            'critical' => $this->critical,
            'order' => $this->order,
            'metadata' => $this->metadata,
        ];
    }
}
