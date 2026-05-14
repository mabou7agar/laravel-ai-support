<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Http\Requests\CancelAgentRunRequest;
use LaravelAIEngine\Http\Requests\ListAgentRunsRequest;
use LaravelAIEngine\Http\Requests\ResumeAgentRunRequest;
use LaravelAIEngine\Services\Agent\AgentRunInspectionService;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService;

class AgentRunController extends Controller
{
    public function __construct(
        private readonly AgentRunInspectionService $runs,
        private readonly AgentRuntimeCapabilityService $capabilities,
        private readonly AgentRunRuntimeControlService $controls
    ) {}

    public function index(ListAgentRunsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->ok('Agent runs loaded.', $this->runs->paginate($validated, (int) ($validated['per_page'] ?? 25)));
    }

    public function show(string $run): JsonResponse
    {
        return $this->ok('Agent run loaded.', $this->runs->detail($run));
    }

    public function trace(string $run): JsonResponse
    {
        return $this->ok('Agent run trace loaded.', $this->runs->trace($run));
    }

    public function resume(string $run, ResumeAgentRunRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload = array_merge($payload['payload'] ?? [], $payload);
        unset($payload['payload']);

        return $this->ok('Agent run resume requested.', $this->controls->resume($run, $payload));
    }

    public function cancel(string $run, CancelAgentRunRequest $request): JsonResponse
    {
        return $this->ok('Agent run cancelled.', $this->controls->cancel($run, $request->validated()));
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
