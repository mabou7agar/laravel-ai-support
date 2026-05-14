<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AgentRunApprovalService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\ProviderTools\HostedArtifactService;
use Illuminate\Support\Str;

class LangGraphAgentRuntime implements AgentRuntimeContract
{
    public function __construct(
        protected LaravelAgentRuntime $fallbackRuntime,
        protected ContextManager $contextManager,
        protected ?AgentExecutionPolicyService $policy = null,
        protected ?LangGraphRuntimeClient $client = null,
        protected ?LangGraphRunMapper $runMapper = null,
        protected ?ProviderToolRunRepository $toolRuns = null,
        protected ?HostedArtifactService $artifacts = null,
        protected ?AgentRunApprovalService $runApprovals = null,
        protected ?AgentRunStepRepository $steps = null
    ) {
    }

    public function name(): string
    {
        return 'langgraph';
    }

    public function capabilities(): AgentRuntimeCapabilities
    {
        return AgentRuntimeCapabilities::langGraph($this->enabled());
    }

    public function process(
        string $message,
        string $sessionId,
        mixed $userId,
        array $options = []
    ): AgentResponse {
        $options = $this->policy()->sanitizePayloadForRuntime($this->name(), $options);

        if (!$this->enabled() || $this->baseUrl() === null) {
            if ($this->fallbackEnabled($options)) {
                $response = $this->fallbackRuntime->process($message, $sessionId, $userId, $options);
                $response->metadata = array_merge($response->metadata ?? [], [
                    'requested_agent_runtime' => $this->name(),
                    'agent_runtime_fallback' => 'laravel',
                    'agent_runtime_fallback_reason' => !$this->enabled()
                        ? 'langgraph_disabled'
                        : 'langgraph_base_url_missing',
                ]);

                return $response;
            }

            return AgentResponse::failure(
                message: 'LangGraph runtime is not available.',
                data: [
                    'runtime' => $this->name(),
                    'enabled' => $this->enabled(),
                    'base_url_configured' => $this->baseUrl() !== null,
                ],
                context: $this->contextManager->getOrCreate($sessionId, $userId)
            );
        }

        $context = $this->contextManager->getOrCreate($sessionId, $userId);

        try {
            $resumeRunId = $this->resumeRunId($options);
            $run = $resumeRunId !== null
                ? $this->client()->resumeRun($resumeRunId, $this->runMapper()->resumePayload($message, $sessionId, $userId, $options))
                : $this->client()->startRun($this->runMapper()->startPayload($message, $sessionId, $userId, $options));

            $response = $this->runMapper()->toResponse($run, $context);
            $response = $this->recordInterruptApproval($response, $run, $options);
            $artifactRecords = $this->recordGeneratedArtifacts($run, $options, $userId);
            if ($artifactRecords !== []) {
                $response->metadata = array_merge($response->metadata ?? [], [
                    'hosted_artifact_ids' => array_map(static fn ($artifact): int => (int) $artifact->id, $artifactRecords),
                    'hosted_artifact_count' => count($artifactRecords),
                ]);
            }

            return $response;
        } catch (\Throwable $e) {
            if ($this->fallbackEnabled($options)) {
                $response = $this->fallbackRuntime->process($message, $sessionId, $userId, $options);
                $response->metadata = array_merge($response->metadata ?? [], [
                    'requested_agent_runtime' => $this->name(),
                    'agent_runtime_fallback' => 'laravel',
                    'agent_runtime_fallback_reason' => 'langgraph_request_failed',
                    'agent_runtime_fallback_error' => $e->getMessage(),
                ]);

                return $response;
            }

            return AgentResponse::failure(
                message: 'LangGraph runtime request failed.',
                data: [
                    'runtime' => $this->name(),
                    'error' => $e->getMessage(),
                ],
                context: $context
            );
        }
    }

    protected function resumeRunId(array $options): ?string
    {
        foreach ([$options['langgraph_resume_run_id'] ?? null, $options['langgraph_run_id'] ?? null] as $candidate) {
            $candidate = is_scalar($candidate) ? trim((string) $candidate) : '';
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    protected function recordInterruptApproval(AgentResponse $response, array $run, array $options): AgentResponse
    {
        $mapper = $this->interruptMapper();
        $stepId = $options['agent_run_step_id'] ?? null;
        if (!$response->needsUserInput || $stepId === null || !$mapper->requiresApproval($run)) {
            return $response;
        }

        $step = $this->stepRepository()->find($stepId);
        if ($step === null) {
            return $response;
        }

        $interrupt = $mapper->interrupt($run);
        $resumePayload = array_merge((array) ($interrupt['resume_payload'] ?? []), [
            'langgraph_run_id' => $run['id'] ?? $run['run_id'] ?? null,
            'thread_id' => $run['thread_id'] ?? null,
            'interrupt_id' => $interrupt['id'] ?? $interrupt['interrupt_id'] ?? null,
        ]);

        $approval = $this->approvalService()->requestStepApproval(
            $step,
            $mapper->approvalDecision($run),
            null,
            array_filter([
                'runtime' => 'langgraph',
                'langgraph_run_id' => $run['id'] ?? $run['run_id'] ?? null,
                'langgraph_thread_id' => $run['thread_id'] ?? null,
                'resume_payload' => $resumePayload,
                'trace_id' => $options['trace_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
        );

        $response->data = array_merge($response->data ?? [], [
            'approval_key' => $approval->approval_key,
            'provider_tool_run_id' => $approval->tool_run_id,
        ]);
        $response->metadata = array_merge($response->metadata ?? [], [
            'agent_run_status' => \LaravelAIEngine\Models\AIAgentRun::STATUS_WAITING_APPROVAL,
            'approval_key' => $approval->approval_key,
            'approval_scope' => 'langgraph_interrupt',
        ]);

        return $response;
    }

    protected function enabled(): bool
    {
        return (bool) config('ai-agent.runtime.langgraph.enabled', false);
    }

    protected function baseUrl(): ?string
    {
        $baseUrl = trim((string) config('ai-agent.runtime.langgraph.base_url', ''));

        return $baseUrl === '' ? null : $baseUrl;
    }

    protected function fallbackEnabled(array $options): bool
    {
        return array_key_exists('fallback_to_laravel', $options)
            ? (bool) $options['fallback_to_laravel']
            : (bool) config('ai-agent.runtime.langgraph.fallback_to_laravel', true);
    }

    protected function policy(): AgentExecutionPolicyService
    {
        if ($this->policy instanceof AgentExecutionPolicyService) {
            return $this->policy;
        }

        return $this->policy = app()->bound(AgentExecutionPolicyService::class)
            ? app(AgentExecutionPolicyService::class)
            : new AgentExecutionPolicyService();
    }

    protected function client(): LangGraphRuntimeClient
    {
        if ($this->client instanceof LangGraphRuntimeClient) {
            return $this->client;
        }

        return $this->client = app()->bound(LangGraphRuntimeClient::class)
            ? app(LangGraphRuntimeClient::class)
            : new LangGraphRuntimeClient();
    }

    protected function runMapper(): LangGraphRunMapper
    {
        if ($this->runMapper instanceof LangGraphRunMapper) {
            return $this->runMapper;
        }

        return $this->runMapper = app()->bound(LangGraphRunMapper::class)
            ? app(LangGraphRunMapper::class)
            : new LangGraphRunMapper(new LangGraphInterruptMapper());
    }

    protected function interruptMapper(): LangGraphInterruptMapper
    {
        return $this->runMapper()->interrupts();
    }

    protected function recordGeneratedArtifacts(array $run, array $options, mixed $userId): array
    {
        if (!$this->hasArtifactCandidates($run) || !app()->bound(HostedArtifactService::class)) {
            return [];
        }

        $toolRun = $this->toolRunRepository()->create([
            'uuid' => (string) Str::uuid(),
            'agent_run_id' => $options['agent_run_id'] ?? null,
            'agent_run_step_id' => $options['agent_run_step_id'] ?? null,
            'provider' => 'langgraph',
            'engine' => 'langgraph',
            'ai_model' => (string) ($run['graph_id'] ?? $run['assistant_id'] ?? 'langgraph'),
            'status' => 'completed',
            'provider_request_id' => $run['id'] ?? $run['run_id'] ?? null,
            'conversation_id' => $options['conversation_id'] ?? null,
            'user_id' => $userId === null ? null : (string) $userId,
            'tool_names' => ['langgraph'],
            'request_payload' => [],
            'response_payload' => $run,
            'metadata' => array_filter([
                'trace_id' => $options['trace_id'] ?? null,
                'source' => 'langgraph',
                'owner_type' => isset($options['agent_run_id']) ? 'agent_run' : null,
                'owner_id' => $options['agent_run_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'completed_at' => now(),
        ]);

        return $this->artifactService()->recordFromProviderResponse($toolRun, $run, [
            'source' => 'langgraph',
            'owner_type' => isset($options['agent_run_id']) ? 'agent_run' : 'provider_tool_run',
            'owner_id' => $options['agent_run_id'] ?? $toolRun->id,
            'agent_run_step_id' => $options['agent_run_step_id'] ?? null,
            'trace_id' => $options['trace_id'] ?? null,
        ]);
    }

    protected function hasArtifactCandidates(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_array($value) && $this->hasArtifactCandidates($value)) {
                return true;
            }

            if (!is_scalar($value)) {
                continue;
            }

            if (in_array((string) $key, ['url', 'download_url', 'file_url', 'source_url', 'image_url', 'video_url', 'audio_url', 'file_id', 'output_file_id'], true)
                && trim((string) $value) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    protected function toolRunRepository(): ProviderToolRunRepository
    {
        return $this->toolRuns ??= app(ProviderToolRunRepository::class);
    }

    protected function artifactService(): HostedArtifactService
    {
        return $this->artifacts ??= app(HostedArtifactService::class);
    }

    protected function approvalService(): AgentRunApprovalService
    {
        return $this->runApprovals ??= app(AgentRunApprovalService::class);
    }

    protected function stepRepository(): AgentRunStepRepository
    {
        return $this->steps ??= app(AgentRunStepRepository::class);
    }
}
