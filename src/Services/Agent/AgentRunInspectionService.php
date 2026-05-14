<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Repositories\AgentRunRepository;

class AgentRunInspectionService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AgentRunEventStreamService $events
    ) {}

    public function paginate(array $filters = [], int $perPage = 25): array
    {
        return $this->runs->paginate($filters, $perPage)->toArray();
    }

    public function detail(int|string $run): array
    {
        $record = $this->runs->findOrFail($run);
        $record->load(['steps', 'providerToolRuns.approvals', 'providerToolRuns.artifacts']);

        return [
            'run' => $record->toArray(),
            'events' => $this->events->fallbackEvents($record),
            'citations' => $this->citations($record),
        ];
    }

    public function trace(int|string $run): array
    {
        $record = $this->runs->findOrFail($run);
        $steps = $record->steps()->get();

        return [
            'run_id' => $record->uuid,
            'trace_id' => $record->metadata['trace_id'] ?? null,
            'routing_trace' => $record->routing_trace ?? [],
            'events' => $this->events->fallbackEvents($record),
            'citations' => $this->citations($record),
            'steps' => $steps->map(static fn ($step): array => [
                'id' => $step->uuid,
                'sequence' => $step->sequence,
                'step_key' => $step->step_key,
                'type' => $step->type,
                'status' => $step->status,
                'action' => $step->action,
                'source' => $step->source,
                'routing_decision' => $step->routing_decision,
                'routing_trace' => $step->routing_trace,
                'metadata' => $step->metadata,
                'error' => $step->error,
            ])->values()->all(),
        ];
    }

    private function citations($record): array
    {
        $citations = [];
        $this->appendCitations($citations, $record->final_response['metadata']['citations'] ?? null, 'final_response');
        $this->appendCitations($citations, $record->final_response['data']['rag_context']['citations'] ?? null, 'final_response');

        foreach ($record->steps as $step) {
            $source = "step:{$step->uuid}";
            $this->appendCitations($citations, $step->output['metadata']['citations'] ?? null, $source);
            $this->appendCitations($citations, $step->output['data']['rag_context']['citations'] ?? null, $source);
        }

        return collect($citations)
            ->unique(fn (array $citation): string => implode('|', [
                $citation['type'] ?? '',
                $citation['title'] ?? '',
                $citation['url'] ?? '',
                $citation['source_id'] ?? '',
            ]))
            ->values()
            ->all();
    }

    private function appendCitations(array &$target, mixed $citations, string $source): void
    {
        if (!is_array($citations)) {
            return;
        }

        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }

            $target[] = array_filter(array_merge($citation, [
                'inspection_source' => $source,
            ]), static fn (mixed $value): bool => $value !== null && $value !== '');
        }
    }
}
