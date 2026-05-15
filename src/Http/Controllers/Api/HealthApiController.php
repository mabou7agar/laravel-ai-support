<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

class HealthApiController extends Controller
{
    public function __construct(
        protected RAGCollectionDiscovery $collectionDiscovery,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $collections = $this->collectionDiscovery->discover();

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'healthy',
                    'version' => '1.0.0',
                    'vector_stores_enabled' => config('ai-engine.rag.enabled', true),
                    'collections_count' => count($collections),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
