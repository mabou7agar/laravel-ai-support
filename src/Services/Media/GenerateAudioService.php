<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Support\Fal\FalCharacterStore;
use Throwable;

class GenerateAudioService
{
    public function __construct(
        private readonly AIEngineService $ai,
        private readonly GenerateApiRequestFactory $requests,
        private readonly GenerateApiResponseFactory $responses,
        private readonly GenerateApiUserResolver $users,
        private readonly FalCharacterStore $characters
    ) {}

    public function transcribe(array $validated, ?UploadedFile $file): JsonResponse
    {
        try {
            $realPath = $file?->getRealPath();
            if (!is_string($realPath) || trim($realPath) === '') {
                return $this->responses->envelope(
                    success: false,
                    message: 'Uploaded file path could not be resolved.',
                    error: ['message' => 'Invalid uploaded audio file.'],
                    status: 422
                );
            }

            $providerPath = $this->providerAudioUploadPath($file, $realPath);

            try {
                $request = $this->requests->transcription($validated, $providerPath, $this->users->id());
                $response = $this->ai->generateDirect($request);
            } finally {
                if ($providerPath !== $realPath && is_file($providerPath)) {
                    @unlink($providerPath);
                }
            }

            if (!$response->isSuccessful()) {
                return $this->responses->failedGeneration(
                    $response,
                    'Audio transcription failed.',
                    $request->getEngine()->value,
                    $request->getModel()->value
                );
            }

            return $this->responses->successfulText($response, 'Audio transcribed successfully.');
        } catch (InsufficientCreditsException $e) {
            return $this->responses->insufficientCredits($e);
        } catch (Throwable $e) {
            Log::error('AI transcribe failed', ['error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Audio transcription failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    public function tts(array $validated): JsonResponse
    {
        try {
            $storedVoice = $this->storedCharacterVoice($validated);
            if ($storedVoice instanceof JsonResponse) {
                return $storedVoice;
            }

            $request = $this->requests->tts($validated, $storedVoice, $this->users->id());
            $response = $this->ai->generateDirect($request);

            if (!$response->isSuccessful()) {
                return $this->responses->failedGeneration(
                    $response,
                    'Text-to-speech generation failed.',
                    $request->getEngine()->value,
                    $request->getModel()->value
                );
            }

            return $this->responses->successfulMedia($response, 'Audio generated successfully.');
        } catch (InsufficientCreditsException $e) {
            return $this->responses->insufficientCredits($e);
        } catch (Throwable $e) {
            Log::error('AI tts failed', ['error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Text-to-speech generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    private function providerAudioUploadPath(UploadedFile $file, string $realPath): string
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension()));
        if ($extension === '' || str_ends_with(strtolower($realPath), '.' . $extension)) {
            return $realPath;
        }

        $path = tempnam(sys_get_temp_dir(), 'ai-engine-audio-');
        if ($path === false) {
            return $realPath;
        }

        $target = $path . '.' . $extension;
        if (!@copy($realPath, $target)) {
            @unlink($path);

            return $realPath;
        }

        @unlink($path);

        return $target;
    }

    private function storedCharacterVoice(array $validated): array|JsonResponse
    {
        $alias = null;
        if (!empty($validated['use_character'])) {
            $alias = trim((string) $validated['use_character']);
        } elseif (($validated['use_last_character'] ?? false) === true) {
            $lastCharacter = $this->characters->getLast();
            if (!is_array($lastCharacter)) {
                return $this->responses->envelope(
                    success: false,
                    message: 'No saved character is available.',
                    error: ['message' => 'No saved character is available.'],
                    status: 422
                );
            }

            $alias = (string) ($lastCharacter['alias'] ?? '');
            if ($alias === '') {
                return $this->responses->envelope(
                    success: false,
                    message: 'Last saved character is missing an alias.',
                    error: ['message' => 'Last saved character is missing an alias.'],
                    status: 422
                );
            }
        }

        if ($alias === null || $alias === '') {
            return [];
        }

        $character = $this->characters->get($alias);
        if (!is_array($character)) {
            return $this->responses->envelope(
                success: false,
                message: "Saved character [{$alias}] was not found.",
                error: ['message' => "Saved character [{$alias}] was not found."],
                status: 422
            );
        }

        $voice = $this->extractCharacterVoiceParameters($character);
        if ($voice === []) {
            return $this->responses->envelope(
                success: false,
                message: "Saved character [{$alias}] does not have voice metadata.",
                error: ['message' => "Saved character [{$alias}] does not have voice metadata."],
                status: 422
            );
        }

        return $voice;
    }

    private function extractCharacterVoiceParameters(array $character): array
    {
        $parameters = [];
        if (isset($character['voice_id']) && is_string($character['voice_id']) && trim($character['voice_id']) !== '') {
            $parameters['voice_id'] = trim($character['voice_id']);
        }

        $voiceSettings = is_array($character['voice_settings'] ?? null) ? $character['voice_settings'] : [];
        foreach (['stability', 'similarity_boost', 'style', 'use_speaker_boost'] as $key) {
            if (array_key_exists($key, $voiceSettings)) {
                $parameters[$key] = $voiceSettings[$key];
            }
        }

        return $parameters;
    }
}
