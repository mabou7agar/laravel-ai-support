<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\GoogleTTS;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;

class GoogleTTSEngineDriver extends BaseEngineDriver
{
    use BuildsMediaResponses;

    private Client $httpClient;

    public function __construct(array $config, ?Client $httpClient = null)
    {
        parent::__construct($config);

        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => rtrim($this->getBaseUrl(), '/').'/',
            'headers' => $this->buildHeaders(),
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        return match ($request->getModel()->getContentType()) {
            'audio' => $this->generateAudio($request),
            default => throw new AIEngineException('Google Text-to-Speech driver supports audio models only.'),
        };
    }

    public function stream(AIRequest $request): \Generator
    {
        yield $this->generate($request)->getContent();
    }

    public function validateRequest(AIRequest $request): bool
    {
        if ($this->getApiKey() === '' && $this->accessToken() === '') {
            throw new AIEngineException('Google Text-to-Speech requires GOOGLE_TTS_API_KEY or GOOGLE_TTS_ACCESS_TOKEN.');
        }

        if (trim($request->getPrompt()) === '' && trim((string) ($request->getParameters()['text'] ?? $request->getParameters()['ssml'] ?? '')) === '') {
            throw new AIEngineException('Text or SSML input is required for Google Text-to-Speech.');
        }

        return true;
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::GOOGLE_TTS);
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::GOOGLE_TTS => ['name' => 'Google Cloud Text-to-Speech', 'type' => 'audio'],
        ];
    }

    public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
    {
        throw new AIEngineException('Google Text-to-Speech does not support JSON analysis.');
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        $parameters = $request->getParameters();
        $audioEncoding = strtoupper((string) ($parameters['audio_encoding'] ?? $parameters['audioEncoding'] ?? 'MP3'));

        try {
            $response = $this->httpClient->post('text:synthesize', [
                'query' => $this->queryParameters(),
                'headers' => $this->requestHeaders(),
                'json' => [
                    'input' => $this->inputPayload($request),
                    'voice' => $this->voicePayload($parameters),
                    'audioConfig' => $this->audioConfigPayload($parameters, $audioEncoding),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true) ?: [];
            $audioContent = (string) ($data['audioContent'] ?? '');
            if ($audioContent === '') {
                throw new AIEngineException('Google Text-to-Speech response did not include audioContent.');
            }

            $bytes = base64_decode($audioContent, true);
            if ($bytes === false) {
                throw new AIEngineException('Google Text-to-Speech returned invalid base64 audioContent.');
            }

            $file = $this->storeMediaBytes($bytes, $request, $this->extensionFromAudioEncoding($audioEncoding));
            $voice = $this->voicePayload($parameters);

            return AIResponse::success(
                $request->getPrompt(),
                $request->getEngine(),
                $request->getModel(),
                [
                    'provider' => EngineEnum::GOOGLE_TTS,
                    'model' => $request->getModel()->value,
                    'voice' => $voice,
                    'audio_encoding' => $audioEncoding,
                ]
            )->withFiles([$file])
             ->withUsage(creditsUsed: max(1, strlen($request->getPrompt()) / 1000) * $request->getModel()->creditIndex());
        } catch (RequestException $e) {
            return AIResponse::error('Google Text-to-Speech request failed: '.$e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    protected function inputPayload(AIRequest $request): array
    {
        $parameters = $request->getParameters();

        if (!empty($parameters['ssml'])) {
            return ['ssml' => (string) $parameters['ssml']];
        }

        return ['text' => (string) ($parameters['text'] ?? $request->getPrompt())];
    }

    protected function voicePayload(array $parameters): array
    {
        return array_filter([
            'languageCode' => $parameters['language_code'] ?? $parameters['languageCode'] ?? $this->config['language_code'] ?? 'en-US',
            'name' => $parameters['voice'] ?? $parameters['voice_name'] ?? $parameters['name'] ?? $this->config['voice'] ?? null,
            'ssmlGender' => $parameters['ssml_gender'] ?? $parameters['ssmlGender'] ?? $this->config['ssml_gender'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    protected function audioConfigPayload(array $parameters, string $audioEncoding): array
    {
        return array_filter([
            'audioEncoding' => $audioEncoding,
            'speakingRate' => $parameters['speaking_rate'] ?? $parameters['speakingRate'] ?? null,
            'pitch' => $parameters['pitch'] ?? null,
            'volumeGainDb' => $parameters['volume_gain_db'] ?? $parameters['volumeGainDb'] ?? null,
            'sampleRateHertz' => $parameters['sample_rate_hertz'] ?? $parameters['sampleRateHertz'] ?? null,
            'effectsProfileId' => $parameters['effects_profile_id'] ?? $parameters['effectsProfileId'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    protected function queryParameters(): array
    {
        return $this->getApiKey() !== '' ? ['key' => $this->getApiKey()] : [];
    }

    protected function requestHeaders(): array
    {
        $token = $this->accessToken();

        return $token !== '' ? ['Authorization' => 'Bearer '.$token] : [];
    }

    protected function accessToken(): string
    {
        return trim((string) ($this->config['access_token'] ?? ''));
    }

    protected function extensionFromAudioEncoding(string $encoding): string
    {
        return match (strtoupper($encoding)) {
            'LINEAR16' => 'wav',
            'OGG_OPUS' => 'ogg',
            'MULAW' => 'wav',
            'ALAW' => 'wav',
            default => 'mp3',
        };
    }

    protected function getSupportedCapabilities(): array
    {
        return ['audio', 'speech', 'text_to_speech', 'tts'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::GOOGLE_TTS);
    }

    protected function getDefaultModel(): EntityEnum
    {
        return new EntityEnum(EntityEnum::GOOGLE_TTS);
    }

    protected function getBaseUrl(): string
    {
        return (string) ($this->config['base_url'] ?? 'https://texttospeech.googleapis.com/v1');
    }

    protected function validateConfig(): void
    {
    }
}
