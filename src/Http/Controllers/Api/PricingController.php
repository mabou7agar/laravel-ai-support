<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Http\Requests\PricingPreviewRequest;
use LaravelAIEngine\Services\Billing\PricingInspectionService;

class PricingController extends Controller
{
    public function __construct(
        private readonly PricingInspectionService $pricing
    ) {}

    public function preview(PricingPreviewRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'success' => true,
            'data' => $this->pricing->simulate(
                (string) $validated['engine'],
                (string) $validated['model'],
                (string) ($validated['prompt'] ?? ''),
                is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [],
                $request->user()?->getAuthIdentifier() !== null ? (string) $request->user()->getAuthIdentifier() : null
            ),
        ]);
    }
}
