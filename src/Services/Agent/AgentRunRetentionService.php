<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Models\AIProviderToolArtifact;

class AgentRunRetentionService
{
    public function protectInput(array $input): array
    {
        if (!(bool) config('ai-agent.run_retention.redact_prompts', false)) {
            return $input;
        }

        return $this->redactedPayload('prompt');
    }

    public function protectResponse(array $response): array
    {
        if (!(bool) config('ai-agent.run_retention.redact_responses', false)) {
            return $response;
        }

        return $this->redactedPayload('response');
    }

    public function shouldStoreRawProviderPayloads(): bool
    {
        return (bool) config('ai-agent.run_retention.store_raw_provider_payloads', config('ai-engine.provider_tools.lifecycle.store_payloads', true));
    }

    public function cleanup(bool $dryRun = false, array $overrides = []): array
    {
        $runDays = (int) ($overrides['run_days'] ?? config('ai-agent.run_retention.run_days', 90));
        $stepDays = (int) ($overrides['step_days'] ?? config('ai-agent.run_retention.step_days', 90));
        $traceDays = (int) ($overrides['trace_days'] ?? config('ai-agent.run_retention.trace_days', 30));
        $artifactDays = (int) ($overrides['artifact_days'] ?? config('ai-agent.run_retention.artifact_days', 90));

        $stepIds = AIAgentRunStep::query()
            ->where('updated_at', '<=', now()->subDays(max(0, $stepDays)))
            ->pluck('id')
            ->all();

        $runIds = AIAgentRun::query()
            ->whereIn('status', [
                AIAgentRun::STATUS_COMPLETED,
                AIAgentRun::STATUS_FAILED,
                AIAgentRun::STATUS_CANCELLED,
                AIAgentRun::STATUS_EXPIRED,
            ])
            ->where('updated_at', '<=', now()->subDays(max(0, $runDays)))
            ->pluck('id')
            ->all();

        $traceRunIds = AIAgentRun::query()
            ->whereNotNull('routing_trace')
            ->where('updated_at', '<=', now()->subDays(max(0, $traceDays)))
            ->pluck('id')
            ->all();

        $traceStepIds = AIAgentRunStep::query()
            ->whereNotNull('routing_trace')
            ->where('updated_at', '<=', now()->subDays(max(0, $traceDays)))
            ->pluck('id')
            ->all();

        $artifactIds = AIProviderToolArtifact::query()
            ->whereNotNull('agent_run_step_id')
            ->where('updated_at', '<=', now()->subDays(max(0, $artifactDays)))
            ->pluck('id')
            ->all();

        if (!$dryRun) {
            if ($traceRunIds !== []) {
                AIAgentRun::query()->whereIn('id', $traceRunIds)->update(['routing_trace' => null]);
            }

            if ($traceStepIds !== []) {
                AIAgentRunStep::query()->whereIn('id', $traceStepIds)->update(['routing_trace' => null, 'routing_decision' => null]);
            }

            if ($artifactIds !== []) {
                AIProviderToolArtifact::query()->whereIn('id', $artifactIds)->delete();
            }

            if ($stepIds !== []) {
                AIAgentRunStep::query()->whereIn('id', $stepIds)->delete();
            }

            if ($runIds !== []) {
                AIAgentRun::query()->whereIn('id', $runIds)->delete();
            }
        }

        return [
            'dry_run' => $dryRun,
            'runs_deleted' => $dryRun ? 0 : count($runIds),
            'steps_deleted' => $dryRun ? 0 : count($stepIds),
            'traces_redacted' => $dryRun ? 0 : count($traceRunIds) + count($traceStepIds),
            'artifacts_deleted' => $dryRun ? 0 : count($artifactIds),
            'matched' => [
                'runs' => count($runIds),
                'steps' => count($stepIds),
                'traces' => count($traceRunIds) + count($traceStepIds),
                'artifacts' => count($artifactIds),
            ],
        ];
    }

    private function redactedPayload(string $type): array
    {
        return [
            'redacted' => true,
            'type' => $type,
            'redacted_at' => now()->toIso8601String(),
        ];
    }
}
