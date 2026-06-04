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
        // The webhook can force a run to failed/completed by id, so when a shared secret is
        // configured it MUST be presented (header or query). Enforced only when configured,
        // to stay non-breaking; configuring it is strongly recommended.
        $secret = trim((string) config('ai-engine.provider_tools.fal.webhook_secret', ''));
        if ($secret !== '') {
            $provided = (string) ($request->header('X-Fal-Webhook-Secret')
                ?? $request->query('webhook_secret', ''));
            if ($provided === '' || !hash_equals($secret, $provided)) {
                abort(401, 'Invalid or missing webhook signature.');
            }
        }

        return $this->operations->falCatalogWebhook(
            $request->validated(),
            $request->all(),
            $request->query('provider_tool_run_id')
        );
    }
}
