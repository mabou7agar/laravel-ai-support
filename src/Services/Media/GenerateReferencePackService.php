<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService;
use Throwable;

class GenerateReferencePackService
{
    public function __construct(
        private readonly FalAsyncReferencePackGenerationService $referencePacks,
        private readonly GenerateApiResponseFactory $responses,
        private readonly GenerateApiUserResolver $users
    ) {}

    public function submit(array $validated, string $successMessage): JsonResponse
    {
        try {
            $prompt = (string) $validated['prompt'];
            unset($validated['prompt']);

            $submitted = $this->referencePacks->submit($prompt, $validated, $this->users->id());

            return $this->responses->submittedJob($submitted, $successMessage);
        } catch (InsufficientCreditsException $e) {
            return $this->responses->insufficientCredits($e);
        } catch (Throwable $e) {
            Log::error('AI reference pack submit failed', ['error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Reference pack submission failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    public function status(string $jobId, bool $refresh): JsonResponse
    {
        try {
            $status = $this->referencePacks->getStatus($jobId, $refresh);
            if ($status === null) {
                return $this->responses->envelope(
                    success: false,
                    message: 'Reference pack job not found.',
                    error: ['message' => 'Reference pack job not found.'],
                    status: 404
                );
            }

            return $this->responses->envelope(
                success: true,
                message: 'Reference pack job status fetched successfully.',
                data: $status
            );
        } catch (Throwable $e) {
            Log::error('AI reference pack job status failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Reference pack job status lookup failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    public function webhook(string $jobId, string $token, array $payload): JsonResponse
    {
        if ($jobId === '' || $token === '') {
            return $this->responses->envelope(
                success: false,
                message: 'Webhook job token is missing.',
                error: ['message' => 'Webhook job token is missing.'],
                status: 422
            );
        }

        try {
            $status = $this->referencePacks->handleWebhook($jobId, $token, $payload);

            return $this->responses->envelope(
                success: true,
                message: 'Webhook accepted.',
                data: [
                    'job_id' => $jobId,
                    'status' => $status['status'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('AI reference pack webhook rejected', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Webhook processing failed.',
                error: ['message' => $e->getMessage()],
                status: 422
            );
        }
    }
}
