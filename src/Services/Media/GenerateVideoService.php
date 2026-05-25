<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Fal\FalAsyncVideoService;
use Throwable;

class GenerateVideoService
{
    public function __construct(
        private readonly AIEngineService $ai,
        private readonly FalAsyncVideoService $asyncVideo,
        private readonly GenerateApiResponseFactory $responses,
        private readonly GenerateApiUserResolver $users
    ) {}

    public function generate(array $validated): JsonResponse
    {
        try {
            $engine = (string) ($validated['engine'] ?? 'fal_ai');
            $model = (string) ($validated['model'] ?? $this->defaultModel($validated));
            $parameters = $this->parameters($validated);
            $prompt = trim((string) ($validated['prompt'] ?? ''));

            if ($validationError = $this->validateResolvedRequest($model, $prompt, $parameters)) {
                return $validationError;
            }

            if ((bool) ($validated['async'] ?? false)) {
                $submitted = $this->asyncVideo->submit(
                    $prompt,
                    array_merge($parameters, ['model' => $model]),
                    $this->users->id()
                );

                return $this->responses->submittedJob($submitted, 'Video job submitted successfully.');
            }

            $response = $this->ai->generateDirect(new AIRequest(
                prompt: $prompt,
                engine: $engine,
                model: $model,
                parameters: $parameters,
                userId: $this->users->id(),
            ));

            if (!$response->isSuccessful()) {
                return $this->responses->failedGeneration($response, 'Video generation failed.', $engine, $model);
            }

            return $this->responses->successfulMedia($response, 'Video generated successfully.');
        } catch (InsufficientCreditsException $e) {
            return $this->responses->insufficientCredits($e);
        } catch (Throwable $e) {
            Log::error('AI generate video failed', ['error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Video generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    public function status(string $jobId, bool $refresh): JsonResponse
    {
        try {
            $status = $this->asyncVideo->getStatus($jobId, $refresh);
            if ($status === null) {
                return $this->responses->envelope(
                    success: false,
                    message: 'Video job not found.',
                    error: ['message' => 'Video job not found.'],
                    status: 404
                );
            }

            return $this->responses->envelope(
                success: true,
                message: 'Video job status fetched successfully.',
                data: $status
            );
        } catch (Throwable $e) {
            Log::error('AI video job status failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Video job status lookup failed.',
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
            $status = $this->asyncVideo->handleWebhook($jobId, $token, $payload);

            return $this->responses->envelope(
                success: true,
                message: 'Webhook accepted.',
                data: [
                    'job_id' => $jobId,
                    'status' => $status['status'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('AI video webhook rejected', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Webhook processing failed.',
                error: ['message' => $e->getMessage()],
                status: 422
            );
        }
    }

    private function parameters(array $validated): array
    {
        $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];

        foreach (['duration', 'aspect_ratio', 'resolution'] as $key) {
            if (!empty($validated[$key])) {
                $parameters[$key] = (string) $validated[$key];
            }
        }
        if (isset($validated['seed'])) {
            $parameters['seed'] = (int) $validated['seed'];
        }
        if (!empty($validated['start_image_url'])) {
            $parameters['start_image_url'] = (string) $validated['start_image_url'];
            $parameters['image_url'] = (string) $validated['start_image_url'];
        }
        if (!empty($validated['end_image_url'])) {
            $parameters['end_image_url'] = (string) $validated['end_image_url'];
        }
        if (!empty($validated['reference_image_urls'])) {
            $parameters['reference_image_urls'] = $validated['reference_image_urls'];
        }
        $referenceVideoUrls = $this->referenceVideoUrls($validated);
        if ($referenceVideoUrls !== []) {
            $parameters['reference_video_urls'] = $referenceVideoUrls;
            $parameters['video_urls'] = $referenceVideoUrls;
        }
        $referenceAudioUrls = $this->referenceAudioUrls($validated);
        if ($referenceAudioUrls !== []) {
            $parameters['reference_audio_urls'] = $referenceAudioUrls;
            $parameters['audio_urls'] = $referenceAudioUrls;
        }
        if (!empty($validated['character_sources'])) {
            $parameters['character_sources'] = $validated['character_sources'];
        }
        if (array_key_exists('generate_audio', $validated)) {
            $parameters['generate_audio'] = (bool) $validated['generate_audio'];
        }
        if (!empty($validated['multi_prompt'])) {
            $parameters['multi_prompt'] = $validated['multi_prompt'];
        }
        if (array_key_exists('use_webhook', $validated)) {
            $parameters['use_webhook'] = (bool) $validated['use_webhook'];
        }

        return $parameters;
    }

    private function defaultModel(array $validated): string
    {
        if ($this->referenceVideoUrls($validated) !== [] || $this->referenceAudioUrls($validated) !== []) {
            return EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO;
        }

        if (!empty($validated['character_sources']) || !empty($validated['reference_image_urls'])) {
            return EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO;
        }

        if (!empty($validated['start_image_url']) || !empty($validated['end_image_url'])) {
            return EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO;
        }

        return EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO;
    }

    private function validateResolvedRequest(string $model, string $prompt, array $parameters): ?JsonResponse
    {
        if ($model === EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO && $prompt === '') {
            return $this->responses->envelope(
                success: false,
                message: 'Prompt is required for text-to-video generation.',
                error: ['message' => 'Prompt is required for text-to-video generation.'],
                status: 422
            );
        }

        if (in_array($model, [
            EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO,
        ], true) && empty($parameters['start_image_url']) && empty($parameters['image_url'])) {
            return $this->responses->envelope(
                success: false,
                message: 'This video model requires start_image_url.',
                error: ['message' => 'This video model requires start_image_url.'],
                status: 422
            );
        }

        if (in_array($model, [
            EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO,
            EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO,
        ], true) && empty($parameters['reference_image_urls'])
            && empty($parameters['character_sources'])
            && empty($parameters['reference_video_urls'])
            && empty($parameters['video_urls'])) {
            return $this->responses->envelope(
                success: false,
                message: 'This video model requires reference_image_urls, reference_video_urls, or character_sources.',
                error: ['message' => 'This video model requires reference_image_urls, reference_video_urls, or character_sources.'],
                status: 422
            );
        }

        return null;
    }

    private function referenceVideoUrls(array $validated): array
    {
        return $this->uniqueStringList(array_merge(
            $validated['reference_video_urls'] ?? [],
            $validated['animation_reference_urls'] ?? [],
            $validated['video_urls'] ?? [],
            isset($validated['animation_reference_url']) ? [(string) $validated['animation_reference_url']] : []
        ));
    }

    private function referenceAudioUrls(array $validated): array
    {
        return $this->uniqueStringList(array_merge(
            $validated['reference_audio_urls'] ?? [],
            $validated['audio_urls'] ?? []
        ));
    }

    private function uniqueStringList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(static function ($value): ?string {
            if (!is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }, $values))));
    }
}
