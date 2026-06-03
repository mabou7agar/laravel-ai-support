<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LaravelAIEngine\Http\Requests\AdminAgentRunActionRequest;
use LaravelAIEngine\Http\Requests\AdminPolicyActivateRequest;
use LaravelAIEngine\Http\Requests\AdminPolicyCreateRequest;
use LaravelAIEngine\Http\Requests\AdminProviderToolApprovalActionRequest;
use LaravelAIEngine\Http\Requests\AdminProviderToolRunActionRequest;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AIPromptPolicyVersionRepository;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Repositories\ProviderToolArtifactRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\Admin\AdminAgentRunOperationsService;
use LaravelAIEngine\Services\Admin\AdminPolicyOperationsService;
use LaravelAIEngine\Services\Admin\AdminProviderToolOperationsService;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolContinuationService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;

class AdminOperationsController extends Controller
{
    public function __construct(
        private readonly AdminProviderToolOperationsService $providerTools,
        private readonly AdminAgentRunOperationsService $agentRuns,
        private readonly AdminPolicyOperationsService $policies
    ) {}

    public function providerTools(
        ProviderToolRunRepository $runs,
        ProviderToolApprovalRepository $approvals,
        ProviderToolArtifactRepository $artifacts
    ): View {
        return $this->providerTools->index($runs, $approvals, $artifacts);
    }

    public function agentRuns(AgentRunRepository $runs): View
    {
        return $this->agentRuns->index($runs);
    }

    public function agentRunDetail(string $run, AgentRunRepository $runs): View
    {
        return $this->agentRuns->show($run, $runs);
    }

    public function resumeAgentRun(
        string $run,
        AdminAgentRunActionRequest $request,
        AgentRunRuntimeControlService $control
    ): RedirectResponse {
        return $this->agentRuns->resume($run, array_merge($request->validated(), [
            'queue' => $request->boolean('queue', true),
        ]), $control);
    }

    public function retryAgentRun(
        string $run,
        AdminAgentRunActionRequest $request,
        AgentRunRuntimeControlService $control
    ): RedirectResponse {
        return $this->agentRuns->retry($run, array_merge($request->validated(), [
            'queue' => $request->boolean('queue', true),
        ]), $control);
    }

    public function cancelAgentRun(
        string $run,
        AdminAgentRunActionRequest $request,
        AgentRunRuntimeControlService $control
    ): RedirectResponse {
        return $this->agentRuns->cancel($run, $request->validated(), $control);
    }

    public function approveProviderTool(
        AdminProviderToolApprovalActionRequest $request,
        ProviderToolApprovalService $approvals,
        ProviderToolContinuationService $continuations,
        JobStatusTracker $jobs
    ): RedirectResponse {
        return $this->providerTools->approve($request->validated(), $approvals, $continuations, $jobs);
    }

    public function rejectProviderTool(
        AdminProviderToolApprovalActionRequest $request,
        ProviderToolApprovalService $approvals
    ): RedirectResponse {
        return $this->providerTools->reject($request->validated(), $approvals);
    }

    public function continueProviderToolRun(
        AdminProviderToolRunActionRequest $request,
        ProviderToolContinuationService $continuations,
        JobStatusTracker $jobs
    ): RedirectResponse {
        return $this->providerTools->continueRun($request->validated(), $continuations, $jobs);
    }

    public function health(InfrastructureHealthService $healthService): View
    {
        return view('ai-engine::admin.health', [
            'report' => $healthService->evaluate(),
        ]);
    }

    public function policies(
        RAGPromptPolicyService $policyService,
        AIPromptPolicyVersionRepository $policyVersions
    ): View {
        return $this->policies->index($policyService, $policyVersions);
    }

    public function createPolicy(
        AdminPolicyCreateRequest $request,
        RAGPromptPolicyService $policyService,
        RAGDecisionPolicy $policyConfig
    ): RedirectResponse {
        return $this->policies->create($request->validated(), $policyService, $policyConfig);
    }

    public function activatePolicy(AdminPolicyActivateRequest $request, RAGPromptPolicyService $policyService): RedirectResponse
    {
        return $this->policies->activate($request->validated(), $policyService);
    }
}
