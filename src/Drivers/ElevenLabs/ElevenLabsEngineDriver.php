<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\ElevenLabs;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class ElevenLabsEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->httpClient = new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
            'headers' => $this->buildHeaders(),
        ]);
    }

    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse
    {
        $contentType = $request->model->getContentType();
        
        return match ($contentType) {
            'audio' => $this->generateAudio($request),
            'speech' => $this->audioToText($request),
            default => throw new \InvalidArgumentException("Unsupported content type: {$contentType}")
        };
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        throw new \InvalidArgumentException('Streaming not supported by ElevenLabs');
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($this->getApiKey())) {
            return false;
        }
        
        if (!$this->supports($request->model->getContentType())) {
            return false;
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::ELEVENLABS;
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities());
    }

    /**
     * Test the engine connection
     */
    public function test(): bool
    {
        try {
            $testRequest = new AIRequest(
                prompt: 'Hello world',
                engine: EngineEnum::ELEVENLABS,
                model: EntityEnum::ELEVENLABS_MULTILINGUAL_V2
            );
            
            $response = $this->generateAudio($testRequest);
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate text content (not supported)
     */
    public function generateText(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Text generation not supported by ElevenLabs',
            $request->engine,
            $request->model
        );
    }

    /**
     * Generate audio/speech
     */
    public function generateAudio(AIRequest $request): AIResponse
    {
        try {
            $voiceId = $request->parameters['voice_id'] ?? 'pNInz6obpgDQGcFmaJgB'; // Default voice
            $stability = $request->parameters['stability'] ?? 0.5;
            $similarityBoost = $request->parameters['similarity_boost'] ?? 0.5;
            $style = $request->parameters['style'] ?? 0.0;
            $useSpeakerBoost = $request->parameters['use_speaker_boost'] ?? true;

            $payload = [
                'text' => $request->prompt,
                'model_id' => $request->model->value,
                'voice_settings' => [
                    'stability' => $stability,
                    'similarity_boost' => $similarityBoost,
                    'style' => $style,
                    'use_speaker_boost' => $useSpeakerBoost,
                ],
            ];

            $response = $this->httpClient->post("/v1/text-to-speech/{$voiceId}", [
                'json' => $payload,
            ]);

            $audioData = $response->getBody()->getContents();
            $filename = $this->saveAudioFile($audioData);

            $charactersUsed = strlen($request->prompt);

            return AIResponse::success(
                $request->prompt,
                $request->engine,
                $request->model
            )->withFiles([$filename])
             ->withUsage(
                 creditsUsed: $charactersUsed * $request->model->creditIndex()
             )->withDetailedUsage([
                 'voice_id' => $voiceId,
                 'characters_used' => $charactersUsed,
                 'audio_duration' => $this->estimateAudioDuration($charactersUsed),
                 'voice_settings' => [
                     'stability' => $stability,
                     'similarity_boost' => $similarityBoost,
                     'style' => $style,
                 ],
             ]);

        } catch (RequestException $e) {
            return AIResponse::error(
                'ElevenLabs API error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        } catch (\Exception $e) {
            return AIResponse::error(
                'Unexpected error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Generate streaming audio
     */
    public function generateAudioStream(AIRequest $request): \Generator
    {
        try {
            $voiceId = $request->parameters['voice_id'] ?? 'pNInz6obpgDQGcFmaJgB';
            
            $payload = [
                'text' => $request->prompt,
                'model_id' => $request->model->value,
                'voice_settings' => [
                    'stability' => $request->parameters['stability'] ?? 0.5,
                    'similarity_boost' => $request->parameters['similarity_boost'] ?? 0.5,
                ],
            ];

            $response = $this->httpClient->post("/v1/text-to-speech/{$voiceId}/stream", [
                'json' => $payload,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk) {
                    yield $chunk;
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('ElevenLabs streaming error: ' . $e->getMessage());
        }
    }

    /**
     * Speech to text (not directly supported by ElevenLabs)
     */
    public function speechToText(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Speech-to-text not supported by ElevenLabs',
            $request->engine,
            $request->model
        );
    }

    /**
     * Voice cloning
     */
    public function cloneVoice(AIRequest $request): AIResponse
    {
        try {
            $name = $request->parameters['voice_name'] ?? 'Cloned Voice';
            $description = $request->parameters['description'] ?? 'Voice cloned via Laravel AI Engine';
            $files = $request->files ?? [];

            if (empty($files)) {
                throw new \InvalidArgumentException('Audio files are required for voice cloning');
            }

            $multipart = [
                ['name' => 'name', 'contents' => $name],
                ['name' => 'description', 'contents' => $description],
            ];

            foreach ($files as $index => $file) {
                $multipart[] = [
                    'name' => 'files',
                    'contents' => fopen($file, 'r'),
                    'filename' => basename($file),
                ];
            }

            $response = $this->httpClient->post('/v1/voices/add', [
                'multipart' => $multipart,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return AIResponse::success(
                "Voice '{$name}' cloned successfully",
                $request->engine,
                $request->model
            )->withDetailedUsage([
                'voice_id' => $data['voice_id'] ?? null,
                'voice_name' => $name,
                'files_processed' => count($files),
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Voice cloning error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Get available voices
     */
    public function getAvailableVoices(): array
    {
        try {
            $response = $this->httpClient->get('/v1/voices');
            $data = json_decode($response->getBody()->getContents(), true);
            
            return array_map(function ($voice) {
                return [
                    'id' => $voice['voice_id'],
                    'name' => $voice['name'],
                    'category' => $voice['category'] ?? 'generated',
                    'description' => $voice['description'] ?? '',
                    'preview_url' => $voice['preview_url'] ?? null,
                    'settings' => $voice['settings'] ?? [],
                ];
            }, $data['voices'] ?? []);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->httpClient->get('/v1/models');
            $data = json_decode($response->getBody()->getContents(), true);
            
            return array_map(function ($model) {
                return [
                    'id' => $model['model_id'],
                    'name' => $model['name'],
                    'description' => $model['description'] ?? '',
                    'languages' => $model['languages'] ?? [],
                ];
            }, $data ?? []);

        } catch (\Exception $e) {
            return [
                ['id' => 'eleven_multilingual_v2', 'name' => 'Eleven Multilingual v2'],
                ['id' => 'eleven_turbo_v2', 'name' => 'Eleven Turbo v2'],
                ['id' => 'eleven_monolingual_v1', 'name' => 'Eleven Monolingual v1'],
            ];
        }
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['audio', 'tts', 'voice_cloning', 'streaming'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return new EngineEnum(EngineEnum::ELEVEN_LABS);
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::ELEVEN_MULTILINGUAL_V2;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \InvalidArgumentException('ElevenLabs API key is required');
        }
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'xi-api-key' => $this->getApiKey(),
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Save audio file to storage
     */
    private function saveAudioFile(string $audioData): string
    {
        $filename = 'ai_audio_' . uniqid() . '.mp3';
        $path = storage_path('app/public/ai-audio/' . $filename);
        
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
        file_put_contents($path, $audioData);
        
        return url('storage/ai-audio/' . $filename);
    }

    /**
     * Estimate audio duration based on character count
     */
    private function estimateAudioDuration(int $characters): float
    {
        // Rough estimate: ~150 words per minute, ~5 characters per word
        $wordsPerMinute = 150;
        $charactersPerWord = 5;
        $words = $characters / $charactersPerWord;
        $minutes = $words / $wordsPerMinute;
        
        return round($minutes * 60, 2); // Return seconds
    }
}
