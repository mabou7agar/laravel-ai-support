<?php

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\AIModelRegistry;

/**
 * Model Recommendation API Controller
 * 
 * Provides intelligent model recommendations based on task requirements
 * with automatic offline fallback to Ollama.
 */
class ModelRecommendationController extends Controller
{
    public function __construct(
        protected AIModelRegistry $registry
    ) {}

    /**
     * Get recommended model for a task
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function recommend(Request $request): JsonResponse
    {
        $request->validate([
            'task' => 'required|string|in:vision,coding,reasoning,fast,cheap,quality,default',
            'provider' => 'nullable|string',
            'offline' => 'nullable|boolean',
        ]);

        $task = $request->input('task');
        $provider = $request->input('provider');
        $offline = $request->boolean('offline', false);

        $model = $this->registry->getRecommendedModel($task, $provider, $offline);

        if (!$model) {
            return response()->json([
                'success' => false,
                'error' => 'No suitable model found for the requested task',
                'task' => $task,
                'provider' => $provider,
                'offline_mode' => $offline,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'model' => [
                'id' => $model->id,
                'provider' => $model->provider,
                'model_id' => $model->model_id,
                'name' => $model->name,
                'description' => $model->description,
                'capabilities' => $model->capabilities,
                'context_window' => $model->context_window,
                'pricing' => $model->pricing,
                'supports_vision' => $model->supports_vision,
                'supports_function_calling' => $model->supports_function_calling,
                'supports_streaming' => $model->supports_streaming,
                'is_offline' => $model->provider === 'ollama',
            ],
            'task' => $task,
            'offline_mode' => $offline || $model->provider === 'ollama',
        ]);
    }

    /**
     * Get all recommendations for common tasks
     * 
     * @return JsonResponse
     */
    public function all(): JsonResponse
    {
        $tasks = ['vision', 'coding', 'reasoning', 'fast', 'cheap', 'quality'];
        $recommendations = [];

        foreach ($tasks as $task) {
            $model = $this->registry->getRecommendedModel($task);
            
            if ($model) {
                $recommendations[$task] = [
                    'provider' => $model->provider,
                    'model_id' => $model->model_id,
                    'name' => $model->name,
                    'pricing' => $model->pricing,
                    'is_offline' => $model->provider === 'ollama',
                ];
            }
        }

        return response()->json([
            'success' => true,
            'recommendations' => $recommendations,
            'online' => $this->registry->hasInternetConnection(),
        ]);
    }

    /**
     * Get cheapest model
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function cheapest(Request $request): JsonResponse
    {
        $provider = $request->input('provider');
        $model = $this->registry->getCheapestModel($provider);

        if (!$model) {
            return response()->json([
                'success' => false,
                'error' => 'No models found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'model' => [
                'provider' => $model->provider,
                'model_id' => $model->model_id,
                'name' => $model->name,
                'pricing' => $model->pricing,
            ],
        ]);
    }

    /**
     * Check if system is online or offline
     * 
     * @return JsonResponse
     */
    public function status(): JsonResponse
    {
        $online = $this->registry->hasInternetConnection();
        $ollamaModels = $this->registry->getModelsByProvider('ollama')->count();

        return response()->json([
            'success' => true,
            'online' => $online,
            'offline_ready' => $ollamaModels > 0,
            'ollama_models_count' => $ollamaModels,
            'mode' => $online ? 'online' : 'offline',
        ]);
    }
}
