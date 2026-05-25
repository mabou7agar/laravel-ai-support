<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Http\JsonResponse;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;

class GenerateApiResponseFactory
{
    public function envelope(
        bool $success,
        string $message,
        array $data = [],
        ?array $error = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'error' => $error,
            'meta' => [
                'status_code' => $status,
                'schema' => 'ai-engine.v1',
            ],
        ], $status);
    }

    public function insufficientCredits(InsufficientCreditsException $e): JsonResponse
    {
        return $this->envelope(
            success: false,
            message: 'Insufficient credits for this request.',
            error: ['message' => $e->getMessage()],
            status: 402
        );
    }

    public function successfulText(AIResponse $response, string $message): JsonResponse
    {
        return $this->envelope(
            success: true,
            message: $message,
            data: [
                'content' => $response->getContent(),
                'engine' => $response->getEngine()->value,
                'model' => $response->getModel()->value,
                'usage' => $response->getUsage(),
                'metadata' => $response->getMetadata(),
            ]
        );
    }

    public function successfulMedia(AIResponse $response, string $message): JsonResponse
    {
        return $this->envelope(
            success: true,
            message: $message,
            data: [
                'files' => $response->getFiles(),
                'content' => $response->getContent(),
                'engine' => $response->getEngine()->value,
                'model' => $response->getModel()->value,
                'usage' => $response->getUsage(),
                'metadata' => $response->getMetadata(),
            ]
        );
    }

    public function failedGeneration(
        AIResponse $response,
        string $fallbackMessage,
        string $engine,
        string $model
    ): JsonResponse {
        $message = $response->getError() ?? $fallbackMessage;

        return $this->envelope(
            success: false,
            message: $message,
            data: [
                'engine' => $engine,
                'model' => $model,
                'usage' => $response->getUsage(),
                'metadata' => $response->getMetadata(),
            ],
            error: ['message' => $message],
            status: 422
        );
    }

    public function submittedJob(array $submitted, string $message): JsonResponse
    {
        return $this->envelope(
            success: true,
            message: $message,
            data: [
                'job_id' => $submitted['job_id'],
                'status' => $submitted['status']['status'] ?? 'queued',
                'job' => $submitted['status'],
                'webhook_url' => $submitted['webhook_url'],
            ],
            status: 202
        );
    }
}
