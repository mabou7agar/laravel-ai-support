<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Http\Requests\ImageOperationRequest;
use LaravelAIEngine\Services\Media\ImageOperationService;

class ImageOperationApiController extends Controller
{
    public function __construct(
        protected ImageOperationService $images,
    ) {}

    /**
     * Apply an image-editing operation (bg removal, cleanup, upscale, sketch-to-image, etc.).
     */
    public function apply(ImageOperationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $userId = $validated['user_id']
            ?? $request->user()?->getAuthIdentifier();

        $params = array_intersect_key($validated, array_flip([
            'image', 'mask', 'prompt', 'target_width', 'target_height',
        ]));

        try {
            $response = $this->images->apply(
                operation: $validated['operation'],
                params: $params,
                engine: $validated['engine'] ?? null,
                userId: $userId !== null ? (string) $userId : null,
            );

            return response()->json([
                'success' => $response->getError() === null,
                'data' => [
                    'operation' => $validated['operation'],
                    'files' => $response->getFiles(),
                    'metadata' => $response->getMetadata(),
                    'error' => $response->getError(),
                ],
            ], $response->getError() === null ? 200 : 502);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->error('Image operation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
