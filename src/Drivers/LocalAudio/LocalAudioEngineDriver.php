<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\LocalAudio;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use Symfony\Component\Process\Process;

class LocalAudioEngineDriver extends BaseEngineDriver
{
    use BuildsMediaResponses;

    protected ?Client $httpClient;

    public function __construct(array $config, ?Client $httpClient = null)
    {
        parent::__construct($config);

        $baseUrl = trim((string) ($this->config['base_url'] ?? ''));
        $this->httpClient = $httpClient ?? new Client(array_filter([
            'timeout' => $this->getTimeout(),
            'base_uri' => $baseUrl !== '' ? rtrim($baseUrl, '/').'/' : null,
            'headers' => $this->headers(),
        ]));
    }

    public function generate(AIRequest $request): AIResponse
    {
        if ($request->getFiles() !== [] || $this->isSpeechToTextModel($request->getModel()->value)) {
            return $this->audioToText($request);
        }

        return $this->generateAudio($request);
    }

    public function stream(AIRequest $request): \Generator
    {
        yield $this->generate($request)->getContent();
    }

    public function validateRequest(AIRequest $request): bool
    {
        $section = $request->getFiles() !== [] ? $this->sttConfig() : $this->ttsConfig();
        $mode = $this->mode($section);

        return $mode === 'command'
            ? $this->command($section) !== []
            : trim((string) ($this->config['base_url'] ?? $section['base_url'] ?? '')) !== '';
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::LocalAudio;
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::LOCAL_WHISPER => ['name' => 'Local Whisper-compatible STT', 'type' => 'audio'],
            EntityEnum::LOCAL_TTS => ['name' => 'Local TTS-compatible voice', 'type' => 'audio'],
        ];
    }

    public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
    {
        throw new AIEngineException('Local audio driver does not support JSON analysis.');
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        return $this->mode($this->ttsConfig()) === 'command'
            ? $this->generateAudioWithCommand($request)
            : $this->generateAudioWithOpenAICompatibleHttp($request);
    }

    public function audioToText(AIRequest $request): AIResponse
    {
        return $this->mode($this->sttConfig()) === 'command'
            ? $this->transcribeWithCommand($request)
            : $this->transcribeWithOpenAICompatibleHttp($request);
    }

    protected function doSpeechToSpeech(AIRequest $request): AIResponse
    {
        $transcription = $this->audioToText($request);
        if (!$transcription->isSuccessful()) {
            return $transcription;
        }

        $speechRequest = new AIRequest(
            prompt: $transcription->getContent(),
            engine: $request->getEngine(),
            model: EntityEnum::LOCAL_TTS,
            parameters: $request->getParameters(),
            userId: $request->getUserId(),
            conversationId: $request->getConversationId(),
            context: $request->getContext(),
            metadata: $request->getMetadata()
        );

        $speech = $this->generateAudio($speechRequest);

        return AIResponse::success($transcription->getContent(), $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::LocalAudio->value,
            'service' => 'speech_to_speech',
            'transcription' => $transcription->getContent(),
            'speech' => $speech->getMetadata(),
        ])->withFiles($speech->toArray()['files'] ?? []);
    }

    protected function transcribeWithOpenAICompatibleHttp(AIRequest $request): AIResponse
    {
        $file = $request->getFiles()[0] ?? $request->getParameters()['file'] ?? null;
        if (!is_string($file) || !is_readable($file)) {
            throw new AIEngineException('Readable audio file is required for local speech-to-text.');
        }

        $config = $this->sttConfig();
        $parameters = $request->getParameters();
        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($file, 'r'),
                'filename' => basename($file),
            ],
            [
                'name' => 'model',
                'contents' => (string) ($parameters['model'] ?? $config['model'] ?? $request->getModel()->value),
            ],
        ];

        $providerParameters = array_replace(
            $this->scalarParameters($config, ['mode', 'path', 'command', 'output_path', 'base_url', 'model']),
            $this->scalarParameters($parameters, ['file', 'model'])
        );

        foreach ($providerParameters as $name => $value) {
            $multipart[] = ['name' => (string) $name, 'contents' => (string) $value];
        }

        try {
            $response = $this->httpClient()->post($this->path($config, '/audio/transcriptions'), [
                'headers' => $this->headers(),
                'multipart' => $multipart,
            ]);
        } catch (GuzzleException $e) {
            return AIResponse::error('Local speech-to-text request failed: '.$e->getMessage(), $request->getEngine(), $request->getModel());
        }

        $data = json_decode($response->getBody()->getContents(), true) ?: [];
        $text = (string) ($data['text'] ?? $data['transcript'] ?? $data['result']['text'] ?? '');

        return AIResponse::success($text, $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::LocalAudio->value,
            'service' => 'speech_to_text',
            'mode' => 'openai_compatible',
            'model' => (string) ($parameters['model'] ?? $config['model'] ?? $request->getModel()->value),
            'usage' => $data['usage'] ?? [],
            'duration' => $data['duration'] ?? null,
        ])->withUsage(creditsUsed: $request->getModel()->creditIndex());
    }

    protected function generateAudioWithOpenAICompatibleHttp(AIRequest $request): AIResponse
    {
        $config = $this->ttsConfig();
        $parameters = $request->getParameters();
        $format = (string) ($parameters['response_format'] ?? $parameters['format'] ?? $config['response_format'] ?? 'mp3');
        $payload = array_filter(array_replace([
            'model' => (string) ($parameters['model'] ?? $config['model'] ?? $request->getModel()->value),
            'input' => $request->getPrompt(),
            'voice' => (string) ($parameters['voice'] ?? $config['voice'] ?? 'default'),
            'response_format' => $format,
        ], $this->scalarParameters($parameters, ['model', 'format'])), static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $response = $this->httpClient()->post($this->path($config, '/audio/speech'), [
                'headers' => $this->headers(json: true),
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            return AIResponse::error('Local text-to-speech request failed: '.$e->getMessage(), $request->getEngine(), $request->getModel());
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $file = $this->storeMediaBytes(
            $response->getBody()->getContents(),
            $request,
            $this->extensionFromContentType($contentType, $this->audioExtensionFromFormat($format))
        );

        return AIResponse::success('', $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::LocalAudio->value,
            'service' => 'text_to_speech',
            'mode' => 'openai_compatible',
            'model' => $payload['model'],
            'voice' => $payload['voice'] ?? null,
            'response_format' => $format,
        ])->withFiles([$file])
          ->withUsage(creditsUsed: max(1, strlen($request->getPrompt()) / 1000) * $request->getModel()->creditIndex());
    }

    protected function transcribeWithCommand(AIRequest $request): AIResponse
    {
        $file = $request->getFiles()[0] ?? $request->getParameters()['file'] ?? null;
        if (!is_string($file) || !is_readable($file)) {
            throw new AIEngineException('Readable audio file is required for local command speech-to-text.');
        }

        $config = $this->sttConfig();
        $process = $this->runCommand($this->command($config), [
            'input' => $file,
            'model' => (string) ($request->getParameters()['model'] ?? $config['model'] ?? $request->getModel()->value),
            'language' => (string) ($request->getParameters()['language'] ?? $config['language'] ?? ''),
        ]);

        $text = trim($process->getOutput());
        $outputPath = (string) ($config['output_path'] ?? '');
        if ($text === '' && $outputPath !== '' && is_readable($outputPath)) {
            $text = trim((string) file_get_contents($outputPath));
        }

        return AIResponse::success($text, $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::LocalAudio->value,
            'service' => 'speech_to_text',
            'mode' => 'command',
            'exit_code' => $process->getExitCode(),
        ])->withUsage(creditsUsed: $request->getModel()->creditIndex());
    }

    protected function generateAudioWithCommand(AIRequest $request): AIResponse
    {
        $config = $this->ttsConfig();
        $outputPath = (string) ($config['output_path'] ?? tempnam(sys_get_temp_dir(), 'ai-engine-local-tts-').'.wav');
        @unlink($outputPath);

        $process = $this->runCommand($this->command($config), [
            'text' => $request->getPrompt(),
            'output' => $outputPath,
            'model' => (string) ($request->getParameters()['model'] ?? $config['model'] ?? $request->getModel()->value),
            'voice' => (string) ($request->getParameters()['voice'] ?? $config['voice'] ?? ''),
        ]);

        $bytes = is_readable($outputPath)
            ? (string) file_get_contents($outputPath)
            : $process->getOutput();

        if ($bytes === '') {
            throw new AIEngineException('Local command text-to-speech did not produce audio output.');
        }

        $file = $this->storeMediaBytes(
            $bytes,
            $request,
            $this->audioExtensionFromFormat((string) ($request->getParameters()['response_format'] ?? $config['response_format'] ?? pathinfo($outputPath, PATHINFO_EXTENSION) ?: 'wav'))
        );

        return AIResponse::success('', $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::LocalAudio->value,
            'service' => 'text_to_speech',
            'mode' => 'command',
            'exit_code' => $process->getExitCode(),
        ])->withFiles([$file])
          ->withUsage(creditsUsed: max(1, strlen($request->getPrompt()) / 1000) * $request->getModel()->creditIndex());
    }

    protected function runCommand(array $command, array $replacements): Process
    {
        if ($command === []) {
            throw new AIEngineException('Local audio command is not configured.');
        }

        $command = array_map(function (mixed $part) use ($replacements): string {
            $part = (string) $part;
            foreach ($replacements as $key => $value) {
                $part = str_replace('{'.$key.'}', (string) $value, $part);
            }

            return $part;
        }, $command);

        $process = new Process($command, timeout: $this->getTimeout());
        $process->run();

        if (!$process->isSuccessful()) {
            throw new AIEngineException('Local audio command failed: '.$process->getErrorOutput());
        }

        return $process;
    }

    protected function sttConfig(): array
    {
        return (array) ($this->config['stt'] ?? []);
    }

    protected function ttsConfig(): array
    {
        return (array) ($this->config['tts'] ?? []);
    }

    protected function mode(array $config): string
    {
        return strtolower((string) ($config['mode'] ?? $this->config['mode'] ?? 'openai_compatible'));
    }

    protected function command(array $config): array
    {
        $command = $config['command'] ?? [];

        return is_array($command) ? array_values($command) : [];
    }

    protected function path(array $config, string $fallback): string
    {
        return ltrim((string) ($config['path'] ?? $fallback), '/');
    }

    protected function headers(bool $json = false): array
    {
        $headers = [
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];

        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    protected function scalarParameters(array $parameters, array $except = []): array
    {
        return array_filter($parameters, static fn (mixed $value, string|int $key): bool => !in_array((string) $key, $except, true)
            && (is_scalar($value) || $value === null), ARRAY_FILTER_USE_BOTH);
    }

    protected function audioExtensionFromFormat(string $format): string
    {
        $format = strtolower($format);

        return match ($format) {
            'mpeg', 'mp3' => 'mp3',
            'wav', 'pcm', 'pcm16' => 'wav',
            'opus', 'ogg' => 'ogg',
            'flac' => 'flac',
            default => $format !== '' ? preg_replace('/[^a-z0-9]+/', '', $format) ?: 'mp3' : 'mp3',
        };
    }

    protected function isSpeechToTextModel(string $model): bool
    {
        $model = strtolower($model);

        return str_contains($model, 'whisper')
            || str_contains($model, 'transcribe')
            || str_contains($model, 'speech-to-text')
            || str_contains($model, 'stt');
    }

    protected function httpClient(): Client
    {
        if (!$this->httpClient instanceof Client) {
            throw new AIEngineException('Local audio HTTP client is not configured.');
        }

        return $this->httpClient;
    }

    protected function getSupportedCapabilities(): array
    {
        return ['audio', 'speech', 'speech_to_text', 'text_to_speech', 'speech_to_speech', 'tts', 'sts'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::LocalAudio;
    }

    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::from(EntityEnum::LOCAL_TTS);
    }

    protected function validateConfig(): void
    {
    }
}
