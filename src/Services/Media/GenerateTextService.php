<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use Throwable;

class GenerateTextService
{
    public function __construct(
        private readonly AIEngineService $ai,
        private readonly GenerateApiRequestFactory $requests,
        private readonly GenerateApiResponseFactory $responses,
        private readonly GenerateApiUserResolver $users
    ) {}

    public function generate(array $validated): JsonResponse
    {
        try {
            $response = $this->ai->generateDirect($this->requests->text($validated, $this->users->id()));

            if (!$response->isSuccessful()) {
                return $this->responses->failedGeneration(
                    $response,
                    'Text generation failed.',
                    $response->getEngine()->value,
                    $response->getModel()->value
                );
            }

            return $this->responses->successfulText($response, 'Text generated successfully.');
        } catch (InsufficientCreditsException $e) {
            return $this->responses->insufficientCredits($e);
        } catch (Throwable $e) {
            Log::error('AI generate text failed', ['error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Text generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }
}
