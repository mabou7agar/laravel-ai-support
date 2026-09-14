<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Http\Requests\McpToolCallRequest;
use LaravelAIEngine\Http\Requests\RealtimeToolDispatchRequest;
use LaravelAIEngine\Http\Support\ApiRequestIdentity;
use LaravelAIEngine\Services\SDK\McpAppToolAdapter;
use LaravelAIEngine\Services\SDK\RealtimeToolBrokerService;

class AgentIntegrationController extends Controller
{
    public function __construct(
        protected McpAppToolAdapter $mcp,
        protected RealtimeToolBrokerService $realtime
    ) {
    }

    public function tools(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'MCP/App tools loaded.',
            'data' => [
                'tools' => $this->mcp->listTools(),
            ],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function callTool(string $tool, McpToolCallRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->mcp->callTool(
            $tool,
            $validated['arguments'] ?? [],
            $this->context($validated, $request),
            (bool) ($validated['approved'] ?? false)
        );

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? $result['error'] ?? 'Tool call completed.'),
            'data' => [
                'result' => $result,
            ],
            'error' => ($result['success'] ?? false) ? null : ['message' => $result['error'] ?? 'Tool call failed.'],
            'meta' => ['schema' => 'ai-engine.v1'],
        ], ($result['success'] ?? false) ? 200 : (($result['status'] ?? null) === 'approval_required' ? 202 : 422));
    }

    public function dispatchRealtimeTool(RealtimeToolDispatchRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->realtime->dispatch(
            $validated['event'],
            $this->context($validated, $request),
            (bool) ($validated['approved'] ?? false)
        );

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? $result['error'] ?? 'Realtime tool dispatch completed.'),
            'data' => [
                'result' => $result,
            ],
            'error' => ($result['success'] ?? false) ? null : ['message' => $result['error'] ?? $result['message'] ?? 'Realtime tool dispatch failed.'],
            'meta' => ['schema' => 'ai-engine.v1'],
        ], ($result['success'] ?? false) ? 200 : 202);
    }

    protected function context(array $validated, Request $request): UnifiedActionContext
    {
        $context = new UnifiedActionContext(
            sessionId: (string) ($validated['session_id'] ?? 'mcp-app'),
            userId: ApiRequestIdentity::userId($request, $validated['user_id'] ?? null)
        );

        if (isset($validated['metadata']) && is_array($validated['metadata'])) {
            $context->metadata = ApiRequestIdentity::metadata($validated['metadata']);
        }

        return $context;
    }
}
