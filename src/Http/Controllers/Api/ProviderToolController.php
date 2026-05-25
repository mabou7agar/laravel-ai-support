<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Http\Requests\ContinueProviderToolRunRequest;
use LaravelAIEngine\Http\Requests\FalCatalogExecuteRequest;
use LaravelAIEngine\Http\Requests\FalCatalogWebhookRequest;
use LaravelAIEngine\Http\Requests\ListProviderToolApprovalsRequest;
use LaravelAIEngine\Http\Requests\ListProviderToolArtifactsRequest;
use LaravelAIEngine\Http\Requests\ListProviderToolRunsRequest;
use LaravelAIEngine\Http\Requests\ResolveProviderToolApprovalRequest;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApiOperationsService;

class ProviderToolController extends Controller
{
    public function __construct(
        private readonly ProviderToolApiOperationsService $operations
    ) {}

    public function runs(ListProviderToolRunsRequest $request): JsonResponse
    {
        return $this->operations->runs($request->validated());
    }

    public function showRun(string $run): JsonResponse
    {
        return $this->operations->showRun($run);
    }

    public function approvals(ListProviderToolApprovalsRequest $request): JsonResponse
    {
        return $this->operations->approvals($request->validated());
    }

    public function approve(string $approvalKey, ResolveProviderToolApprovalRequest $request): JsonResponse
    {
        return $this->operations->approve($approvalKey, $request->validated());
    }

    public function reject(string $approvalKey, ResolveProviderToolApprovalRequest $request): JsonResponse
    {
        return $this->operations->reject($approvalKey, $request->validated());
    }

    public function continueRun(string $run, ContinueProviderToolRunRequest $request): JsonResponse
    {
        return $this->operations->continueRun($run, $request->validated());
    }

    public function artifacts(ListProviderToolArtifactsRequest $request): JsonResponse
    {
        return $this->operations->artifacts($request->validated());
    }

    public function downloadArtifact(string $artifact): Response
    {
        return $this->operations->downloadArtifact($artifact);
    }

    public function executeFalCatalog(FalCatalogExecuteRequest $request): JsonResponse
    {
        return $this->operations->executeFalCatalog($request->validated());
    }

    public function falCatalogWebhook(FalCatalogWebhookRequest $request): JsonResponse
    {
        return $this->operations->falCatalogWebhook(
            $request->validated(),
            $request->all(),
            $request->query('provider_tool_run_id')
        );
    }
}
