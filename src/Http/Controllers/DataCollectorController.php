<?php

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\DataCollector\DataCollectorChatService;
use LaravelAIEngine\DTOs\DataCollectorConfig;

/**
 * Example controller for Data Collector Chat
 * 
 * This controller demonstrates how to integrate the Data Collector
 * into your application. You can extend or customize this for your needs.
 */
class DataCollectorController extends Controller
{
    public function __construct(
        protected DataCollectorChatService $dataCollector
    ) {}

    /**
     * Start a new data collection session
     * 
     * POST /api/ai-engine/data-collector/start
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'config_name' => 'required|string',
            'session_id' => 'nullable|string',
            'initial_data' => 'nullable|array',
        ]);

        $configName = $request->input('config_name');
        $sessionId = $request->input('session_id', 'dc-' . uniqid());
        $initialData = $request->input('initial_data', []);

        // Get the registered config
        $config = $this->dataCollector->getConfig($configName);
        
        if (!$config) {
            return response()->json([
                'success' => false,
                'error' => "Configuration '{$configName}' not found.",
            ], 404);
        }

        $response = $this->dataCollector->startCollection($sessionId, $config, $initialData);

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $response->getMetadata(),
        ]);
    }

    /**
     * Start a new data collection with inline config
     * 
     * POST /api/ai-engine/data-collector/start-custom
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function startCustom(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string',
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'fields' => 'required|array',
            'session_id' => 'nullable|string',
            'initial_data' => 'nullable|array',
            'confirm_before_complete' => 'nullable|boolean',
            'allow_enhancement' => 'nullable|boolean',
        ]);

        $sessionId = $request->input('session_id', 'dc-' . uniqid());
        
        $config = new DataCollectorConfig(
            name: $request->input('name'),
            title: $request->input('title', ''),
            description: $request->input('description', ''),
            fields: $request->input('fields'),
            confirmBeforeComplete: $request->input('confirm_before_complete', true),
            allowEnhancement: $request->input('allow_enhancement', true),
        );

        $response = $this->dataCollector->startCollection(
            $sessionId,
            $config,
            $request->input('initial_data', [])
        );

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $response->getMetadata(),
        ]);
    }

    /**
     * Process a message in an active data collection session
     * 
     * POST /api/ai-engine/data-collector/message
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function message(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'message' => 'required|string',
            'engine' => 'nullable|string',
            'model' => 'nullable|string',
        ]);

        $response = $this->dataCollector->processMessage(
            $request->input('session_id'),
            $request->input('message'),
            $request->input('engine', 'openai'),
            $request->input('model', 'gpt-4o')
        );

        return response()->json([
            'success' => $response->isSuccessful(),
            'message' => $response->getContent(),
            'actions' => $response->getActions(),
            'metadata' => $response->getMetadata(),
        ]);
    }

    /**
     * Get the current state of a data collection session
     * 
     * GET /api/ai-engine/data-collector/status/{sessionId}
     * 
     * @param string $sessionId
     * @return JsonResponse
     */
    public function status(string $sessionId): JsonResponse
    {
        $state = $this->dataCollector->getSessionState($sessionId);

        if (!$state) {
            return response()->json([
                'success' => false,
                'error' => 'Session not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'status' => $state->status,
            'data' => $state->getData(),
            'current_field' => $state->currentField,
            'is_complete' => $state->isComplete(),
            'is_cancelled' => $state->isCancelled(),
            'validation_errors' => $state->validationErrors,
        ]);
    }

    /**
     * Cancel a data collection session
     * 
     * POST /api/ai-engine/data-collector/cancel
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function cancel(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        $response = $this->dataCollector->cancelSession($request->input('session_id'));

        return response()->json([
            'success' => $response->isSuccessful(),
            'message' => $response->getContent(),
            'metadata' => $response->getMetadata(),
        ]);
    }

    /**
     * Get collected data from a session
     * 
     * GET /api/ai-engine/data-collector/data/{sessionId}
     * 
     * @param string $sessionId
     * @return JsonResponse
     */
    public function getData(string $sessionId): JsonResponse
    {
        $data = $this->dataCollector->getCollectedData($sessionId);

        if (empty($data) && !$this->dataCollector->isDataCollectionSession($sessionId)) {
            return response()->json([
                'success' => false,
                'error' => 'Session not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'data' => $data,
        ]);
    }
}
