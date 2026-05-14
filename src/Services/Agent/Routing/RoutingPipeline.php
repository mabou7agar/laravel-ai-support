<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingTrace;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;

class RoutingPipeline
{
    /**
     * @param array<int, RoutingStageContract> $stages
     */
    public function __construct(
        protected array $stages = []
    ) {
    }

    public function addStage(RoutingStageContract $stage): self
    {
        $this->stages[] = $stage;

        return $this;
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): RoutingTrace
    {
        $trace = new RoutingTrace();
        $skipped = [];

        foreach ($this->stages as $stage) {
            $this->emitRoutingEvent(AgentRunEventStreamService::ROUTING_STAGE_STARTED, $stage->name(), null, $options);
            $decision = $stage->decide($message, $context, $options)
                ?? RoutingDecision::abstained($stage->name(), 'Stage did not match.');

            $trace = $trace->record($decision);
            $this->emitRoutingEvent(
                $decision->isAbstention() ? AgentRunEventStreamService::ROUTING_STAGE_ABSTAINED : AgentRunEventStreamService::ROUTING_DECIDED,
                $stage->name(),
                $decision,
                $options
            );

            if (!$decision->isAbstention() && $decision->confidence === 'high') {
                return $trace->select($this->withSkippedStages($decision, $skipped));
            }

            $skipped[] = [
                'stage' => $stage->name(),
                'action' => $decision->action,
                'confidence' => $decision->confidence,
                'reason' => $decision->reason,
            ];
        }

        foreach (array_reverse($trace->decisions) as $decision) {
            if (!$decision->isAbstention()) {
                return $trace->select($this->withSkippedStages($decision, $skipped));
            }
        }

        return $trace;
    }

    /**
     * @return array<int, RoutingStageContract>
     */
    public function stages(): array
    {
        return $this->stages;
    }

    /**
     * @param array<int, array<string, mixed>> $skipped
     */
    protected function withSkippedStages(RoutingDecision $decision, array $skipped): RoutingDecision
    {
        if ($skipped === []) {
            return $decision;
        }

        return new RoutingDecision(
            action: $decision->action,
            source: $decision->source,
            confidence: $decision->confidence,
            reason: $decision->reason,
            payload: $decision->payload,
            metadata: array_merge($decision->metadata, ['skipped_stages' => $skipped])
        );
    }

    protected function emitRoutingEvent(string $event, string $stage, ?RoutingDecision $decision, array $options): void
    {
        app(AgentRunEventStreamService::class)->emit(
            $event,
            $options['agent_run_id'] ?? null,
            $options['agent_run_step_id'] ?? null,
            array_filter([
                'stage' => $stage,
                'decision' => $decision?->toArray(),
            ], static fn (mixed $value): bool => $value !== null),
            ['trace_id' => $options['trace_id'] ?? null]
        );
    }
}
