<?php

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorSessionService;

class AutonomousCollectorController extends Controller
{
    public function __construct(
        protected AutonomousCollectorSessionService $collector
    ) {
    }

    /**
     * POST /api/v1/autonomous-collector/start
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'config_name' => 'required|string',
            'session_id' => 'nullable|string',
            'initial_message' => 'nullable|string',
        ]);

        $configName = trim((string) $validated['config_name']);
        $config = AutonomousCollectorRegistry::getConfig($configName)
            ?? $this->collector->getRegisteredConfig($configName);

        if (!$config) {
            return response()->json([
                'success' => false,
                'error' => "Autonomous collector configuration '{$configName}' not found.",
            ], 404);
        }

        $sessionId = $validated['session_id'] ?? ('ac-' . uniqid());
        $initialMessage = $validated['initial_message'] ?? '';

        $response = $this->collector->start($sessionId, $config, $initialMessage);

        return response()->json([
            'success' => $response->success,
            'session_id' => $sessionId,
            'message' => $response->message,
            'status' => $response->status,
            'collected_data' => $response->collectedData,
            'is_complete' => $response->isComplete,
            'is_cancelled' => $response->isCancelled,
            'requires_confirmation' => $response->requiresConfirmation,
            'turn_count' => $response->turnCount,
            'tool_results' => $response->toolResults,
            'result' => $response->result,
            'error' => $response->error,
        ], $response->success ? 200 : 422);
    }

    /**
     * POST /api/v1/autonomous-collector/message
     */
    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'message' => 'required|string',
        ]);

        $response = $this->collector->process(
            $validated['session_id'],
            $validated['message']
        );

        return response()->json([
            'success' => $response->success,
            'session_id' => $validated['session_id'],
            'message' => $response->message,
            'status' => $response->status,
            'collected_data' => $response->collectedData,
            'is_complete' => $response->isComplete,
            'is_cancelled' => $response->isCancelled,
            'requires_confirmation' => $response->requiresConfirmation,
            'turn_count' => $response->turnCount,
            'tool_results' => $response->toolResults,
            'result' => $response->result,
            'error' => $response->error,
        ], $response->success ? 200 : 422);
    }

    /**
     * GET /api/v1/autonomous-collector/status/{sessionId}
     */
    public function status(string $sessionId): JsonResponse
    {
        if (!$this->collector->hasSession($sessionId)) {
            return response()->json([
                'success' => false,
                'error' => 'Session not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'status' => $this->collector->getStatus($sessionId),
            'is_active' => true,
        ]);
    }

    /**
     * POST /api/v1/autonomous-collector/confirm
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        $response = $this->collector->confirm($validated['session_id']);

        return response()->json([
            'success' => $response->success,
            'session_id' => $validated['session_id'],
            'message' => $response->message,
            'status' => $response->status,
            'collected_data' => $response->collectedData,
            'is_complete' => $response->isComplete,
            'is_cancelled' => $response->isCancelled,
            'requires_confirmation' => $response->requiresConfirmation,
            'turn_count' => $response->turnCount,
            'tool_results' => $response->toolResults,
            'result' => $response->result,
            'error' => $response->error,
        ], $response->success ? 200 : 422);
    }

    /**
     * POST /api/v1/autonomous-collector/cancel
     */
    public function cancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        $sessionId = $validated['session_id'];
        if (!$this->collector->hasSession($sessionId)) {
            return response()->json([
                'success' => false,
                'error' => 'Session not found.',
            ], 404);
        }

        $response = $this->collector->process($sessionId, 'cancel');

        return response()->json([
            'success' => $response->success,
            'session_id' => $sessionId,
            'message' => $response->message,
            'status' => $response->status,
            'is_cancelled' => $response->isCancelled,
            'error' => $response->error,
        ], $response->success ? 200 : 422);
    }

    /**
     * GET /api/v1/autonomous-collector/data/{sessionId}
     */
    public function data(string $sessionId): JsonResponse
    {
        if (!$this->collector->hasSession($sessionId)) {
            return response()->json([
                'success' => false,
                'error' => 'Session not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'status' => $this->collector->getStatus($sessionId),
            'data' => $this->collector->getData($sessionId),
        ]);
    }
}
