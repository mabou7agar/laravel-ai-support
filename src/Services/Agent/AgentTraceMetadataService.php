<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;

class AgentTraceMetadataService
{
    public function traceId(array $metadata = [], ?AIAgentRun $run = null): string
    {
        $existing = trim((string) ($metadata['trace_id'] ?? $run?->metadata['trace_id'] ?? ''));

        return $existing !== '' ? $existing : (string) Str::uuid();
    }

    public function enrichResponse(
        AgentResponse $response,
        array $metadata = [],
        ?AIAgentRun $run = null,
        ?AIAgentRunStep $step = null
    ): AgentResponse {
        $response->metadata = array_merge($response->metadata ?? [], array_filter([
            'trace_id' => $metadata['trace_id'] ?? $this->traceId($metadata, $run),
            'agent_run_id' => $metadata['agent_run_id'] ?? $run?->uuid ?? $run?->id,
            'agent_run_db_id' => $metadata['agent_run_db_id'] ?? $run?->id,
            'agent_run_step_id' => $metadata['agent_run_step_id'] ?? $step?->uuid ?? $step?->id,
            'agent_run_step_db_id' => $metadata['agent_run_step_db_id'] ?? $step?->id,
            'runtime' => $metadata['runtime'] ?? $run?->runtime,
            'decision_source' => $metadata['decision_source'] ?? $step?->source,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return $response;
    }

    public function spanMetadata(
        string $name,
        array $attributes = [],
        array $metadata = [],
        ?AIAgentRun $run = null,
        ?AIAgentRunStep $step = null
    ): array {
        $traceId = $metadata['trace_id'] ?? $this->traceId($metadata, $run);
        $spanId = $metadata['span_id'] ?? str_replace('-', '', (string) Str::uuid());

        return [
            'trace_id' => $traceId,
            'span_id' => substr($spanId, 0, 16),
            'parent_span_id' => $metadata['parent_span_id'] ?? null,
            'span_name' => $name,
            'otel' => [
                'trace_id' => $traceId,
                'span_id' => substr($spanId, 0, 16),
                'parent_span_id' => $metadata['parent_span_id'] ?? null,
                'name' => $name,
                'kind' => $metadata['span_kind'] ?? 'internal',
                'attributes' => array_filter(array_merge([
                    'ai.agent.run_id' => $run?->uuid,
                    'ai.agent.run_db_id' => $run?->id,
                    'ai.agent.step_id' => $step?->uuid,
                    'ai.agent.step_db_id' => $step?->id,
                    'ai.agent.runtime' => $metadata['runtime'] ?? $run?->runtime,
                ], $attributes), static fn (mixed $value): bool => $value !== null && $value !== ''),
            ],
        ];
    }

    public function responseMetadataFromContext(array $contextMetadata): array
    {
        return array_intersect_key($contextMetadata, array_flip([
            'trace_id',
            'agent_run_id',
            'agent_run_db_id',
            'agent_run_step_id',
            'agent_run_step_db_id',
            'runtime',
            'decision_source',
        ]));
    }
}
