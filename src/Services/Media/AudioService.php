<?php

namespace LaravelAIEngine\Services\Media;

use OpenAI\Contracts\ClientContract as OpenAIClient;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\CreditManager;

class AudioService
{
    protected OpenAIClient $client;
    protected CreditManager $creditManager;
    protected string $model;

    public function __construct(
        OpenAIClient $client,
        CreditManager $creditManager
    ) {
        $this->client = $client;
        $this->creditManager = $creditManager;
        $this->model = config('ai-engine.vector.media.whisper_model', 'whisper-1');
    }

    /**
     * Transcribe audio file using Whisper
     */
    public function transcribe(
        string $audioPath,
        ?string $userId = null,
        array $options = []
    ): string {
        try {
            if (!file_exists($audioPath)) {
                throw new \InvalidArgumentException("Audio file not found: {$audioPath}");
            }

            // Check file size (Whisper has 25MB limit)
            $fileSize = filesize($audioPath);
            if ($fileSize > 25 * 1024 * 1024) {
                throw new \InvalidArgumentException("Audio file too large. Maximum size is 25MB.");
            }

            $duration = $this->estimateAudioDuration($audioPath);
            $creditRequest = $this->buildCreditRequest($userId, 'audio_transcription', [
                'audio_seconds' => $duration,
                'audio_minutes' => max(1 / 60, $duration / 60),
            ]);
            $credits = $this->creditManager->calculateCredits($creditRequest);
            $this->assertCreditsAvailable($userId, $creditRequest, $credits);

            // Transcribe using Whisper API
            $response = $this->client->audio()->transcribe([
                'model' => $this->model,
                'file' => fopen($audioPath, 'r'),
                'response_format' => $options['format'] ?? 'text',
                'language' => $options['language'] ?? null,
                'prompt' => $options['prompt'] ?? null,
                'temperature' => $options['temperature'] ?? 0,
            ]);

            $transcription = is_string($response) ? $response : $response->text;

            $this->chargeCredits($userId, $creditRequest, $credits);

            Log::info('Audio transcribed with Whisper', [
                'audio_path' => $audioPath,
                'file_size' => $fileSize,
                'duration_estimate' => $duration,
                'transcription_length' => strlen($transcription),
            ]);

            return $transcription;
        } catch (\Exception $e) {
            Log::error('Audio transcription failed', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Transcribe with timestamps
     */
    public function transcribeWithTimestamps(
        string $audioPath,
        ?string $userId = null
    ): array {
        try {
            if (!file_exists($audioPath)) {
                throw new \InvalidArgumentException("Audio file not found: {$audioPath}");
            }

            $duration = $this->estimateAudioDuration($audioPath);
            $creditRequest = $this->buildCreditRequest($userId, 'audio_transcription', [
                'audio_seconds' => $duration,
                'audio_minutes' => max(1 / 60, $duration / 60),
                'timestamps' => true,
            ]);
            $credits = $this->creditManager->calculateCredits($creditRequest);
            $this->assertCreditsAvailable($userId, $creditRequest, $credits);

            $response = $this->client->audio()->transcribe([
                'model' => $this->model,
                'file' => fopen($audioPath, 'r'),
                'response_format' => 'verbose_json',
                'timestamp_granularities' => ['segment'],
            ]);

            $this->chargeCredits($userId, $creditRequest, $credits);

            return [
                'text' => $response->text,
                'segments' => $response->segments ?? [],
                'language' => $response->language ?? null,
                'duration' => $response->duration ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Audio transcription with timestamps failed', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Translate audio to English
     */
    public function translate(
        string $audioPath,
        ?string $userId = null
    ): string {
        try {
            if (!file_exists($audioPath)) {
                throw new \InvalidArgumentException("Audio file not found: {$audioPath}");
            }

            $duration = $this->estimateAudioDuration($audioPath);
            $creditRequest = $this->buildCreditRequest($userId, 'audio_translation', [
                'audio_seconds' => $duration,
                'audio_minutes' => max(1 / 60, $duration / 60),
            ]);
            $credits = $this->creditManager->calculateCredits($creditRequest);
            $this->assertCreditsAvailable($userId, $creditRequest, $credits);

            $response = $this->client->audio()->translate([
                'model' => $this->model,
                'file' => fopen($audioPath, 'r'),
                'response_format' => 'text',
            ]);

            $translation = is_string($response) ? $response : $response->text;

            $this->chargeCredits($userId, $creditRequest, $credits);

            Log::info('Audio translated with Whisper', [
                'audio_path' => $audioPath,
                'translation_length' => strlen($translation),
            ]);

            return $translation;
        } catch (\Exception $e) {
            Log::error('Audio translation failed', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Batch transcribe multiple audio files
     */
    public function transcribeBatch(
        array $audioPaths,
        ?string $userId = null,
        array $options = []
    ): array {
        $results = [];

        foreach ($audioPaths as $index => $audioPath) {
            try {
                $results[$index] = $this->transcribe($audioPath, $userId, $options);
            } catch (\Exception $e) {
                Log::error('Batch audio transcription failed for item', [
                    'index' => $index,
                    'audio_path' => $audioPath,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }
        }

        return $results;
    }

    /**
     * Detect language of audio
     */
    public function detectLanguage(string $audioPath, ?string $userId = null): ?string
    {
        try {
            if (!file_exists($audioPath)) {
                throw new \InvalidArgumentException("Audio file not found: {$audioPath}");
            }

            $duration = $this->estimateAudioDuration($audioPath);
            $creditRequest = $this->buildCreditRequest($userId, 'audio_language_detection', [
                'audio_seconds' => $duration,
                'audio_minutes' => max(1 / 60, $duration / 60),
            ]);
            $credits = $this->creditManager->calculateCredits($creditRequest);
            $this->assertCreditsAvailable($userId, $creditRequest, $credits);

            $response = $this->client->audio()->transcribe([
                'model' => $this->model,
                'file' => fopen($audioPath, 'r'),
                'response_format' => 'verbose_json',
            ]);

            $this->chargeCredits($userId, $creditRequest, $credits);

            return $response->language ?? null;
        } catch (InsufficientCreditsException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Language detection failed', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Estimate audio duration (rough approximation)
     */
    protected function estimateAudioDuration(string $audioPath): int
    {
        // Try to get duration using getID3 if available
        if (class_exists('\getID3')) {
            try {
                $getID3 = new \getID3();
                $fileInfo = $getID3->analyze($audioPath);
                return (int) ($fileInfo['playtime_seconds'] ?? 0);
            } catch (\Exception $e) {
                // Fall through to estimation
            }
        }

        // Rough estimation based on file size and format
        $fileSize = filesize($audioPath);
        $extension = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));

        // Rough bitrate estimates (kbps)
        $bitrates = [
            'mp3' => 128,
            'wav' => 1411,
            'ogg' => 128,
            'flac' => 1000,
            'm4a' => 128,
            'aac' => 128,
            'wma' => 128,
        ];

        $bitrate = $bitrates[$extension] ?? 128;
        $duration = (int) ($fileSize * 8 / ($bitrate * 1000));

        return max(1, $duration); // At least 1 second
    }

    protected function buildCreditRequest(?string $userId, string $operation, array $parameters = []): AIRequest
    {
        return new AIRequest(
            prompt: $operation,
            engine: EngineEnum::OPENAI,
            model: $this->model,
            parameters: $parameters,
            userId: $userId,
            metadata: [
                'source' => 'media.audio',
                'operation' => $operation,
            ]
        );
    }

    protected function assertCreditsAvailable(?string $userId, AIRequest $request, float $credits): void
    {
        if ($userId === null || $credits <= 0) {
            return;
        }

        if (!$this->creditManager->hasCreditsForAmount($userId, $request, $credits)) {
            throw new InsufficientCreditsException("Insufficient credits. Required: {$credits}");
        }
    }

    protected function chargeCredits(?string $userId, AIRequest $request, float $credits): void
    {
        if ($userId === null || $credits <= 0) {
            return;
        }

        $this->creditManager->deductCredits($userId, $request, $credits);
        CreditManager::accumulate($credits);
    }

    /**
     * Check if audio file is supported
     */
    public function isSupported(string $extension): bool
    {
        $supported = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma'];
        return in_array(strtolower($extension), $supported);
    }

    /**
     * Set Whisper model
     */
    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    /**
     * Get current model
     */
    public function getModel(): string
    {
        return $this->model;
    }
}
