<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

class VectorStoreApiController extends Controller
{
    public function __construct(
        protected RAGCollectionDiscovery $collectionDiscovery,
    ) {}

    public function collections(): JsonResponse
    {
        try {
            $collections = $this->collectionDiscovery->discover();

            return response()->json([
                'success' => true,
                'data' => [
                    'collections' => $collections,
                    'count' => count($collections),
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
