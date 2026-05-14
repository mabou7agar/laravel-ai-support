<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

class LangGraphEventMapper
{
    public function toStepAttributes(array $event): array
    {
        $name = (string) ($event['event'] ?? $event['name'] ?? 'langgraph.event');
        $node = (string) ($event['node'] ?? $event['step'] ?? $name);
        $status = $this->status($name, $event);

        return [
            'step_key' => 'langgraph:' . $node,
            'type' => 'langgraph',
            'status' => $status,
            'action' => $node,
            'source' => 'langgraph',
            'input' => is_array($event['input'] ?? null) ? $event['input'] : null,
            'output' => is_array($event['output'] ?? null) ? $event['output'] : null,
            'routing_trace' => ['event' => $event],
            'metadata' => [
                'langgraph_event' => $name,
                'langgraph_node' => $node,
                'trace_id' => $event['trace_id'] ?? null,
            ],
            'error' => $event['error'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toStepAttributesList(array $events): array
    {
        return array_values(array_map(
            fn (array $event): array => $this->toStepAttributes($event),
            array_filter($events, 'is_array')
        ));
    }

    private function status(string $name, array $event): string
    {
        if (isset($event['status'])) {
            return (string) $event['status'];
        }

        return match (true) {
            str_contains($name, 'failed'), isset($event['error']) => 'failed',
            str_contains($name, 'completed'), str_contains($name, 'finished') => 'completed',
            default => 'running',
        };
    }
}
