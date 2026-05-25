<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use Throwable;

class GenerateImageService
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
            $engine = (string) ($validated['engine'] ?? $this->defaultEngine($validated));
            $model = (string) ($validated['model'] ?? $this->defaultModel($validated));
            $request = $this->requests->image($validated, $engine, $model, $this->users->id());
            $parameters = $request->getParameters();

            if ($model === EntityEnum::FAL_NANO_BANANA_2_EDIT
                && empty($parameters['source_images'])
                && empty($parameters['character_sources'])) {
                return $this->responses->envelope(
                    success: false,
                    message: 'Nano Banana edit requires source_images or character_sources.',
                    error: ['message' => 'Nano Banana edit requires source_images or character_sources.'],
                    status: 422
                );
            }

            $response = $this->ai->generateDirect($request);
            if (!$response->isSuccessful()) {
                return $this->responses->failedGeneration($response, 'Image generation failed.', $engine, $model);
            }

            return $this->responses->successfulMedia($response, 'Image generated successfully.');
        } catch (InsufficientCreditsException $e) {
            return $this->responses->insufficientCredits($e);
        } catch (Throwable $e) {
            Log::error('AI generate image failed', ['error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Image generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    private function defaultEngine(array $validated): string
    {
        return $this->usesFalWorkflow($validated) ? 'fal_ai' : 'openai';
    }

    private function defaultModel(array $validated): string
    {
        if (!$this->usesFalWorkflow($validated)) {
            return EntityEnum::GPT_IMAGE_1_MINI;
        }

        if (($validated['mode'] ?? null) === 'edit'
            || !empty($validated['source_images'])
            || !empty($validated['character_sources'])) {
            return EntityEnum::FAL_NANO_BANANA_2_EDIT;
        }

        return EntityEnum::FAL_NANO_BANANA_2;
    }

    private function usesFalWorkflow(array $validated): bool
    {
        foreach (['frame_count', 'mode', 'source_images', 'character_sources', 'aspect_ratio', 'resolution', 'thinking_level', 'output_format'] as $field) {
            if (array_key_exists($field, $validated)) {
                return true;
            }
        }

        return false;
    }
}
