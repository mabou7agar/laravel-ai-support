<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\OpenRouter;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper;

class OpenRouterEngineDriver extends BaseEngineDriver
{
    use BuildsMediaResponses;

    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->baseUrl = rtrim((string) ($config['base_url'] ?? config('ai-engine.engines.openrouter.base_url', $this->baseUrl)), '/');
    }

    /**
     * Get the API key for OpenRouter
     */
    protected function getApiKey(): string
    {
        $apiKey = $this->config['api_key']
            ?? config('ai-engine.engines.openrouter.api_key');

        if (is_string($apiKey) && trim($apiKey) !== '') {
            return $apiKey;
        }

        throw new \InvalidArgumentException('OpenRouter API key not configured');
    }

    /**
     * Get the headers for OpenRouter API requests
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('ai-engine.engines.openrouter.site_url', config('app.url')),
            'X-Title' => config('ai-engine.engines.openrouter.site_name', config('app.name')),
        ];
    }

    /**
     * Generate content using OpenRouter
     */
    public function generate(AIRequest $request): AIResponse
    {
        return match ($request->getModel()->getContentType()) {
            'image' => $this->generateImage($request),
            'audio' => $this->isSpeechToTextModel($request->getModel()->value)
                ? $this->audioToText($request)
                : $this->generateAudio($request),
            'embeddings' => $this->generateEmbeddings($request),
            default => $this->shouldGenerateImage($request)
                ? $this->generateImage($request)
                : $this->generateText($request),
        };
    }

    /**
     * Generate text content via OpenRouter API
     */
    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $this->logApiRequest('generateText', $request);

            $messages = $this->buildMessages($request);
            $payload = $this->buildChatCompletionPayload($request, $messages);
            $response = $this->postJson('/chat/completions', $payload);

            if (!$response->successful()) {
                $error = $response->json()['error']['message'] ?? $response->body();
                return AIResponse::error($error, $request->getEngine(), $request->getModel());
            }

            $data = $response->json();
            $message = $data['choices'][0]['message'] ?? [];
            $content = $this->stringifyContent($message['content'] ?? '');
            $files = $this->extractMessageImageFiles((array) ($message['images'] ?? []), $request);
            $toolCalls = $this->extractToolCalls($message);
            $functionCall = $this->firstFunctionCall($toolCalls, $message);

            $aiResponse = AIResponse::success(
                $content,
                $request->getEngine(),
                $request->getModel(),
                [
                    'model' => $data['model'] ?? $request->getModel()->value,
                    'usage' => $data['usage'] ?? [],
                    'openrouter_id' => $data['id'] ?? null,
                    'provider' => $data['provider'] ?? null,
                    'tool_calls' => $toolCalls,
                ]
            )->withFunctionCall($functionCall);

            return $files === [] ? $aiResponse : $aiResponse->withFiles($files);

        } catch (\Exception $e) {
            return AIResponse::error($e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    public function generateImage(AIRequest $request): AIResponse
    {
        try {
            $parameters = $request->getParameters();
            $payload = $this->buildChatCompletionPayload($request, $this->buildMessages($request));
            $payload['modalities'] = array_values((array) ($parameters['modalities'] ?? ['image', 'text']));
            $payload['stream'] = false;

            if (isset($parameters['image_config']) && is_array($parameters['image_config'])) {
                $payload['image_config'] = $parameters['image_config'];
            } else {
                $payload['image_config'] = array_filter([
                    'aspect_ratio' => $parameters['aspect_ratio'] ?? null,
                    'image_size' => $parameters['image_size'] ?? $parameters['size'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== '');
            }

            if ($payload['image_config'] === []) {
                unset($payload['image_config']);
            }

            $response = $this->postJson('/chat/completions', $payload);

            if (!$response->successful()) {
                return AIResponse::error(
                    $response->json()['error']['message'] ?? $response->body(),
                    $request->getEngine(),
                    $request->getModel()
                );
            }

            $data = $response->json();
            $message = $data['choices'][0]['message'] ?? [];
            $files = $this->extractMessageImageFiles((array) ($message['images'] ?? []), $request);

            return AIResponse::success(
                $this->stringifyContent($message['content'] ?? ''),
                $request->getEngine(),
                $request->getModel(),
                [
                    'provider' => EngineEnum::OpenRouter->value,
                    'model' => $data['model'] ?? $request->getModel()->value,
                    'openrouter_id' => $data['id'] ?? null,
                    'usage' => $data['usage'] ?? [],
                    'image_count' => count($files),
                ]
            )->withFiles($files)
             ->withUsage(creditsUsed: max(1, count($files)) * $request->getModel()->creditIndex());
        } catch (\Exception $e) {
            return AIResponse::error($e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        try {
            if ($this->shouldUseChatAudioOutput($request)) {
                return $this->generateChatAudioOutput($request);
            }

            $parameters = $request->getParameters();
            $format = (string) ($parameters['response_format'] ?? $parameters['format'] ?? 'mp3');
            $payload = array_filter([
                'model' => $request->getModel()->value,
                'input' => $request->getPrompt(),
                'voice' => $parameters['voice'] ?? 'alloy',
                'response_format' => $format,
                'speed' => $parameters['speed'] ?? null,
                'provider' => $parameters['provider'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');

            $response = $this->postJson('/audio/speech', $payload);

            if (!$response->successful()) {
                return AIResponse::error(
                    $response->json()['error']['message'] ?? $response->body(),
                    $request->getEngine(),
                    $request->getModel()
                );
            }

            $file = $this->storeMediaBytes(
                $response->body(),
                $request,
                $this->extensionFromContentType($response->header('Content-Type', ''), $this->audioExtensionFromFormat($format))
            );

            return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                'provider' => EngineEnum::OpenRouter->value,
                'service' => 'text_to_speech',
                'model' => $request->getModel()->value,
                'voice' => $payload['voice'],
                'response_format' => $format,
                'generation_id' => $response->header('X-Generation-Id'),
            ])->withFiles([$file])
              ->withUsage(creditsUsed: max(1, strlen($request->getPrompt()) / 1000) * $request->getModel()->creditIndex());
        } catch (\Exception $e) {
            return AIResponse::error($e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    protected function generateChatAudioOutput(AIRequest $request): AIResponse
    {
        $parameters = $request->getParameters();
        $audio = (array) ($parameters['audio'] ?? []);
        $format = (string) ($audio['format'] ?? $parameters['response_format'] ?? $parameters['format'] ?? 'wav');

        $payload = $this->buildChatCompletionPayload($request, $this->buildMessages($request));
        $payload['modalities'] = array_values((array) ($parameters['modalities'] ?? ['text', 'audio']));
        $payload['audio'] = array_filter([
            'voice' => $audio['voice'] ?? $parameters['voice'] ?? 'alloy',
            'format' => $format,
        ], static fn ($value): bool => $value !== null && $value !== '');
        $payload['stream'] = true;

        $response = $this->postJson('/chat/completions', $payload, ['stream' => true]);
        if (!$response->successful()) {
            return AIResponse::error(
                $response->json()['error']['message'] ?? $response->body(),
                $request->getEngine(),
                $request->getModel()
            );
        }

        $audioStream = $this->consumeChatAudioStream($response);
        if ($audioStream['bytes'] === '') {
            return AIResponse::error(
                'OpenRouter chat audio stream did not include audio data.',
                $request->getEngine(),
                $request->getModel()
            );
        }

        $extension = $this->audioExtensionFromFormat($format);
        $file = $this->storeMediaBytes($audioStream['bytes'], $request, $extension);

        return AIResponse::success($audioStream['transcript'], $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::OpenRouter->value,
            'service' => 'chat_audio_output',
            'model' => $request->getModel()->value,
            'voice' => $payload['audio']['voice'] ?? null,
            'response_format' => $format,
            'mime_type' => $this->audioMimeTypeFromFormat($format),
        ])->withFiles([$file])
          ->withUsage(creditsUsed: max(1, strlen($request->getPrompt()) / 1000) * $request->getModel()->creditIndex());
    }

    protected function doAudioToText(AIRequest $request): AIResponse
    {
        try {
            $audioFile = $request->getFiles()[0] ?? null;
            if (!is_string($audioFile) || !is_readable($audioFile)) {
                throw new \InvalidArgumentException('Readable audio file is required for OpenRouter speech-to-text');
            }

            $parameters = $request->getParameters();
            $format = (string) ($parameters['format'] ?? pathinfo($audioFile, PATHINFO_EXTENSION) ?: 'wav');
            $payload = array_filter([
                'model' => $request->getModel()->value,
                'input_audio' => [
                    'data' => base64_encode((string) file_get_contents($audioFile)),
                    'format' => $format,
                ],
                'language' => $parameters['language'] ?? null,
                'temperature' => $parameters['temperature'] ?? $request->getTemperature(),
                'provider' => $parameters['provider'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');

            $response = $this->postJson('/audio/transcriptions', $payload);

            if (!$response->successful()) {
                return AIResponse::error(
                    $response->json()['error']['message'] ?? $response->body(),
                    $request->getEngine(),
                    $request->getModel()
                );
            }

            $data = $response->json();

            return AIResponse::success((string) ($data['text'] ?? ''), $request->getEngine(), $request->getModel(), [
                'provider' => EngineEnum::OpenRouter->value,
                'service' => 'speech_to_text',
                'model' => $request->getModel()->value,
                'language' => $data['language'] ?? $parameters['language'] ?? null,
                'usage' => $data['usage'] ?? [],
                'raw' => $data,
            ])->withUsage(creditsUsed: max(1, (float) ($parameters['audio_minutes'] ?? 1.0)) * $request->getModel()->creditIndex());
        } catch (\Exception $e) {
            return AIResponse::error($e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    protected function doSpeechToSpeech(AIRequest $request): AIResponse
    {
        $parameters = $request->getParameters();
        $transcriptionModel = EntityEnum::from((string) ($parameters['transcription_model'] ?? $request->getModel()->value));
        $ttsModel = EntityEnum::from((string) ($parameters['tts_model'] ?? $request->getModel()->value));

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
            'provider' => EngineEnum::OpenRouter->value,
            'service' => 'speech_to_speech',
            'transcript' => $transcription->getContent(),
            'transcription_model' => $transcriptionModel->value,
            'tts_model' => $ttsModel->value,
        ]);
    }

    public function generateEmbeddings(AIRequest $request): AIResponse
    {
        try {
            $parameters = $request->getParameters();
            $payload = array_filter([
                'model' => $request->getModel()->value,
                'input' => $parameters['input'] ?? $request->getPrompt(),
                'dimensions' => $parameters['dimensions'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');

            $response = $this->postJson('/embeddings', $payload);

            if (!$response->successful()) {
                return AIResponse::error(
                    $response->json()['error']['message'] ?? $response->body(),
                    $request->getEngine(),
                    $request->getModel()
                );
            }

            $data = $response->json();
            $embeddings = array_map(
                static fn (array $item): array => (array) ($item['embedding'] ?? []),
                (array) ($data['data'] ?? [])
            );

            return AIResponse::success(json_encode($embeddings, JSON_THROW_ON_ERROR), $request->getEngine(), $request->getModel(), [
                'provider' => EngineEnum::OpenRouter->value,
                'service' => 'embeddings',
                'model' => $data['model'] ?? $request->getModel()->value,
                'usage' => $data['usage'] ?? [],
                'embeddings' => $embeddings,
                'dimensions' => count($embeddings[0] ?? []),
            ])->withUsage(
                tokensUsed: $data['usage']['total_tokens'] ?? null,
                creditsUsed: max(1, count($embeddings)) * $request->getModel()->creditIndex()
            );
        } catch (\Exception $e) {
            return AIResponse::error($e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    /**
     * Build messages array for chat completion
     */
    protected function buildMessages(AIRequest $request): array
    {
        $messages = [];

        $systemPrompt = $request->getSystemPrompt();
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Add conversation history if present (stored in messages)
        $existingMessages = $request->getMessages();
        if (!empty($existingMessages)) {
            foreach ($existingMessages as $msg) {
                $messages[] = [
                    'role' => $msg['role'] ?? 'user',
                    'content' => $msg['content'] ?? '',
                ];
            }
        }

        // Add the current prompt
        $messages[] = [
            'role' => 'user',
            'content' => $this->buildCurrentMessageContent($request),
        ];

        return $messages;
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        $payload = $this->buildChatCompletionPayload($request, $this->buildMessages($request));
        $payload['stream'] = true;

        $response = $this->postJson('/chat/completions', $payload, ['stream' => true]);
        if (!$response->successful()) {
            throw new \RuntimeException($response->json()['error']['message'] ?? $response->body());
        }

        yield from $this->parseStreamingResponse($response);
    }

    /**
     * Validate the request
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($request->getPrompt()) && $request->getFiles() === []) {
            throw new \InvalidArgumentException('Prompt is required');
        }
        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::OpenRouter;
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities(), true);
    }

    /**
     * Test the engine connection
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(10)
                ->get($this->baseUrl . '/models');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the engine name for logging
     */
    public function getEngineName(): string
    {
        return 'openrouter';
    }

    /**
     * Check if the model is supported by OpenRouter
     */
    public function supportsModel(string $model): bool
    {
        return str_contains($model, '/');
    }

    /**
     * Get available models from OpenRouter
     */
    public function getAvailableModels(): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(10)
                ->get($this->baseUrl . '/models');

            if ($response->successful()) {
                $data = $response->json();
                return collect($data['data'] ?? [])
                    ->map(fn (array $model): array => $this->normalizeModel($model))
                    ->toArray();
            }
        } catch (\Exception $e) {
            // Fallback to configured models
        }

        return collect(config('ai-engine.engines.openrouter.models', []))
            ->map(fn (mixed $model, string $id): array => is_array($model)
                ? array_merge(['id' => $id], $model)
                : ['id' => $id, 'name' => (string) $model])
            ->values()
            ->toArray();
    }

    /**
     * Get supported capabilities
     */
    protected function getSupportedCapabilities(): array
    {
        return [
            'text',
            'chat',
            'streaming',
            'vision',
            'image',
            'images',
            'image_generation',
            'audio',
            'speech_to_text',
            'text_to_speech',
            'speech_to_speech',
            'tts',
            'sts',
            'embeddings',
        ];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::OpenRouter;
    }

    /**
     * Get the default model
     */
    protected function getDefaultModel(): EntityEnum
    {
        $model = config('ai-engine.engines.openrouter.default_model', 'meta-llama/llama-3.1-8b-instruct:free');
        return EntityEnum::from($model);
    }

    /**
     * Get the base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Validate the configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->getApiKey())) {
            throw new \InvalidArgumentException('OpenRouter API key is required');
        }
    }

    protected function buildChatCompletionPayload(AIRequest $request, array $messages): array
    {
        $parameters = $request->getParameters();
        $payload = [
            'model' => $request->getModel()->value,
            'messages' => $messages,
            'max_tokens' => $request->getMaxTokens() ?? $parameters['max_tokens'] ?? 4096,
            'temperature' => $request->getTemperature() ?? $parameters['temperature'] ?? 0.7,
        ];

        foreach ([
            'models',
            'max_completion_tokens',
            'frequency_penalty',
            'presence_penalty',
            'top_p',
            'top_k',
            'seed',
            'stop',
            'response_format',
            'provider',
            'reasoning',
            'modalities',
            'audio',
            'image_config',
            'plugins',
            'parallel_tool_calls',
            'web_search_options',
            'metadata',
            'cache_control',
            'debug',
            'trace_id',
            'trace_name',
            'span_name',
            'generation_name',
            'parent_span_id',
        ] as $field) {
            if (array_key_exists($field, $parameters) && $parameters[$field] !== null && $parameters[$field] !== '') {
                $payload[$field] = $parameters[$field];
            }
        }

        if ($transforms = $parameters['transforms'] ?? config('ai-engine.engines.openrouter.transforms')) {
            $payload['transforms'] = $transforms;
        }

        if ($route = $parameters['route'] ?? config('ai-engine.engines.openrouter.route')) {
            $payload['route'] = $route;
        }

        $this->applyCostOptimization($payload, $request);
        $this->applyToolPayload($payload, $request);
        $this->applyStructuredOutput($payload, $request);

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    protected function applyCostOptimization(array &$payload, AIRequest $request): void
    {
        $parameters = $request->getParameters();
        $config = (array) config('ai-engine.engines.openrouter.cost_optimization', []);
        $setting = $parameters['cost_optimization'] ?? $config['enabled'] ?? false;

        if (!$this->costOptimizationEnabled($setting)) {
            return;
        }

        $mode = is_string($setting) && $setting !== '1'
            ? $setting
            : (string) ($parameters['cost_optimization_mode'] ?? $config['mode'] ?? 'free_first');

        $models = $this->costOptimizedModels($payload, $request, $config, $mode);
        if ($models !== []) {
            $payload['models'] = $models;
            unset($payload['model']);
        }

        $provider = (array) ($payload['provider'] ?? []);
        $sortByPrice = (bool) ($parameters['sort_by_price'] ?? $config['sort_by_price'] ?? true);
        if ($sortByPrice && !isset($provider['order'])) {
            $provider['sort'] = array_replace([
                'by' => 'price',
                'partition' => 'none',
            ], (array) ($provider['sort'] ?? []));
        }

        $latency = $parameters['preferred_max_latency_p90']
            ?? $config['preferred_max_latency_p90']
            ?? null;
        if ($latency !== null && $latency !== '') {
            $provider['preferred_max_latency'] = array_replace(
                (array) ($provider['preferred_max_latency'] ?? []),
                ['p90' => is_numeric($latency) ? (float) $latency : $latency]
            );
        }

        $maxPrice = $parameters['max_price'] ?? $config['max_price'] ?? null;
        if (is_array($maxPrice)) {
            $maxPrice = array_filter($maxPrice, static fn ($value): bool => $value !== null && $value !== '');
            if ($maxPrice !== []) {
                $provider['max_price'] = $maxPrice;
            }
        }

        if ($provider !== []) {
            $payload['provider'] = $provider;
        }
    }

    protected function costOptimizationEnabled(mixed $setting): bool
    {
        if (is_bool($setting)) {
            return $setting;
        }

        if (is_string($setting)) {
            return in_array(strtolower(trim($setting)), ['1', 'true', 'yes', 'on', 'free_first', 'cheapest'], true);
        }

        return (bool) $setting;
    }

    protected function costOptimizedModels(array $payload, AIRequest $request, array $config, string $mode): array
    {
        $configuredModels = array_values(array_filter((array) ($payload['models'] ?? []), 'is_string'));
        if ($configuredModels !== []) {
            return $this->dedupeModels($configuredModels);
        }

        $requestedModel = (string) ($payload['model'] ?? $request->getModel()->value);
        $includeRequested = (bool) ($request->getParameters()['include_requested_model_fallback']
            ?? $config['include_requested_model_fallback']
            ?? true);

        $models = [];
        if (in_array($mode, ['free_first', 'cheapest'], true)) {
            $models = array_merge($models, $this->freeOpenRouterModels($config));
        }

        if ($includeRequested && $requestedModel !== '') {
            $models[] = $requestedModel;
        }

        return $this->dedupeModels($models);
    }

    protected function freeOpenRouterModels(array $config): array
    {
        $models = array_values(array_filter((array) ($config['free_models'] ?? []), 'is_string'));

        try {
            $catalogModels = AIModel::active()
                ->where('provider', EngineEnum::OpenRouter->value)
                ->where('model_id', 'like', '%:free')
                ->pluck('model_id')
                ->all();

            $models = array_merge($models, array_values(array_filter($catalogModels, 'is_string')));
        } catch (\Throwable) {
            // Database may not be migrated in lightweight installs.
        }

        return $this->dedupeModels($models);
    }

    protected function dedupeModels(array $models): array
    {
        $deduped = [];
        foreach ($models as $model) {
            if (!is_string($model) || trim($model) === '') {
                continue;
            }

            $deduped[] = trim($model);
        }

        return array_values(array_unique($deduped));
    }

    protected function applyToolPayload(array &$payload, AIRequest $request): void
    {
        if ($request->getFunctions() === []) {
            return;
        }

        $split = app(ProviderToolPayloadMapper::class)->splitForProvider(
            EngineEnum::OpenRouter->value,
            $request->getFunctions()
        );

        if (!empty($split['functions'])) {
            $payload['tools'] = array_merge($payload['tools'] ?? [], array_map(
                static fn (array $function): array => isset($function['type'])
                    ? $function
                    : ['type' => 'function', 'function' => $function],
                $split['functions']
            ));
        }

        if (!empty($split['tools'])) {
            $payload['tools'] = array_merge($payload['tools'] ?? [], $split['tools']);
        }

        if ($request->getFunctionCall() !== null) {
            $payload['tool_choice'] = $request->getFunctionCall();
        }
    }

    protected function applyStructuredOutput(array &$payload, AIRequest $request): void
    {
        if (isset($payload['response_format'])) {
            return;
        }

        $definition = $request->getMetadata()['structured_output'] ?? null;
        if (!is_array($definition) || !is_array($definition['schema'] ?? null)) {
            return;
        }

        $payload['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => (string) ($definition['name'] ?? 'response'),
                'schema' => $definition['schema'],
                'strict' => (bool) ($definition['strict'] ?? true),
            ],
        ];
    }

    protected function buildCurrentMessageContent(AIRequest $request): string|array
    {
        $files = $request->getFiles();
        if ($files === []) {
            return $request->getPrompt();
        }

        $parts = [];
        if ($request->getPrompt() !== '') {
            $parts[] = ['type' => 'text', 'text' => $request->getPrompt()];
        }

        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }

            $mime = $this->detectMimeType($file);
            if (str_starts_with($mime, 'audio/')) {
                $parts[] = [
                    'type' => 'input_audio',
                    'input_audio' => [
                        'data' => $this->fileToBase64($file),
                        'format' => $this->formatFromPath($file, $mime),
                    ],
                ];
                continue;
            }

            $parts[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $this->imageReference($file, $mime),
                ],
            ];
        }

        return $parts === [] ? $request->getPrompt() : $parts;
    }

    protected function extractMessageImageFiles(array $images, AIRequest $request): array
    {
        $files = [];
        foreach ($images as $image) {
            $url = $image['image_url']['url'] ?? $image['imageUrl']['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }

            $files[] = $this->storeDataUrlOrReturn($url, $request);
        }

        return array_values(array_unique($files));
    }

    protected function storeDataUrlOrReturn(string $url, AIRequest $request): string
    {
        if (preg_match('/^data:([^;]+);base64,(.+)$/', $url, $matches) !== 1) {
            return $url;
        }

        $bytes = base64_decode($matches[2], true);
        if ($bytes === false) {
            return $url;
        }

        return $this->storeMediaBytes($bytes, $request, $this->extensionFromContentType($matches[1], 'png'));
    }

    protected function stringifyContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $text .= $part;
                continue;
            }

            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    protected function postJson(string $path, array $payload, array $options = []): Response
    {
        return Http::withHeaders($this->getHeaders())
            ->withOptions($options)
            ->timeout((int) ($this->config['timeout'] ?? config('ai-engine.engines.openrouter.timeout', 60)))
            ->post($this->baseUrl . $path, $payload);
    }

    protected function parseStreamingResponse(Response $response): \Generator
    {
        $buffer = '';
        $body = $response->toPsrResponse()->getBody();

        while (!$body->eof()) {
            $buffer .= $body->read(8192);

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    return;
                }

                $data = json_decode($payload, true);
                if (!is_array($data)) {
                    continue;
                }

                $delta = $data['choices'][0]['delta'] ?? [];
                $content = $this->stringifyContent($delta['content'] ?? '');
                if ($content !== '') {
                    yield $content;
                }

                $audio = $delta['audio'] ?? null;
                if (is_array($audio) && is_string($audio['transcript'] ?? null) && $audio['transcript'] !== '') {
                    yield $audio['transcript'];
                }
            }
        }

        if (trim($buffer) !== '') {
            $line = trim($buffer);
            if (str_starts_with($line, 'data:')) {
                $payload = trim(substr($line, 5));
                if ($payload !== '[DONE]') {
                    $data = json_decode($payload, true);
                    $content = $this->stringifyContent($data['choices'][0]['delta']['content'] ?? '');
                    if ($content !== '') {
                        yield $content;
                    }
                }
            }
        }
    }

    /**
     * @return array{transcript:string,bytes:string}
     */
    protected function consumeChatAudioStream(Response $response): array
    {
        $buffer = '';
        $transcript = '';
        $audioChunks = [];
        $body = $response->toPsrResponse()->getBody();

        while (!$body->eof()) {
            $buffer .= $body->read(8192);

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    return [
                        'transcript' => $transcript,
                        'bytes' => $this->decodeAudioChunks($audioChunks),
                    ];
                }

                $data = json_decode($payload, true);
                if (!is_array($data)) {
                    continue;
                }

                $audio = $data['choices'][0]['delta']['audio'] ?? null;
                if (!is_array($audio)) {
                    continue;
                }

                if (is_string($audio['transcript'] ?? null)) {
                    $transcript .= $audio['transcript'];
                }

                if (is_string($audio['data'] ?? null) && $audio['data'] !== '') {
                    $audioChunks[] = $audio['data'];
                }
            }
        }

        return [
            'transcript' => $transcript,
            'bytes' => $this->decodeAudioChunks($audioChunks),
        ];
    }

    protected function decodeAudioChunks(array $chunks): string
    {
        if ($chunks === []) {
            return '';
        }

        $decoded = base64_decode(implode('', $chunks), true);
        if (is_string($decoded)) {
            return $decoded;
        }

        $bytes = '';
        foreach ($chunks as $chunk) {
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }

            $decoded = base64_decode($chunk, true);
            if (is_string($decoded)) {
                $bytes .= $decoded;
            }
        }

        return $bytes;
    }

    protected function extractToolCalls(array $message): array
    {
        $toolCalls = (array) ($message['tool_calls'] ?? []);
        if ($toolCalls !== []) {
            return array_values($toolCalls);
        }

        if (is_array($message['function_call'] ?? null)) {
            return [[
                'id' => null,
                'type' => 'function',
                'function' => $message['function_call'],
            ]];
        }

        return [];
    }

    protected function firstFunctionCall(array $toolCalls, array $message): ?array
    {
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $function = (array) ($toolCall['function'] ?? []);
            $name = $function['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            return [
                'id' => $toolCall['id'] ?? null,
                'name' => $name,
                'arguments' => $this->decodeFunctionArguments($function['arguments'] ?? []),
                'raw' => $toolCall,
            ];
        }

        if (is_array($message['function_call'] ?? null)) {
            $function = $message['function_call'];

            return [
                'id' => null,
                'name' => $function['name'] ?? null,
                'arguments' => $this->decodeFunctionArguments($function['arguments'] ?? []),
                'raw' => $function,
            ];
        }

        return null;
    }

    protected function decodeFunctionArguments(mixed $arguments): array|string
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (!is_string($arguments) || trim($arguments) === '') {
            return [];
        }

        try {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : $arguments;
        } catch (\JsonException) {
            return $arguments;
        }
    }

    protected function normalizeModel(array $model): array
    {
        $id = (string) ($model['id'] ?? '');
        $architecture = (array) ($model['architecture'] ?? []);
        $modality = strtolower((string) ($architecture['modality'] ?? ''));
        $supportedParameters = array_values((array) ($model['supported_parameters'] ?? []));

        return [
            'id' => $id,
            'name' => $model['name'] ?? $id,
            'description' => $model['description'] ?? null,
            'context_length' => $model['context_length'] ?? null,
            'pricing' => $model['pricing'] ?? [],
            'architecture' => $architecture,
            'top_provider' => $model['top_provider'] ?? [],
            'supported_parameters' => $supportedParameters,
            'capabilities' => $this->capabilitiesFromModel($id, $modality, $supportedParameters),
            'raw' => $model,
        ];
    }

    protected function capabilitiesFromModel(string $id, string $modality, array $supportedParameters): array
    {
        $haystack = strtolower($id . ' ' . $modality . ' ' . implode(' ', $supportedParameters));
        $capabilities = ['chat'];

        if (str_contains($haystack, 'image')) {
            $capabilities[] = str_contains($modality, '->image') || str_contains($haystack, 'image-generation')
                ? 'image_generation'
                : 'vision';
        }

        if (str_contains($haystack, 'audio')) {
            $capabilities[] = 'audio';
        }

        if ($this->isSpeechToTextModel($haystack)) {
            $capabilities[] = 'speech_to_text';
        }

        if (str_contains($haystack, 'tts') || str_contains($haystack, 'text-to-speech')) {
            $capabilities[] = 'text_to_speech';
            $capabilities[] = 'tts';
        }

        if (str_contains($haystack, 'embedding')) {
            $capabilities[] = 'embeddings';
        }

        if (in_array('tools', $supportedParameters, true) || in_array('tool_choice', $supportedParameters, true)) {
            $capabilities[] = 'function_calling';
        }

        return array_values(array_unique($capabilities));
    }

    protected function shouldGenerateImage(AIRequest $request): bool
    {
        $parameters = $request->getParameters();

        return in_array('image', (array) ($parameters['modalities'] ?? []), true)
            || (isset($parameters['image_config']) && is_array($parameters['image_config']));
    }

    protected function shouldUseChatAudioOutput(AIRequest $request): bool
    {
        $parameters = $request->getParameters();
        $modalities = array_map('strtolower', (array) ($parameters['modalities'] ?? []));

        return in_array('audio', $modalities, true)
            || isset($parameters['audio'])
            || str_contains(strtolower($request->getModel()->value), 'gpt-audio');
    }

    protected function isSpeechToTextModel(string $model): bool
    {
        $model = strtolower($model);

        return str_contains($model, 'whisper')
            || str_contains($model, 'transcribe')
            || str_contains($model, 'speech-to-text')
            || str_contains($model, 'stt');
    }

    protected function audioExtensionFromFormat(string $format): string
    {
        $format = strtolower($format);

        return match (true) {
            str_contains($format, 'wav') => 'wav',
            str_contains($format, 'pcm') => 'pcm',
            str_contains($format, 'opus') => 'opus',
            str_contains($format, 'aac') => 'aac',
            str_contains($format, 'flac') => 'flac',
            default => 'mp3',
        };
    }

    protected function audioMimeTypeFromFormat(string $format): string
    {
        return match ($this->audioExtensionFromFormat($format)) {
            'wav' => 'audio/wav',
            'pcm' => 'audio/pcm',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }

    protected function detectMimeType(string $file): string
    {
        $extension = strtolower(pathinfo(parse_url($file, PHP_URL_PATH) ?? $file, PATHINFO_EXTENSION));
        $byExtension = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            default => null,
        };

        if ($byExtension !== null) {
            return $byExtension;
        }

        if (filter_var($file, FILTER_VALIDATE_URL)) {
            return 'image/png';
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($file);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    protected function formatFromPath(string $file, string $mime): string
    {
        $extension = strtolower(pathinfo(parse_url($file, PHP_URL_PATH) ?? $file, PATHINFO_EXTENSION));
        if ($extension !== '') {
            return $extension;
        }

        return match ($mime) {
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/x-wav', 'audio/wave' => 'wav',
            default => strtolower(str_replace('audio/', '', $mime)),
        };
    }

    protected function fileToBase64(string $file): string
    {
        if (!is_readable($file)) {
            throw new \InvalidArgumentException("File [{$file}] is not readable.");
        }

        return base64_encode((string) file_get_contents($file));
    }

    protected function imageReference(string $file, string $mime): string
    {
        if (filter_var($file, FILTER_VALIDATE_URL) || str_starts_with($file, 'data:')) {
            return $file;
        }

        return sprintf('data:%s;base64,%s', $mime, $this->fileToBase64($file));
    }
}
