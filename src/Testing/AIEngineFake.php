<?php

declare(strict_types=1);

namespace LaravelAIEngine\Testing;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Services\UnifiedEngineManager;

class AIEngineFake extends UnifiedEngineManager
{
    protected array $responses = [];
    protected array $calls = [];

    /**
     * Don't call parent::__construct() — the fake doesn't need real services.
     * All properties that would come from parent are kept null/empty intentionally.
     */
    public function __construct(array $responses = [])
    {
        // Deliberately skip parent::__construct() to avoid requiring injected services.
        $this->responses = array_values($responses);
    }

    // -------------------------------------------------------------------------
    // Factory — NOTE: parent declares fake() as a non-static instance method,
    // so we must keep the same signature. Call AIEngineFake::create() for a
    // truly static entry-point, or call ->fake() on any UnifiedEngineManager.
    // -------------------------------------------------------------------------

    /**
     * Override parent's instance factory so that calling ->fake() on an already-
     * resolved UnifiedEngineManager still returns a properly registered fake.
     */
    public function fake(array $responses = []): static
    {
        $fake = new static($responses);

        if (function_exists('app')) {
            app()->instance(UnifiedEngineManager::class, $fake);
            app()->instance('ai-engine', $fake);
            app()->instance('unified-engine', $fake);
        }

        return $fake;
    }

    /**
     * Convenience static entry-point for test bootstrapping:
     *
     *   $fake = AIEngineFake::create();
     */
    public static function create(array $responses = []): static
    {
        $fake = new static($responses);

        if (function_exists('app')) {
            app()->instance(UnifiedEngineManager::class, $fake);
            app()->instance('ai-engine', $fake);
            app()->instance('unified-engine', $fake);
        }

        return $fake;
    }

    // -------------------------------------------------------------------------
    // Overrides — every public method from UnifiedEngineManager
    // -------------------------------------------------------------------------

    public function engine(string $engine): \LaravelAIEngine\Services\EngineProxy
    {
        $this->calls[] = ['method' => 'engine', 'engine' => $engine];
        return (new \LaravelAIEngine\Services\EngineProxy($this))->engine($engine);
    }

    public function model(string $model): \LaravelAIEngine\Services\EngineProxy
    {
        $this->calls[] = ['method' => 'model', 'model' => $model];
        return (new \LaravelAIEngine\Services\EngineProxy($this))->model($model);
    }

    public function memory(?string $driver = null): \LaravelAIEngine\Services\MemoryProxy
    {
        $this->calls[] = ['method' => 'memory', 'driver' => $driver];

        $proxy = new \LaravelAIEngine\Services\MemoryProxy(new \LaravelAIEngine\Services\Memory\MemoryManager());

        if ($driver !== null && $driver !== '') {
            $proxy->driver($driver);
        }

        return $proxy;
    }

    public function send(array $messages, array $options = []): AIResponse
    {
        $this->calls[] = [
            'method'   => 'send',
            'type'     => 'messages',
            'messages' => $messages,
            'options'  => $options,
        ];

        return $this->nextResponse($options);
    }

    public function stream(array $messages, array $options = []): \Generator
    {
        $this->calls[] = [
            'method'   => 'stream',
            'type'     => 'stream',
            'messages' => $messages,
            'options'  => $options,
        ];

        $response = $this->nextResponse($options);

        return (static function () use ($response): \Generator {
            yield $response->getContent();
        })();
    }

    public function getEngines(): array
    {
        $this->calls[] = ['method' => 'getEngines'];
        return ['openai', 'anthropic', 'gemini'];
    }

    public function getModels(?string $engine = null): array
    {
        $this->calls[] = ['method' => 'getModels', 'engine' => $engine];
        return ['gpt-4o-mini', 'claude-3-5-sonnet', 'gemini-1-5-flash'];
    }

    public function getAvailableEngines(): array
    {
        $this->calls[] = ['method' => 'getAvailableEngines'];
        return $this->getEngines();
    }

    public function getAvailableModels(?string $engine = null): array
    {
        $this->calls[] = ['method' => 'getAvailableModels', 'engine' => $engine];
        return $this->getModels($engine);
    }

    public function processRequest(AIRequest $request): AIResponse
    {
        $this->calls[] = [
            'method'  => 'processRequest',
            'type'    => 'request',
            'request' => $request,
        ];

        return $this->nextResponse($request->getMetadata());
    }

    public function processStreamingRequest(AIRequest $request): \Generator
    {
        $this->calls[] = [
            'method'  => 'processStreamingRequest',
            'type'    => 'stream',
            'request' => $request,
        ];

        $response = $this->nextResponse($request->getMetadata());

        return (static function () use ($response): \Generator {
            yield $response->getContent();
        })();
    }

    public function generateText(AIRequest $request): AIResponse
    {
        $this->calls[] = [
            'method'  => 'generateText',
            'type'    => 'text',
            'request' => $request,
        ];

        return $this->nextResponse($request->getMetadata());
    }

    public function generateImage(AIRequest $request): AIResponse
    {
        $this->calls[] = [
            'method'  => 'generateImage',
            'type'    => 'image',
            'request' => $request,
        ];

        $response = $this->nextResponse($request->getMetadata());

        // Ensure a fake image URL is present when no pre-set response provides one.
        if ($response->getFiles() === [] && $response->getContent() === '') {
            return AIResponse::success(
                content: 'https://example.com/fake-image.png',
                metadata: $request->getMetadata()
            )->withFiles(['https://example.com/fake-image.png']);
        }

        return $response;
    }

    public function generateVideo(AIRequest $request): AIResponse
    {
        $this->calls[] = [
            'method'  => 'generateVideo',
            'type'    => 'video',
            'request' => $request,
        ];

        $response = $this->nextResponse($request->getMetadata());

        if ($response->getFiles() === [] && $response->getContent() === '') {
            return AIResponse::success(
                content: 'https://example.com/fake-video.mp4',
                metadata: $request->getMetadata()
            )->withFiles(['https://example.com/fake-video.mp4']);
        }

        return $response;
    }

    public function generateEmbeddings(AIRequest $request): AIResponse
    {
        $this->calls[] = [
            'method'  => 'generateEmbeddings',
            'type'    => 'embeddings',
            'request' => $request,
        ];

        $response = $this->nextResponse($request->getMetadata());

        if ($response->getContent() === '') {
            $fakeEmbeddings = array_fill(0, 1536, 0.0);
            return AIResponse::success(
                content: json_encode($fakeEmbeddings, JSON_THROW_ON_ERROR),
                metadata: array_merge($request->getMetadata(), ['embeddings' => $fakeEmbeddings])
            );
        }

        return $response;
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        $this->calls[] = [
            'method'  => 'generateAudio',
            'type'    => 'audio',
            'request' => $request,
        ];

        $response = $this->nextResponse($request->getMetadata());

        if ($response->getFiles() === [] && $response->getContent() === '') {
            return AIResponse::success(
                content: 'https://example.com/fake-audio.mp3',
                metadata: $request->getMetadata()
            )->withFiles(['https://example.com/fake-audio.mp3']);
        }

        return $response;
    }

    public function generatePrompt(string $prompt, array $options = []): AIResponse
    {
        $this->calls[] = [
            'method'  => 'generatePrompt',
            'type'    => 'prompt',
            'prompt'  => $prompt,
            'options' => $options,
        ];

        return $this->nextResponse($options);
    }

    public function streamPrompt(string $prompt, array $options = []): \Generator
    {
        $this->calls[] = [
            'method'  => 'streamPrompt',
            'type'    => 'stream',
            'prompt'  => $prompt,
            'options' => $options,
        ];

        $response = $this->nextResponse($options);

        return (static function () use ($response): \Generator {
            yield $response->getContent();
        })();
    }

    public function rerank(string $query, array $documents, int $limit = 10): array
    {
        $this->calls[] = [
            'method'    => 'rerank',
            'query'     => $query,
            'documents' => $documents,
            'limit'     => $limit,
        ];

        return array_slice($documents, 0, $limit);
    }

    public function vectorStores(): \LaravelAIEngine\Services\SDK\VectorStoreService
    {
        $this->calls[] = ['method' => 'vectorStores'];
        return app(\LaravelAIEngine\Services\SDK\VectorStoreService::class);
    }

    public function fileStores(): \LaravelAIEngine\Services\SDK\FileStoreService
    {
        $this->calls[] = ['method' => 'fileStores'];
        return app(\LaravelAIEngine\Services\SDK\FileStoreService::class);
    }

    public function realtime(): \LaravelAIEngine\Services\SDK\RealtimeSessionService
    {
        $this->calls[] = ['method' => 'realtime'];
        return app(\LaravelAIEngine\Services\SDK\RealtimeSessionService::class);
    }

    public function traces(): \LaravelAIEngine\Services\SDK\TraceRecorderService
    {
        $this->calls[] = ['method' => 'traces'];
        return app(\LaravelAIEngine\Services\SDK\TraceRecorderService::class);
    }

    public function evaluations(): \LaravelAIEngine\Services\SDK\EvaluationService
    {
        $this->calls[] = ['method' => 'evaluations'];
        return app(\LaravelAIEngine\Services\SDK\EvaluationService::class);
    }

    public function batch(): \LaravelAIEngine\Services\BatchProcessor
    {
        $this->calls[] = ['method' => 'batch'];
        return new \LaravelAIEngine\Services\BatchProcessor($this);
    }

    public function template(string $name): \LaravelAIEngine\Services\TemplateManager
    {
        $this->calls[] = ['method' => 'template', 'name' => $name];
        return new \LaravelAIEngine\Services\TemplateManager($name, $this);
    }

    public function createRequest(
        string $prompt,
        ?string $engine = null,
        ?string $model = null,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?string $systemPrompt = null,
        array $parameters = []
    ): AIRequest {
        $this->calls[] = ['method' => 'createRequest', 'prompt' => $prompt];

        return new AIRequest(
            prompt: $prompt,
            engine: $engine,
            model: $model,
            parameters: $parameters,
            maxTokens: $maxTokens,
            temperature: $temperature,
            systemPrompt: $systemPrompt
        );
    }

    public function estimateCost(array $operations): array
    {
        $this->calls[] = ['method' => 'estimateCost', 'operations' => $operations];

        return [
            'total_credits' => 0.0,
            'breakdown'     => [],
            'currency'      => 'credits',
        ];
    }

    public function analytics(): \LaravelAIEngine\Services\AnalyticsManager
    {
        $this->calls[] = ['method' => 'analytics'];
        return app(\LaravelAIEngine\Services\AnalyticsManager::class);
    }

    public function trackRequest(array $data): void
    {
        $this->calls[] = ['method' => 'trackRequest', 'data' => $data];
    }

    public function trackAction(array $data): void
    {
        $this->calls[] = ['method' => 'trackAction', 'data' => $data];
    }

    public function trackStreaming(array $data): void
    {
        $this->calls[] = ['method' => 'trackStreaming', 'data' => $data];
    }

    public function executeAction(InteractiveAction $action, mixed $userId = null, ?string $sessionId = null): ActionResult
    {
        $this->calls[] = [
            'method'    => 'executeAction',
            'action'    => $action,
            'userId'    => $userId,
            'sessionId' => $sessionId,
        ];

        return ActionResult::success('Fake action executed.');
    }

    public function createAction(array $data): InteractiveAction
    {
        $this->calls[] = ['method' => 'createAction', 'data' => $data];
        return InteractiveAction::fromArray($data);
    }

    public function createActions(array $actionsData): array
    {
        $this->calls[] = ['method' => 'createActions', 'data' => $actionsData];
        return array_map(fn ($data) => InteractiveAction::fromArray($data), $actionsData);
    }

    public function streamResponse(string $sessionId, callable $generator, array $options = []): void
    {
        $this->calls[] = [
            'method'    => 'streamResponse',
            'type'      => 'stream',
            'sessionId' => $sessionId,
            'options'   => $options,
        ];

        foreach ($generator() as $_chunk) {
            // consumed but ignored in the fake
        }
    }

    public function streamWithActions(string $sessionId, callable $generator, array $actions = [], array $options = []): void
    {
        $this->calls[] = [
            'method'    => 'streamWithActions',
            'type'      => 'stream',
            'sessionId' => $sessionId,
            'actions'   => $actions,
            'options'   => $options,
        ];
    }

    public function getStreamingStats(): array
    {
        $this->calls[] = ['method' => 'getStreamingStats'];
        return [];
    }

    public function getDashboardData(array $filters = []): array
    {
        $this->calls[] = ['method' => 'getDashboardData', 'filters' => $filters];
        return [];
    }

    public function getUsageStats(array $filters = []): array
    {
        $this->calls[] = ['method' => 'getUsageStats', 'filters' => $filters];
        return [];
    }

    public function getPerformanceMetrics(array $filters = []): array
    {
        $this->calls[] = ['method' => 'getPerformanceMetrics', 'filters' => $filters];
        return [];
    }

    // -------------------------------------------------------------------------
    // Inspection helpers (backward-compatible)
    // -------------------------------------------------------------------------

    /**
     * Return all recorded calls.
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Alias kept for backward compatibility.
     */
    public function requests(): array
    {
        return $this->calls;
    }

    // -------------------------------------------------------------------------
    // Assertion helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that the engine was prompted at least once.
     * Accepts a callable that receives the call array and returns true on match.
     * Also accepts a plain string to match against the 'prompt' key.
     */
    public function assertPrompted(callable|string|null $callback = null): void
    {
        $prompted = array_filter($this->calls, fn ($call) => in_array(
            $call['method'] ?? '',
            ['generatePrompt', 'send', 'processRequest', 'generateText', 'streamPrompt', 'stream'],
            true
        ));

        if ($prompted === []) {
            throw new \RuntimeException('Expected AI engine to be prompted, but no prompt requests were recorded.');
        }

        if ($callback === null) {
            return;
        }

        if (is_string($callback)) {
            $needle = $callback;
            $callback = static fn ($call) => ($call['prompt'] ?? null) === $needle;
        }

        foreach ($prompted as $call) {
            if ($callback($call)) {
                return;
            }
        }

        throw new \RuntimeException('No recorded AI engine prompt request matched the assertion callback.');
    }

    /**
     * Assert that generateImage() was called at least once.
     */
    public function assertImageGenerated(callable|null $callback = null): void
    {
        $this->assertCallType('generateImage', 'image generation', $callback);
    }

    /**
     * Assert that generateVideo() was called at least once.
     */
    public function assertVideoGenerated(callable|null $callback = null): void
    {
        $this->assertCallType('generateVideo', 'video generation', $callback);
    }

    /**
     * Assert that generateEmbeddings() was called at least once.
     */
    public function assertEmbeddingsRequested(callable|null $callback = null): void
    {
        $this->assertCallType('generateEmbeddings', 'embeddings request', $callback);
    }

    /**
     * Assert that a streaming method was called at least once.
     */
    public function assertStreamed(callable|null $callback = null): void
    {
        $streamed = array_filter(
            $this->calls,
            fn ($call) => ($call['type'] ?? null) === 'stream'
        );

        if ($streamed === []) {
            throw new \RuntimeException('Expected AI engine to be streamed, but no streaming calls were recorded.');
        }

        if ($callback === null) {
            return;
        }

        foreach ($streamed as $call) {
            if ($callback($call)) {
                return;
            }
        }

        throw new \RuntimeException('No recorded streaming call matched the assertion callback.');
    }

    /**
     * Assert that a specific model was used in any call.
     */
    public function assertModelUsed(string $model): void
    {
        foreach ($this->calls as $call) {
            $request = $call['request'] ?? null;
            if ($request instanceof AIRequest && $request->getModel()->value === $model) {
                return;
            }

            $opts = $call['options'] ?? [];
            if (is_array($opts) && ($opts['model'] ?? null) === $model) {
                return;
            }
        }

        throw new \RuntimeException("Expected model [{$model}] to be used, but it was not found in any recorded call.");
    }

    /**
     * Assert that a specific engine was used in any call.
     */
    public function assertEngineUsed(string $engine): void
    {
        foreach ($this->calls as $call) {
            $request = $call['request'] ?? null;
            if ($request instanceof AIRequest && $request->getEngine()->value === $engine) {
                return;
            }

            $opts = $call['options'] ?? [];
            if (is_array($opts) && ($opts['engine'] ?? null) === $engine) {
                return;
            }

            if (($call['engine'] ?? null) === $engine) {
                return;
            }
        }

        throw new \RuntimeException("Expected engine [{$engine}] to be used, but it was not found in any recorded call.");
    }

    /**
     * Assert that a specific temperature was used in any call.
     */
    public function assertTemperatureUsed(float $temperature): void
    {
        foreach ($this->calls as $call) {
            $request = $call['request'] ?? null;
            if ($request instanceof AIRequest && $request->getTemperature() !== null && abs($request->getTemperature() - $temperature) < 0.00001) {
                return;
            }

            $opts = $call['options'] ?? [];
            if (is_array($opts) && isset($opts['temperature']) && abs((float) $opts['temperature'] - $temperature) < 0.00001) {
                return;
            }
        }

        throw new \RuntimeException("Expected temperature [{$temperature}] to be used, but it was not found in any recorded call.");
    }

    /**
     * Assert that no calls were made to the fake.
     */
    public function assertNoRequests(): void
    {
        if ($this->calls !== []) {
            $count = count($this->calls);
            throw new \RuntimeException("Expected no AI engine calls, but [{$count}] call(s) were recorded.");
        }
    }

    /**
     * Assert that exactly $count calls were recorded.
     */
    public function assertRequestCount(int $count): void
    {
        $actual = count($this->calls);

        if ($actual !== $count) {
            throw new \RuntimeException("Expected [{$count}] AI engine call(s), but [{$actual}] were recorded.");
        }
    }

    /**
     * Assert that the last call contained a specific key/value pair.
     * Searches the top-level call array and the nested 'options' / 'request' metadata.
     */
    public function assertLastRequestHad(string $key, mixed $value): void
    {
        if ($this->calls === []) {
            throw new \RuntimeException("Expected AI engine calls to exist, but none were recorded.");
        }

        $last = end($this->calls);

        // Top-level key
        if (array_key_exists($key, $last) && $last[$key] === $value) {
            return;
        }

        // Inside options
        $opts = $last['options'] ?? [];
        if (is_array($opts) && array_key_exists($key, $opts) && $opts[$key] === $value) {
            return;
        }

        // Inside request metadata
        $request = $last['request'] ?? null;
        if ($request instanceof AIRequest) {
            $meta = $request->getMetadata();
            if (array_key_exists($key, $meta) && $meta[$key] === $value) {
                return;
            }
        }

        throw new \RuntimeException(
            "Expected last AI engine call to have [{$key}] = [{$value}], but it was not found."
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    protected function assertCallType(string $method, string $label, ?callable $callback): void
    {
        $matched = array_filter($this->calls, fn ($call) => ($call['method'] ?? '') === $method);

        if ($matched === []) {
            throw new \RuntimeException("Expected AI engine to perform [{$label}], but no [{$method}] calls were recorded.");
        }

        if ($callback === null) {
            return;
        }

        foreach ($matched as $call) {
            if ($callback($call)) {
                return;
            }
        }

        throw new \RuntimeException("No recorded [{$method}] call matched the assertion callback.");
    }

    protected function nextResponse(array $metadata = []): AIResponse
    {
        $response = array_shift($this->responses);

        if ($response instanceof AIResponse) {
            return $response;
        }

        if (is_string($response)) {
            return AIResponse::success($response, metadata: $metadata);
        }

        if (is_array($response)) {
            return new AIResponse(
                content: (string) ($response['content'] ?? ''),
                engine: $response['engine'] ?? null,
                model:  $response['model'] ?? null,
                metadata: array_merge($metadata, (array) ($response['metadata'] ?? [])),
                error: $response['error'] ?? null,
                success: (bool) ($response['success'] ?? true)
            );
        }

        return AIResponse::success('', metadata: $metadata);
    }
}
