<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;

class ProviderToolContinuationService
{
    public function __construct(
        private readonly ProviderToolRunRepository $runs,
        private readonly ProviderToolApprovalRepository $approvals,
        private readonly ProviderToolRunService $runLifecycle,
        private readonly HostedArtifactService $artifacts
    ) {}

    public function continueRun(int|string $runId, array $options = []): AIProviderToolRun
    {
        $run = $this->runs->findOrFail($runId);
        if ($run->status === 'completed') {
            return $run;
        }

        $this->assertReadyForContinuation($run);

        $payload = is_array($run->request_payload) ? $run->request_payload : [];
        if ($payload === []) {
            throw new AIEngineException("Provider tool run [{$run->uuid}] does not have a stored request payload.");
        }

        $run = $this->runs->update($run, [
            'status' => 'running',
            'continuation_payload' => $options,
            'started_at' => $run->started_at ?? now(),
        ]);

        try {
            $response = match ($run->provider) {
                'openai' => $this->continueOpenAI($payload),
                'anthropic' => $this->continueAnthropic($payload),
                default => throw new AIEngineException("Provider [{$run->provider}] does not support automatic continuation."),
            };

            $run = $this->runLifecycle->complete($run, $response, [
                'continued_at' => now()->toISOString(),
                'continuation' => $options,
                'agent_run_id' => $run->agent_run_id,
                'agent_run_step_id' => $run->agent_run_step_id,
                'trace_id' => $run->metadata['trace_id'] ?? null,
            ]);

            $this->artifacts->recordFromProviderResponse($run, $response, [
                'continued' => true,
                'agent_run_id' => $run->agent_run_id,
                'agent_run_step_id' => $run->agent_run_step_id,
                'trace_id' => $run->metadata['trace_id'] ?? null,
            ]);

            return $run;
        } catch (\Throwable $e) {
            $this->runLifecycle->fail($run, $e->getMessage(), [
                'continued_at' => now()->toISOString(),
                'continuation' => $options,
            ]);

            throw $e;
        }
    }

    private function assertReadyForContinuation(AIProviderToolRun $run): void
    {
        $requiredTools = array_values(array_filter((array) ($run->tool_names ?? [])));
        foreach ($requiredTools as $toolName) {
            $pending = $this->approvals->pendingForRunAndTool((int) $run->id, (string) $toolName);
            if ($pending !== null) {
                throw new AIEngineException("Provider tool run [{$run->uuid}] still has pending approval for [{$toolName}].");
            }
        }
    }

    private function continueOpenAI(array $payload): array
    {
        $response = Http::timeout((int) config('ai-engine.engines.openai.timeout', 120))
            ->withToken((string) config('ai-engine.engines.openai.api_key'))
            ->acceptJson()
            ->post(rtrim((string) config('ai-engine.engines.openai.base_url', 'https://api.openai.com/v1'), '/') . '/responses', $payload);

        if (!$response->successful()) {
            throw new AIEngineException('OpenAI provider tool continuation failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    private function continueAnthropic(array $payload): array
    {
        $response = Http::timeout((int) config('ai-engine.engines.anthropic.timeout', 120))
            ->withHeaders([
                'x-api-key' => (string) config('ai-engine.engines.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])
            ->acceptJson()
            ->post(rtrim((string) config('ai-engine.engines.anthropic.base_url', 'https://api.anthropic.com'), '/') . '/v1/messages', $payload);

        if (!$response->successful()) {
            throw new AIEngineException('Anthropic provider tool continuation failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }
}
