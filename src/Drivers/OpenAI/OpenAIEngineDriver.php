<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Services\AIMediaManager;
use LaravelAIEngine\Services\ProviderTools\HostedArtifactService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolRunService;
use LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper;
use OpenAI;

class OpenAIEngineDriver extends BaseEngineDriver
{
    use BuildsMediaResponses;

    private Client $httpClient;
    private $openAIClient;

    public function __construct(array $config, Client $httpClient = null)
    {
        parent::__construct($config);
        
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
        ]);

        // For testing, we can inject a custom HTTP client
        if ($httpClient) {
            $this->openAIClient = OpenAI::factory()
                ->withApiKey($this->getApiKey())
                ->withHttpClient($httpClient)
                ->make();
        } else {
            $this->openAIClient = OpenAI::client($this->getApiKey());
        }
    }

    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse
    {
        // Route to appropriate generation method based on content type
        $contentType = $request->getModel()->getContentType();
        
        return match ($contentType) {
            'text' => $this->generateText($request),
            'image' => $this->generateImage($request),
            'audio' => $this->isSpeechToTextModel($request->getModel()->value)
                ? $this->audioToText($request)
                : $this->generateAudio($request),
            'embeddings' => $this->generateEmbeddings($request),
            default => throw new \InvalidArgumentException("Unsupported content type: {$contentType}")
        };
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        return $this->generateTextStream($request);
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        // Check if the prompt is empty
        if (empty($request->getPrompt()) && !$this->isSpeechToTextModel($request->getModel()->value)) {
            throw new AIEngineException('Prompt is required');
        }

        // Check if the model is supported by this engine
        $supportedModels = [
            EntityEnum::GPT_4O,
            EntityEnum::GPT_4O_MINI,
            EntityEnum::GPT_3_5_TURBO,
            EntityEnum::GPT_IMAGE_1_5,
            EntityEnum::GPT_IMAGE_1,
            EntityEnum::GPT_IMAGE_1_MINI,
            EntityEnum::DALL_E_3,
            EntityEnum::DALL_E_2,
            EntityEnum::WHISPER_1,
            EntityEnum::OPENAI_GPT_4O_TRANSCRIBE,
            EntityEnum::OPENAI_GPT_4O_MINI_TRANSCRIBE,
            EntityEnum::OPENAI_GPT_4O_TRANSCRIBE_DIARIZE,
            EntityEnum::OPENAI_GPT_4O_MINI_TTS,
            EntityEnum::OPENAI_TTS_1,
            EntityEnum::OPENAI_TTS_1_HD,
        ];

        if (!in_array($request->getModel()->value, $supportedModels)) {
            throw new AIEngineException('Unsupported model: ' . $request->getModel()->value . ' for engine: ' . $this->getEngine()->value);
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::OpenAI;
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
    public function testConnection(): bool
    {
        return $this->safeConnectionTest(
            AIRequest::create('test'),
            fn() => $this->openAIClient->models()->list()
        );
    }

    /**
     * Generate text content
     */
    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $this->logApiRequest('generateText', $request);
            
            $messages = $this->buildMessages($request);
            if ($this->shouldUseResponsesApi($request)) {
                return $this->generateTextWithResponsesApi($request, $messages);
            }

            $payload = $this->buildChatPayload($request, $messages, [
                'seed' => $request->seed,
            ]);
            
            $response = $this->openAIClient->chat()->create($payload);
            $message = $response->choices[0]->message;
            $content = $message->content ?? '';

            // Extract function call data if present
            $functionCall = null;
            if (isset($message->functionCall)) {
                $functionCall = [
                    'name' => $message->functionCall->name,
                    'arguments' => $message->functionCall->arguments,
                ];
            }

            // Create AIResponse with proper parameters
            $aiResponse = new AIResponse(
                content: $content,
                engine: EngineEnum::OpenAI,
                model: $request->getModel(),
                metadata: $response->toArray(),
                tokensUsed: $response->usage->totalTokens ?? null,
                usage: [
                    'prompt_tokens' => $response->usage->promptTokens ?? 0,
                    'completion_tokens' => $response->usage->completionTokens ?? 0,
                    'total_tokens' => $response->usage->totalTokens ?? 0,
                ],
                finishReason: $response->choices[0]->finishReason ?? null,
                functionCall: $functionCall
            );

            return $aiResponse;

        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'text generation');
        }
    }

    protected function shouldUseResponsesApi(AIRequest $request): bool
    {
        foreach ($request->getFunctions() as $function) {
            $type = is_array($function) ? (string) ($function['type'] ?? '') : '';
            if (in_array($type, [
                'web_search',
                'file_search',
                'code_interpreter',
                'computer_use',
                'mcp_server',
                'image_generation',
                'tool_search',
            ], true)) {
                return true;
            }
        }

        return (bool) (($request->getMetadata()['openai_responses_api'] ?? false) === true);
    }

    protected function generateTextWithResponsesApi(AIRequest $request, array $messages): AIResponse
    {
        $split = app(ProviderToolPayloadMapper::class)->splitForProvider(
            EngineEnum::OpenAI->value,
            $request->getFunctions()
        );

        $payload = array_filter([
            'model' => $request->getModel()->value,
            'input' => $messages,
            'tools' => $split['tools'],
            'temperature' => $request->getTemperature(),
            'max_output_tokens' => $request->getMaxTokens(),
            'metadata' => $this->openAIResponsesMetadata($request),
        ], static fn ($value): bool => $value !== null && $value !== []);

        $responseOptions = $this->openAIResponsesOptions($request);
        $previousResponseId = $this->resolvePreviousOpenAIResponseId($request, $responseOptions);
        if ($previousResponseId !== null && !isset($responseOptions['previous_response_id'])) {
            $responseOptions['previous_response_id'] = $previousResponseId;
        }

        $payload = array_replace_recursive($payload, $responseOptions);

        $toolRunResult = null;
        if ((bool) config('ai-engine.provider_tools.lifecycle.enabled', true)
            && Schema::hasTable('ai_provider_tool_runs')
            && $split['tools'] !== []) {
            $toolRunResult = app(ProviderToolRunService::class)->prepare('openai', $request, $request->getFunctions(), $payload);
            if (!$toolRunResult->canExecute()) {
                return AIResponse::success(
                    'Provider tool run requires approval before execution.',
                    $request->getEngine(),
                    $request->getModel(),
                    ['provider_tool_lifecycle' => $toolRunResult->jsonSerialize()],
                    [[
                        'type' => 'provider_tool_approval',
                        'label' => 'Approve provider tools',
                        'payload' => $toolRunResult->jsonSerialize(),
                    ]]
                );
            }
        }

        try {
            $response = $this->httpClient->post(rtrim($this->getBaseUrl(), '/') . '/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = $this->parseJsonResponse($response->getBody()->getContents());
            $content = $data['output_text'] ?? $this->extractResponsesOutputText((array) ($data['output'] ?? []));

            $metadata = [
                'openai_response' => is_array($data) ? $data : [],
                'openai_response_id' => is_string($data['id'] ?? null) ? $data['id'] : null,
                'openai_previous_response_id' => $payload['previous_response_id'] ?? null,
            ];
            $metadata = array_filter($metadata, static fn ($value): bool => $value !== null);

            if (is_string($data['id'] ?? null) && $this->shouldRememberOpenAIResponse($request, $responseOptions)) {
                $this->responseState()->remember('openai', (string) $request->getConversationId(), (string) $data['id'], [
                    'model' => $request->getModel()->value,
                    'request_id' => $data['id'],
                ]);
            }

            if ($toolRunResult !== null) {
                $run = app(ProviderToolRunService::class)->complete($toolRunResult->run, is_array($data) ? $data : []);
                $artifacts = app(HostedArtifactService::class)->recordFromProviderResponse($run, is_array($data) ? $data : [], [
                    'provider_api' => 'responses',
                ]);
                $metadata['provider_tool_lifecycle'] = $toolRunResult->jsonSerialize();
                $metadata['provider_tool_lifecycle']['run']['status'] = $run->status;
                $metadata['hosted_artifacts'] = array_map(static fn ($artifact): array => $artifact->toArray(), $artifacts);
            }

            return $this->buildSuccessResponse(
                (string) $content,
                $request,
                is_array($data) ? $data : [],
                'openai'
            )->withMetadata($metadata);
        } catch (\Throwable $e) {
            if ($toolRunResult !== null) {
                app(ProviderToolRunService::class)->fail($toolRunResult->run, $e->getMessage());
            }

            throw $e;
        }
    }

    protected function openAIResponsesOptions(AIRequest $request): array
    {
        $options = $this->providerPayloadOptions($request, 'openai');
        unset($options['remember_response'], $options['use_previous_response']);

        return $options;
    }

    protected function openAIResponsesMetadata(AIRequest $request): array
    {
        $metadata = array_diff_key($request->getMetadata(), [
            'provider_options' => true,
            'openai_responses_api' => true,
        ]);

        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '' || $value === null) {
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_scalar($value)) {
                $normalized[$key] = (string) $value;
                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                $normalized[$key] = $encoded;
            }
        }

        return $normalized;
    }

    protected function shouldRememberOpenAIResponse(AIRequest $request, array $options): bool
    {
        return (string) $request->getConversationId() !== ''
            && (bool) ($request->getProviderOptions('openai')['remember_response'] ?? $options['store'] ?? false);
    }

    protected function resolvePreviousOpenAIResponseId(AIRequest $request, array $options): ?string
    {
        if (isset($options['previous_response_id']) && is_string($options['previous_response_id']) && $options['previous_response_id'] !== '') {
            return $options['previous_response_id'];
        }

        if (!(bool) ($request->getProviderOptions('openai')['use_previous_response'] ?? false)) {
            return null;
        }

        $conversationId = $request->getConversationId();
        if (!is_string($conversationId) || $conversationId === '') {
            return null;
        }

        $state = $this->responseState()->previous('openai', $conversationId);
        $responseId = $state['response_id'] ?? null;

        return is_string($responseId) && $responseId !== '' ? $responseId : null;
    }

    protected function responseState(): \LaravelAIEngine\Services\SDK\ProviderResponseStateService
    {
        return app(\LaravelAIEngine\Services\SDK\ProviderResponseStateService::class);
    }

    protected function extractResponsesOutputText(array $output): string
    {
        $text = '';

        foreach ($output as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text') {
                    $text .= (string) ($content['text'] ?? '');
                }
            }
        }

        return $text;
    }

    /**
     * Implementation-specific streaming text generation
     */
    protected function doGenerateTextStream(AIRequest $request): \Generator
    {
        try {
            $messages = $this->buildMessages($request);
            
            // Build payload using the base method which handles model-specific parameters
            $payload = $this->buildChatPayload($request, $messages, [
                'seed' => $request->seed,
            ]);
            
            $stream = $this->openAIClient->chat()->createStreamed($payload);

            foreach ($stream as $response) {
                $content = $response->choices[0]->delta->content ?? '';
                if (!empty($content)) {
                    yield $content;
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('OpenAI streaming error: ' . $e->getMessage());
        }
    }

    /**
     * Generate images
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        try {
            $imageCount = $request->getParameters()['image_count'] ?? 1;
            $size = $request->getParameters()['size'] ?? '1024x1024';
            $model = $request->getModel()->value;
            $quality = $request->getParameters()['quality'] ?? $this->defaultImageQuality($model);

            $response = $this->openAIClient->images()->create([
                'model' => $model,
                'prompt' => $request->getPrompt(),
                'n' => $imageCount,
                'size' => $size,
                'quality' => $quality,
            ]);

            $storedImages = array_map(function ($image) use ($request) {
                $attributes = [
                    'engine' => $request->getEngine()->value,
                    'ai_model' => $request->getModel()->value,
                    'content_type' => 'image',
                    'collection_name' => 'generated-images',
                    'name' => 'openai-image',
                    'extension' => 'png',
                    'mime_type' => 'image/png',
                ];

                if (($image->url ?? '') !== '') {
                    return app(AIMediaManager::class)->storeRemoteFile($image->url, $attributes);
                }

                if (($image->b64_json ?? '') !== '') {
                    $contents = base64_decode($image->b64_json, true);
                    if ($contents === false) {
                        throw new \RuntimeException('OpenAI image response included an invalid base64 image payload.');
                    }

                    return app(AIMediaManager::class)->storeBinary(
                        $contents,
                        'openai-image-' . Str::uuid() . '.png',
                        $attributes
                    );
                }

                throw new \RuntimeException('OpenAI image response did not include a URL or base64 image payload.');
            }, $response->data);

            $imageUrls = array_values(array_filter(array_map(
                static fn (array $image): ?string => $image['url'] ?? $image['source_url'] ?? null,
                $storedImages
            )));

            return AIResponse::success(
                $request->getPrompt(),
                $request->getEngine(),
                $request->getModel()
            )->withFiles($imageUrls)
             ->withMetadata(['images' => $storedImages])
             ->withUsage(
                 creditsUsed: $imageCount * $request->getModel()->creditIndex()
             );

        } catch (\Exception $e) {
            throw new \RuntimeException('OpenAI image generation error: ' . $e->getMessage(), 0, $e);
        }
    }

    private function defaultImageQuality(string $model): string
    {
        return str_starts_with($model, 'gpt-image-') ? 'low' : 'standard';
    }

    /**
     * Image-editing operations via the OpenAI Images API. Plugs into the unified
     * ImageOperationService: variation/reimagine -> images/variations,
     * everything else (edit/inpaint/generative_fill/cleanup) -> images/edits.
     */
    public function editImage(AIRequest $request): AIResponse
    {
        $params = $request->getParameters();
        $operation = (string) ($params['operation'] ?? 'edit');
        $size = (string) ($params['size'] ?? '1024x1024');

        try {
            if (in_array($operation, ['variation', 'reimagine'], true)) {
                $response = $this->openAIClient->images()->variation(array_filter([
                    'image' => $this->imageEditResource($params['image'] ?? null, 'image'),
                    'n' => 1,
                    'size' => $size,
                    'model' => $params['model'] ?? null,
                ], static fn ($value) => $value !== null));
            } else {
                $response = $this->openAIClient->images()->edit(array_filter([
                    'image' => $this->imageEditResource($params['image'] ?? null, 'image'),
                    'mask' => isset($params['mask']) ? $this->imageEditResource($params['mask'], 'mask') : null,
                    'prompt' => (string) ($params['prompt'] ?? $request->getPrompt()),
                    'model' => $params['model'] ?? 'gpt-image-1',
                    'n' => 1,
                    'size' => $size,
                ], static fn ($value) => $value !== null));
            }

            $storedImages = array_map(fn ($image) => $this->storeEditedImage($image, $request), $response->data);
            $imageUrls = array_values(array_filter(array_map(
                static fn (array $image): ?string => $image['url'] ?? $image['source_url'] ?? null,
                $storedImages
            )));

            return AIResponse::success($request->getPrompt(), $request->getEngine(), $request->getModel())
                ->withFiles($imageUrls)
                ->withMetadata(['operation' => $operation, 'images' => $storedImages])
                ->withUsage(creditsUsed: $request->getModel()->creditIndex());
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenAI image edit error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return resource
     */
    private function imageEditResource(mixed $value, string $field)
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("OpenAI image edit requires a '{$field}' image.");
        }

        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            $value = $comma !== false ? substr($value, $comma + 1) : $value;
        }

        if (strlen($value) < 1024 && @is_file($value)) {
            $contents = (string) file_get_contents($value);
        } else {
            $decoded = base64_decode($value, true);
            $contents = ($decoded !== false && base64_encode($decoded) === $value) ? $decoded : $value;
        }

        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    private function storeEditedImage(object $image, AIRequest $request): array
    {
        $attributes = [
            'engine' => $request->getEngine()->value,
            'ai_model' => $request->getModel()->value,
            'content_type' => 'image',
            'collection_name' => 'edited-images',
            'name' => 'openai-image-edit',
            'extension' => 'png',
            'mime_type' => 'image/png',
        ];

        if (($image->url ?? '') !== '') {
            return app(AIMediaManager::class)->storeRemoteFile($image->url, $attributes);
        }

        if (($image->b64_json ?? '') !== '') {
            $contents = base64_decode($image->b64_json, true);
            if ($contents === false) {
                throw new \RuntimeException('OpenAI image edit response included an invalid base64 payload.');
            }

            return app(AIMediaManager::class)->storeBinary($contents, 'openai-edit-' . Str::uuid() . '.png', $attributes);
        }

        throw new \RuntimeException('OpenAI image edit response did not include a URL or base64 payload.');
    }

    /**
     * Implementation-specific text to speech generation.
     */
    protected function doGenerateAudio(AIRequest $request): AIResponse
    {
        try {
            $parameters = $request->getParameters();
            $format = (string) ($parameters['response_format'] ?? $parameters['format'] ?? 'mp3');
            $voice = (string) ($parameters['voice'] ?? $this->config['default_voice'] ?? 'alloy');

            $payload = array_filter([
                'model' => $request->getModel()->value,
                'input' => $request->getPrompt(),
                'voice' => $voice,
                'response_format' => $format,
                'speed' => $parameters['speed'] ?? null,
                'instructions' => $parameters['instructions'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');

            $audioData = $this->openAIClient->audio()->speech($payload);
            $file = $this->storeMediaBytes((string) $audioData, $request, $this->audioExtensionFromFormat($format));
            $charactersUsed = strlen($request->getPrompt());

            return AIResponse::success(
                $request->getPrompt(),
                $request->getEngine(),
                $request->getModel(),
                [
                    'provider' => EngineEnum::OpenAI->value,
                    'model' => $request->getModel()->value,
                    'voice' => $voice,
                    'response_format' => $format,
                ]
            )->withFiles([$file])
             ->withUsage(creditsUsed: max(1, $charactersUsed / 1000) * $request->getModel()->creditIndex());
        } catch (\Exception $e) {
            throw new \RuntimeException('OpenAI speech generation error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Implementation-specific audio to text
     */
    protected function doAudioToText(AIRequest $request): AIResponse
    {
        try {
            $audioFile = $request->getFiles()[0] ?? null;
            if (!$audioFile) {
                throw new \InvalidArgumentException('Audio file is required');
            }

            $parameters = $request->getParameters();
            $model = $this->isSpeechToTextModel($request->getModel()->value)
                ? $request->getModel()->value
                : (string) ($parameters['transcription_model'] ?? EntityEnum::OPENAI_GPT_4O_TRANSCRIBE);

            $payload = array_filter([
                'model' => $model,
                'file' => fopen($audioFile, 'r'),
                'response_format' => $parameters['response_format'] ?? 'json',
                'language' => $parameters['language'] ?? null,
                'prompt' => $parameters['transcription_prompt'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');

            $response = $this->openAIClient->audio()->transcribe($payload);

            $duration = $parameters['audio_minutes'] ?? 1.0;

            return AIResponse::success(
                $response->text,
                $request->getEngine(),
                $request->getModel(),
                [
                    'provider' => EngineEnum::OpenAI->value,
                    'service' => 'speech_to_text',
                    'model' => $model,
                ]
            )->withUsage(
                creditsUsed: $duration * $request->getModel()->creditIndex()
            );

        } catch (\Exception $e) {
            throw new \RuntimeException('OpenAI audio transcription error: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function doSpeechToSpeech(AIRequest $request): AIResponse
    {
        $parameters = $request->getParameters();
        $transcriptionModel = EntityEnum::from((string) ($parameters['transcription_model'] ?? EntityEnum::OPENAI_GPT_4O_TRANSCRIBE));
        $ttsModel = $this->isSpeechToTextModel($request->getModel()->value)
            ? EntityEnum::from((string) ($parameters['tts_model'] ?? EntityEnum::OPENAI_GPT_4O_MINI_TTS))
            : $request->getModel();

        $transcriptionRequest = new AIRequest(
            prompt: '',
            engine: $request->getEngine(),
            model: $transcriptionModel,
            parameters: $parameters,
            userId: $request->getUserId(),
            conversationId: $request->getConversationId(),
            context: $request->getContext(),
            files: $request->getFiles(),
            systemPrompt: $request->getSystemPrompt(),
            messages: $request->getMessages(),
            maxTokens: $request->getMaxTokens(),
            temperature: $request->getTemperature(),
            seed: $request->getSeed(),
            metadata: $request->getMetadata()
        );

        $transcription = $this->audioToText($transcriptionRequest);

        if (!$transcription->isSuccessful()) {
            return $transcription;
        }

        $speechRequest = new AIRequest(
            prompt: $transcription->getContent(),
            engine: $request->getEngine(),
            model: $ttsModel,
            parameters: $parameters,
            userId: $request->getUserId(),
            conversationId: $request->getConversationId(),
            context: $request->getContext(),
            systemPrompt: $request->getSystemPrompt(),
            messages: $request->getMessages(),
            maxTokens: $request->getMaxTokens(),
            temperature: $request->getTemperature(),
            seed: $request->getSeed(),
            metadata: $request->getMetadata()
        );

        $response = $this->generateAudio($speechRequest);

        return $response->withMetadata([
            'provider' => EngineEnum::OpenAI->value,
            'service' => 'speech_to_speech',
            'transcript' => $transcription->getContent(),
            'transcription_model' => $transcriptionModel->value,
            'tts_model' => $ttsModel->value,
        ]);
    }

    protected function isSpeechToTextModel(string $model): bool
    {
        $model = strtolower($model);

        return str_contains($model, 'whisper') || str_contains($model, 'transcribe');
    }

    protected function audioExtensionFromFormat(string $format): string
    {
        return match (strtolower($format)) {
            'opus' => 'opus',
            'aac' => 'aac',
            'flac' => 'flac',
            'wav' => 'wav',
            'pcm' => 'pcm',
            default => 'mp3',
        };
    }

    /**
     * Implementation-specific embeddings generation
     */
    protected function doGenerateEmbeddings(AIRequest $request): AIResponse
    {
        try {
            $response = $this->openAIClient->embeddings()->create([
                'model' => $request->getModel()->value,
                'input' => $request->getPrompt(),
            ]);

            $embeddings = $response->embeddings[0]->embedding;
            $tokensUsed = $response->usage->totalTokens ?? $this->calculateTokensUsed($request->getPrompt());

            return AIResponse::success(
                json_encode($embeddings),
                $request->getEngine(),
                $request->getModel()
            )->withUsage(
                tokensUsed: $tokensUsed,
                creditsUsed: $tokensUsed * $request->getModel()->creditIndex()
            )->withDetailedUsage([
                'embeddings' => $embeddings,
                'dimensions' => count($embeddings),
            ]);

        } catch (\Exception $e) {
            throw new \RuntimeException('OpenAI embeddings error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->openAIClient->models()->list();
            
            return array_map(function ($model) {
                return [
                    'id' => $model->id,
                    'object' => $model->object,
                    'created' => $model->created,
                    'owned_by' => $model->ownedBy,
                ];
            }, $response->data);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'images', 'image_edit', 'audio', 'embeddings', 'vision', 'streaming', 'speech_to_text', 'text_to_speech', 'speech_to_speech', 'tts', 'sts'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::OpenAI;
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::from(EntityEnum::GPT_4O_MINI);
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new AIEngineException('OpenAI API key is required');
        }
    }

    /**
     * Build messages array for chat completion
     */
    private function buildMessages(AIRequest $request): array
    {
        // Use centralized method from BaseEngineDriver
        return $this->buildStandardMessages($request);
    }
    
    /**
     * Generate JSON analysis using the best approach for the given model
     * Automatically selects between standard chat and JSON mode based on model type
     * 
     * @param string $prompt The analysis prompt
     * @param string $systemPrompt System instructions
     * @param string|null $model Model to use (null = use config default)
     * @param int $maxTokens Maximum tokens for response
     * @return string JSON response content
     */
    public function generateJsonAnalysis(
        string $prompt,
        string $systemPrompt,
        ?string $model = null,
        int $maxTokens = 300
    ): string {
        $model = $model ?? config('ai-engine.engines.openai.model', 'gpt-4o');
        
        try {
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ];
            
            // GPT-5 and reasoning models: use JSON mode + appropriate parameters
            if ($this->isGpt5FamilyModel($model)) {
                $payload['response_format'] = ['type' => 'json_object'];
                // GPT-5 needs more tokens for reasoning before output
                $payload['max_completion_tokens'] = max($maxTokens, 1000);
                // GPT-5 with json_object format works better with low reasoning for fast analysis
                $payload['reasoning_effort'] = 'low';
            } elseif ($this->isReasoningModel($model)) {
                $payload['response_format'] = ['type' => 'json_object'];
                $payload['max_completion_tokens'] = $maxTokens;
                $payload['temperature'] = 1;
            } else {
                // Standard models (GPT-4o, etc.): JSON mode + standard params
                $payload['response_format'] = ['type' => 'json_object'];
                $payload['max_tokens'] = $maxTokens;
                $payload['temperature'] = 0.3;
            }
            
            \Log::channel('ai-engine')->debug('JSON analysis request', [
                'model' => $model,
                'prompt_length' => strlen($prompt),
                'is_gpt5' => $this->isGpt5FamilyModel($model),
            ]);
            
            $response = $this->openAIClient->chat()->create($payload);
            
            // Debug: log full response structure for GPT-5 models
            if ($this->isGpt5FamilyModel($model)) {
                \Log::channel('ai-engine')->debug('GPT-5 raw response', [
                    'model' => $model,
                    'choices_count' => count($response->choices ?? []),
                    'first_choice' => isset($response->choices[0]) ? [
                        'finish_reason' => $response->choices[0]->finishReason ?? null,
                        'message_role' => $response->choices[0]->message->role ?? null,
                        'message_content' => substr($response->choices[0]->message->content ?? '', 0, 200),
                    ] : null,
                ]);
            }
            
            $content = $response->choices[0]->message->content ?? '';
            
            \Log::channel('ai-engine')->debug('JSON analysis response', [
                'model' => $model,
                'content_length' => strlen($content),
                'has_content' => !empty(trim($content)),
            ]);
            
            return trim($content);
            
        } catch (\Exception $e) {
            \Log::channel('ai-engine')->error('JSON analysis failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            
            throw new \RuntimeException('JSON analysis error: ' . $e->getMessage(), 0, $e);
        }
    }
}
