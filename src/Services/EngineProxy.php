<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIResponse;

class EngineProxy
{
    protected array $options = [];

    public function __construct(protected UnifiedEngineManager $manager) {}

    public function engine(string $engine): self
    {
        $this->options['engine'] = $engine;

        return $this;
    }

    public function model(string $model): self
    {
        $this->options['model'] = $model;

        return $this;
    }

    public function temperature(float $temperature): self
    {
        $this->options['temperature'] = $temperature;

        return $this;
    }

    public function maxTokens(int $maxTokens): self
    {
        $this->options['max_tokens'] = $maxTokens;

        return $this;
    }

    public function user(string $user): self
    {
        $this->options['user'] = $user;

        return $this;
    }

    public function conversation(string $conversationId): self
    {
        $this->options['conversation_id'] = $conversationId;

        return $this;
    }

    public function send(array $messages, array $options = []): AIResponse
    {
        $finalOptions = array_merge($this->options, $options);
        $this->options = [];

        return $this->manager->send($messages, $finalOptions);
    }

    public function stream(array $messages, array $options = []): \Generator
    {
        $finalOptions = array_merge($this->options, $options);
        $this->options = [];

        return $this->manager->stream($messages, $finalOptions);
    }
}

