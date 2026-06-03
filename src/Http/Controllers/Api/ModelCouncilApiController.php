<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Http\Requests\ModelCouncilRequest;
use LaravelAIEngine\Services\ModelCouncilService;

class ModelCouncilApiController extends Controller
{
    public function __construct(
        protected ModelCouncilService $council,
    ) {}

    /**
     * Run one prompt across several models and return every response for comparison.
     */
    public function run(ModelCouncilRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $userId = $validated['user_id']
            ?? $request->user()?->getAuthIdentifier();

        try {
            $results = $this->council->run(
                prompt: $validated['prompt'],
                members: $validated['members'],
                options: $validated['options'] ?? [],
                userId: $userId !== null ? (string) $userId : null,
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'prompt' => $validated['prompt'],
                    'members' => $results,
                    'count' => count($results),
                    'succeeded' => count(array_filter($results, static fn ($r) => $r['success'] ?? false)),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->error('Model council failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
