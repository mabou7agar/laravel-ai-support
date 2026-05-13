<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\Catalog\EngineCatalogService;

class EngineCatalogController extends Controller
{
    public function __construct(protected EngineCatalogService $catalog)
    {
    }

    public function models(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'engine' => 'nullable|string',
        ]);

        $engine = isset($validated['engine']) ? trim((string) $validated['engine']) : null;
        $models = $this->catalog->flatModels($engine);

        return response()->json([
            'success' => true,
            'data' => [
                'models' => $models,
                'count' => count($models),
                'engine' => $engine,
            ],
        ]);
    }

    public function engines(): JsonResponse
    {
        $engines = $this->catalog->engines();

        return response()->json([
            'success' => true,
            'data' => [
                'engines' => $engines,
                'count' => count($engines),
                'default_engine' => config('ai-engine.default', EngineEnum::OPENAI),
                'default_model' => config('ai-engine.default_model', 'gpt-4o'),
            ],
        ]);
    }

    public function enginesWithModels(): JsonResponse
    {
        $catalog = $this->catalog->catalog();

        return response()->json([
            'success' => true,
            'data' => [
                'engines' => $catalog,
                'count' => count($catalog),
                'default_engine' => config('ai-engine.default', EngineEnum::OPENAI),
                'default_model' => config('ai-engine.default_model', 'gpt-4o'),
            ],
        ]);
    }
}
