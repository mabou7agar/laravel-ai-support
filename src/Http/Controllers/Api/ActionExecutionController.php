<?php

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\ActionExecutionService;

class ActionExecutionController extends Controller
{
    public function __construct(
        protected ActionExecutionService $actionService
    ) {}

    /**
     * Execute an action
     * 
     * POST /ai/actions/execute
     * {
     *   "action_id": "view_source_abc123",
     *   "action_type": "view_source",
     *   "data": { "model_id": 382, "model_class": "App\\Models\\Email" },
     *   "session_id": "user-123"
     * }
     */
    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'action_type' => 'required|string',
            'data' => 'required|array',
            'session_id' => 'nullable|string',
        ]);

        try {
            $actionType = $request->input('action_type');
            $data = $request->input('data');
            $sessionId = $request->input('session_id');
            $userId = $request->user()?->id ?? $request->input('user_id');

            Log::info('Action execution requested', [
                'action_type' => $actionType,
                'data' => $data,
                'user_id' => $userId,
            ]);

            $result = $this->actionService->execute(
                actionType: $actionType,
                data: $data,
                userId: $userId,
                sessionId: $sessionId
            );

            return response()->json([
                'success' => true,
                'action_type' => $actionType,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Action execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Select a numbered option from AI response
     * 
     * POST /ai/actions/select-option
     * {
     *   "option_id": "opt_1_abc123",
     *   "option_number": 1,
     *   "source_index": 0,
     *   "session_id": "user-123"
     * }
     */
    public function selectOption(Request $request): JsonResponse
    {
        $request->validate([
            'option_number' => 'required|integer|min:1',
            'session_id' => 'required|string',
            'source_index' => 'nullable|integer',
            'sources' => 'nullable|array',
        ]);

        try {
            $optionNumber = $request->input('option_number');
            $sessionId = $request->input('session_id');
            $sourceIndex = $request->input('source_index');
            $sources = $request->input('sources', []);
            $userId = $request->user()?->id ?? $request->input('user_id');

            $result = $this->actionService->selectOption(
                optionNumber: $optionNumber,
                sessionId: $sessionId,
                sourceIndex: $sourceIndex,
                sources: $sources,
                userId: $userId
            );

            return response()->json([
                'success' => true,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Option selection failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available actions for a context
     * 
     * GET /ai/actions/available?context=email&include_remote=true
     */
    public function available(Request $request): JsonResponse
    {
        $context = $request->input('context');
        $userId = $request->user()?->id ?? $request->input('user_id');
        $includeRemote = $request->boolean('include_remote', false);

        if ($includeRemote) {
            $actions = $this->actionService->getAvailableActionsFromAllNodes($context);
        } else {
            $actions = $this->actionService->getAvailableActions($context, $userId);
        }

        return response()->json([
            'success' => true,
            'actions' => $actions,
        ]);
    }

    /**
     * Execute action on a specific remote node
     * 
     * POST /ai/actions/execute-remote
     * {
     *   "node": "node-slug",
     *   "action_type": "view_source",
     *   "data": { ... }
     * }
     */
    public function executeRemote(Request $request): JsonResponse
    {
        $request->validate([
            'node' => 'required|string',
            'action_type' => 'required|string',
            'data' => 'required|array',
        ]);

        try {
            $nodeSlug = $request->input('node');
            $actionType = $request->input('action_type');
            $data = $request->input('data');
            $sessionId = $request->input('session_id');
            $userId = $request->user()?->id ?? $request->input('user_id');

            // Add node to data for remote execution
            $data['node'] = $nodeSlug;

            $result = $this->actionService->execute(
                actionType: $actionType,
                data: $data,
                userId: $userId,
                sessionId: $sessionId
            );

            return response()->json([
                'success' => true,
                'node' => $nodeSlug,
                'action_type' => $actionType,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Remote action execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute action on all nodes
     * 
     * POST /ai/actions/execute-all
     * {
     *   "action_type": "sync_data",
     *   "data": { ... },
     *   "parallel": true,
     *   "node_ids": ["node1", "node2"]  // optional
     * }
     */
    public function executeOnAll(Request $request): JsonResponse
    {
        $request->validate([
            'action_type' => 'required|string',
            'data' => 'required|array',
        ]);

        try {
            $actionType = $request->input('action_type');
            $data = $request->input('data');
            $sessionId = $request->input('session_id');
            $userId = $request->user()?->id ?? $request->input('user_id');
            $parallel = $request->boolean('parallel', true);
            $nodeIds = $request->input('node_ids');

            $data['parallel'] = $parallel;
            $data['node_ids'] = $nodeIds;

            $result = $this->actionService->executeOnAllNodes(
                actionType: $actionType,
                data: $data,
                userId: $userId,
                sessionId: $sessionId
            );

            return response()->json([
                'success' => $result['success'] ?? false,
                'action_type' => $actionType,
                'nodes_executed' => $result['nodes_executed'] ?? 0,
                'success_count' => $result['success_count'] ?? 0,
                'failure_count' => $result['failure_count'] ?? 0,
                'results' => $result['results'] ?? [],
            ]);

        } catch (\Exception $e) {
            Log::error('Multi-node action execution failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
