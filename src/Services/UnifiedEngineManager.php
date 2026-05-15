<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Memory\MemoryManager;
use LaravelAIEngine\Services\SDK\RerankingService;
use LaravelAIEngine\Services\SDK\FileStoreService;
use LaravelAIEngine\Services\SDK\EvaluationService;
use LaravelAIEngine\Services\SDK\RealtimeSessionService;
use LaravelAIEngine\Services\SDK\TraceRecorderService;
use LaravelAIEngine\Services\SDK\VectorStoreService;
use LaravelAIEngine\Services\Streaming\WebSocketManager;
use LaravelAIEngine\Testing\AIEngineFake;

class UnifiedEngineManager
{
    public function __construct(
        protected AIEngineService $aiEngineService,
        protected MemoryManager $memoryManager
    ) {}

    public function engine(string $engine): EngineProxy
    {
        return (new EngineProxy($this))->engine($engine);
    }

    public function model(string $model): EngineProxy
    {
        return (new EngineProxy($this))->model($model);
    }

    public function memory(?string $driver = null): MemoryProxy
    {
        $proxy = new MemoryProxy($this->memoryManager);

        if ($driver !== null && $driver !== '') {
            $proxy->driver($driver);
        }

        return $proxy;
    }

    public function fake(array $responses = []): AIEngineFake
    {
        $fake = new AIEngineFake($responses);

        if (function_exists('app')) {
            app()->instance(self::class, $fake);
            app()->instance('ai-engine', $fake);
            app()->instance('unified-engine', $fake);
        }

        return $fake;
    }

    public function send(array $messages, array $options = []): AIResponse
    {
        $conversationId = $this->extractConversationId($options);
        $contextLimit = (int) ($options['context_limit'] ?? 50);
        $preparedMessages = $this->mergeConversationContext($messages, $conversationId, $contextLimit);

        $request = $this->buildRequest($preparedMessages, $options, false, $conversationId);
        $response = $this->aiEngineService->generate($request);

        $this->storeConversationMessages($conversationId, $messages, $response);

        return $response;
    }

    public function stream(array $messages, array $options = []): \Generator
    {
        $conversationId = $this->extractConversationId($options);
        $contextLimit = (int) ($options['context_limit'] ?? 50);
        $preparedMessages = $this->mergeConversationContext($messages, $conversationId, $contextLimit);
        $request = $this->buildRequest($preparedMessages, $options, true, $conversationId);

        return $this->aiEngineService->stream($request);
    }

    public function getEngines(): array
    {
        $engines = $this->aiEngineService->getAvailableEngines();

        if (!empty($engines) && is_array($engines[0] ?? null) && isset($engines[0]['name'])) {
            return array_values(array_filter(array_map(fn (array $engine) => $engine['name'] ?? null, $engines)));
        }

        return $engines;
    }

    public function getModels(?string $engine = null): array
    {
        try {
            return $this->aiEngineService->getAvailableModels($engine);
        } catch (\Throwable) {
            $models = $engine
                ? EntityEnum::forEngine(EngineEnum::fromSlug($engine))
                : EntityEnum::cases();

            return array_map(fn (EntityEnum $model) => $model->value, $models);
        }
    }

    public function getAvailableEngines(): array
    {
        return $this->getEngines();
    }

    public function getAvailableModels(?string $engine = null): array
    {
        return $this->getModels($engine);
    }

    public function processRequest(AIRequest $request): AIResponse
    {
        return $this->aiEngineService->generate($request);
    }

    public function processStreamingRequest(AIRequest $request): \Generator
    {
        return $this->aiEngineService->stream($request);
    }

    public function generateText(AIRequest $request): AIResponse
    {
        return $this->aiEngineService->generateText($request);
    }

    public function generateImage(AIRequest $request): AIResponse
    {
        return $this->aiEngineService->generateImage($request);
    }

    public function generateVideo(AIRequest $request): AIResponse
    {
        return $this->aiEngineService->generateVideo($request);
    }

    public function generateEmbeddings(AIRequest $request): AIResponse
    {
        return $this->aiEngineService->generateEmbeddings($request);
    }

    public function rerank(string $query, array $documents, int $limit = 10): array
    {
        return app(RerankingService::class)->rerank($query, $documents, $limit);
    }

    public function vectorStores(): VectorStoreService
    {
        return app(VectorStoreService::class);
    }

    public function fileStores(): FileStoreService
    {
        return app(FileStoreService::class);
    }

    public function realtime(): RealtimeSessionService
    {
        return app(RealtimeSessionService::class);
    }

    public function traces(): TraceRecorderService
    {
        return app(TraceRecorderService::class);
    }

    public function evaluations(): EvaluationService
    {
        return app(EvaluationService::class);
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        return $this->aiEngineService->generateAudio($request);
    }

    public function generatePrompt(string $prompt, array $options = []): AIResponse
    {
        return $this->processRequest($this->buildPromptRequest($prompt, $options, false));
    }

    public function streamPrompt(string $prompt, array $options = []): \Generator
    {
        return $this->processStreamingRequest($this->buildPromptRequest($prompt, $options, true));
    }

    public function batch(): BatchProcessor
    {
        return new BatchProcessor($this);
    }

    public function template(string $name): TemplateManager
    {
        return new TemplateManager($name, $this);
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
        $totalCredits = 0.0;
        $breakdown = [];

        foreach ($operations as $operation) {
            $engine = EngineEnum::fromSlug((string) $operation['engine']);
            $model = EntityEnum::fromSlug((string) $operation['model']);

            $request = AIRequest::make(
                (string) ($operation['prompt'] ?? ''),
                $engine,
                $model,
                is_array($operation['parameters'] ?? null) ? $operation['parameters'] : []
            );

            $credits = $this->creditManager()->calculateCredits($request);
            $totalCredits += $credits;

            $breakdown[] = [
                'operation' => $operation,
                'credits' => $credits,
                'cost_breakdown' => [
                    'input_count' => $this->getInputCount($request),
                    'credit_index' => $model->creditIndex(),
                    'calculation_method' => $model->calculationMethod(),
                ],
            ];
        }

        return [
            'total_credits' => $totalCredits,
            'breakdown' => $breakdown,
            'currency' => config('ai-engine.credits.currency', 'credits'),
        ];
    }

    public function analytics(): AnalyticsManager
    {
        return app(AnalyticsManager::class);
    }

    public function trackRequest(array $data): void
    {
        $this->analytics()->trackRequest($data);
    }

    public function trackAction(array $data): void
    {
        $this->analytics()->trackAction($data);
    }

    public function trackStreaming(array $data): void
    {
        $this->analytics()->trackStreaming($data);
    }

    public function executeAction(InteractiveAction $action, mixed $userId = null, ?string $sessionId = null): ActionResult
    {
        return $this->actionManager()->executeAction($action, $userId, $sessionId);
    }

    public function createAction(array $data): InteractiveAction
    {
        return InteractiveAction::fromArray($data);
    }

    public function createActions(array $actionsData): array
    {
        return array_map(fn ($data) => InteractiveAction::fromArray($data), $actionsData);
    }

    public function streamResponse(string $sessionId, callable $generator, array $options = []): void
    {
        $manager = $this->webSocketManager();

        if ($manager !== null) {
            $manager->streamResponse($sessionId, $generator, $options);
            return;
        }

        foreach ($generator() as $chunk) {
            // No-op fallback when streaming infrastructure is unavailable.
        }
    }

    public function streamWithActions(string $sessionId, callable $generator, array $actions = [], array $options = []): void
    {
        $manager = $this->webSocketManager();

        if ($manager !== null) {
            $manager->streamWithActions($sessionId, $generator, $actions, $options);
        }
    }

    public function getStreamingStats(): array
    {
        return $this->webSocketManager()?->getStats() ?? [];
    }

    public function getDashboardData(array $filters = []): array
    {
        return $this->analytics()->getDashboardData($filters);
    }

    public function getUsageStats(array $filters = []): array
    {
        return $this->analytics()->getUsageStats($filters);
    }

    public function getPerformanceMetrics(array $filters = []): array
    {
        return $this->analytics()->getPerformanceMetrics($filters);
    }

    protected function buildRequest(
        array $messages,
        array $options,
        bool $stream,
        ?string $conversationId
    ): AIRequest {
        $engine = array_key_exists('engine', $options)
            ? EngineEnum::fromSlug((string) $options['engine'])
            : null;
        $model = array_key_exists('model', $options)
            ? EntityEnum::from((string) $options['model'])
            : null;

        $prompt = $this->extractPrompt($messages);

        return new AIRequest(
            prompt: $prompt,
            engine: $engine,
            model: $model,
            userId: isset($options['user']) ? (string) $options['user'] : ($options['user_id'] ?? null),
            conversationId: $conversationId,
            messages: $messages,
            stream: $stream,
            maxTokens: isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
            temperature: isset($options['temperature']) ? (float) $options['temperature'] : null,
            metadata: is_array($options['metadata'] ?? null) ? $options['metadata'] : [],
            functions: is_array($options['functions'] ?? null) ? $options['functions'] : [],
            functionCall: is_array($options['function_call'] ?? null) ? $options['function_call'] : null
        );
    }

    protected function buildPromptRequest(string $prompt, array $options, bool $stream): AIRequest
    {
        return new AIRequest(
            prompt: $prompt,
            engine: $options['engine'] ?? null,
            model: $options['model'] ?? null,
            parameters: is_array($options['parameters'] ?? null) ? $options['parameters'] : [],
            userId: isset($options['user']) ? (string) $options['user'] : ($options['user_id'] ?? null),
            conversationId: $this->extractConversationId($options),
            context: is_array($options['context'] ?? null) ? $options['context'] : [],
            files: is_array($options['files'] ?? null) ? $options['files'] : [],
            stream: $stream,
            systemPrompt: isset($options['system_prompt']) ? (string) $options['system_prompt'] : null,
            messages: is_array($options['messages'] ?? null) ? $options['messages'] : [],
            maxTokens: isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
            temperature: isset($options['temperature']) ? (float) $options['temperature'] : null,
            seed: isset($options['seed']) ? (int) $options['seed'] : null,
            metadata: is_array($options['metadata'] ?? null) ? $options['metadata'] : [],
            functions: is_array($options['functions'] ?? null) ? $options['functions'] : [],
            functionCall: is_array($options['function_call'] ?? null) ? $options['function_call'] : null
        );
    }

    protected function extractPrompt(array $messages): string
    {
        $lastMessage = end($messages);
        if (is_array($lastMessage) && isset($lastMessage['content']) && is_string($lastMessage['content'])) {
            return $lastMessage['content'];
        }

        return '';
    }

    protected function extractConversationId(array $options): ?string
    {
        $conversationId = $options['conversation_id'] ?? null;

        if (!is_string($conversationId) || $conversationId === '') {
            return null;
        }

        return $conversationId;
    }

    protected function mergeConversationContext(array $messages, ?string $conversationId, int $limit): array
    {
        if ($conversationId === null) {
            return $messages;
        }

        try {
            $contextMessages = $this->memoryManager->getContext($conversationId, $limit);
        } catch (\Throwable) {
            $contextMessages = $this->memoryManager->getContext($conversationId);
        }

        if (!is_array($contextMessages) || $contextMessages === []) {
            return $messages;
        }

        return array_merge($contextMessages, $messages);
    }

    protected function storeConversationMessages(?string $conversationId, array $messages, AIResponse $response): void
    {
        if ($conversationId === null) {
            return;
        }

        $lastUserMessage = $this->extractLastUserMessage($messages);
        if ($lastUserMessage !== null) {
            $this->memoryManager->addMessage($conversationId, 'user', $lastUserMessage, []);
        }

        if ($response->success) {
            $this->memoryManager->addMessage($conversationId, 'assistant', $response->getContent(), []);
        }
    }

    protected function extractLastUserMessage(array $messages): ?string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index] ?? null;
            if (!is_array($message)) {
                continue;
            }

            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = $message['content'] ?? null;
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        return null;
    }

    protected function actionManager(): \LaravelAIEngine\Services\Actions\ActionManager
    {
        return app(\LaravelAIEngine\Services\Actions\ActionManager::class);
    }

    protected function creditManager(): CreditManager
    {
        return app(CreditManager::class);
    }

    protected function getInputCount(AIRequest $request): float|int
    {
        return match ($request->model->calculationMethod()) {
            'words' => str_word_count(strip_tags($request->prompt)),
            'characters' => mb_strlen(strip_tags($request->prompt)),
            'images' => $request->parameters['image_count'] ?? 1,
            'videos' => $request->parameters['video_count'] ?? 1,
            'minutes' => $request->parameters['audio_minutes'] ?? 1,
            default => 1,
        };
    }

    protected function webSocketManager(): ?WebSocketManager
    {
        if (function_exists('app') && app()->bound(WebSocketManager::class)) {
            return app(WebSocketManager::class);
        }

        return null;
    }
}
