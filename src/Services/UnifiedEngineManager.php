<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Memory\MemoryManager;

class UnifiedEngineManager
{
    public function __construct(
        protected AIEngineService $aiEngineService,
        protected MemoryManager $memoryManager,
        protected ?AIEngineManager $legacyEngineManager = null
    ) {}

    public function engine(string $engine): EngineProxy
    {
        return (new EngineProxy($this))->engine($engine);
    }

    public function memory(?string $driver = null): MemoryProxy
    {
        $proxy = new MemoryProxy($this->memoryManager);

        if ($driver !== null && $driver !== '') {
            $proxy->driver($driver);
        }

        return $proxy;
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

    /**
     * Backward compatibility bridge for facade methods that still live on AIEngineManager.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $manager = $this->resolveLegacyEngineManager();

        if ($manager !== null && method_exists($manager, $name)) {
            return $manager->{$name}(...$arguments);
        }

        throw new \BadMethodCallException(sprintf(
            'Method %s::%s does not exist.',
            static::class,
            $name
        ));
    }

    protected function buildRequest(
        array $messages,
        array $options,
        bool $stream,
        ?string $conversationId
    ): AIRequest {
        $engine = EngineEnum::fromSlug((string) ($options['engine'] ?? config('ai-engine.default', config('ai-engine.default_engine', EngineEnum::OPENAI))));
        $model = EntityEnum::from((string) ($options['model'] ?? config('ai-engine.default_model', EntityEnum::GPT_4O)));

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
            temperature: isset($options['temperature']) ? (float) $options['temperature'] : null
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

    protected function resolveLegacyEngineManager(): ?AIEngineManager
    {
        if ($this->legacyEngineManager instanceof AIEngineManager) {
            return $this->legacyEngineManager;
        }

        if (function_exists('app') && app()->bound(AIEngineManager::class)) {
            $this->legacyEngineManager = app(AIEngineManager::class);
        }

        return $this->legacyEngineManager;
    }
}
