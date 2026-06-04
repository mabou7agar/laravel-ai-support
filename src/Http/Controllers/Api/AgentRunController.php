<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Http\Requests\CancelAgentRunRequest;
use LaravelAIEngine\Http\Requests\ListAgentRunsRequest;
use LaravelAIEngine\Http\Requests\ResumeAgentRunRequest;
use LaravelAIEngine\Http\Requests\StreamAgentRunRequest;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Services\Agent\AgentRunAccessGuard;
use LaravelAIEngine\Services\Agent\AgentRunInspectionService;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;
use LaravelAIEngine\Services\Agent\AgentRunSseStreamService;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentRunController extends Controller
{
    public function __construct(
        private readonly AgentRunInspectionService $runs,
        private readonly AgentRuntimeCapabilityService $capabilities,
        private readonly AgentRunRuntimeControlService $controls,
        private readonly AgentRunSseStreamService $streams,
        private readonly AgentRunRepository $repository,
        private readonly AgentRunAccessGuard $access
    ) {}

    public function index(ListAgentRunsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $filters = $this->access->scopeListFilters($validated, $request->user()?->getAuthIdentifier());

        return $this->ok('Agent runs loaded.', $this->runs->paginate($filters, (int) ($validated['per_page'] ?? 25)));
    }

    public function show(string $run, Request $request): JsonResponse
    {
        $this->guard($run, $request);

        return $this->ok('Agent run loaded.', $this->runs->detail($run));
    }

    public function trace(string $run, Request $request): JsonResponse
    {
        $this->guard($run, $request);

        return $this->ok('Agent run trace loaded.', $this->runs->trace($run));
    }

    public function stream(string $run, StreamAgentRunRequest $request): StreamedResponse
    {
        return $this->streams->response($run, array_merge($request->validated(), [
            'auth_user_id' => $request->user()?->getAuthIdentifier(),
        ]));
    }

    public function resume(string $run, ResumeAgentRunRequest $request): JsonResponse
    {
        $record = $this->guard($run, $request);

        $payload = $request->validated();
        $payload = array_merge($payload['payload'] ?? [], $payload);
        unset($payload['payload']);

        return $this->ok('Agent run resume requested.', $this->controls->resume($record, $payload));
    }

    public function cancel(string $run, CancelAgentRunRequest $request): JsonResponse
    {
        $record = $this->guard($run, $request);

        return $this->ok('Agent run cancelled.', $this->controls->cancel($record, $request->validated()));
    }

    private function guard(string $run, Request $request): \LaravelAIEngine\Models\AIAgentRun
    {
        $record = $this->repository->findOrFail($run);
        $this->access->authorize($record, $request->user()?->getAuthIdentifier());

        return $record;
    }

    public function capabilities(): JsonResponse
    {
        return $this->ok('Agent runtime capabilities loaded.', $this->capabilities->report());
    }

    protected function ok(string $message, array $data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }
}
