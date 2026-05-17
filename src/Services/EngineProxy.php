<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Contracts\ProviderToolInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\StructuredOutputSchema;

class EngineProxy
{
    protected array $options = [];
    protected bool $retryEnabled = false;
    protected int $maxRetries = 3;
    protected string $backoffStrategy = 'exponential';
    protected ?string $fallbackEngine = null;

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

    public function withTemperature(float $temperature): self
    {
        return $this->temperature($temperature);
    }

    public function maxTokens(int $maxTokens): self
    {
        $this->options['max_tokens'] = $maxTokens;

        return $this;
    }

    public function withMaxTokens(int $maxTokens): self
    {
        return $this->maxTokens($maxTokens);
    }

    public function user(string $user): self
    {
        $this->options['user'] = $user;

        return $this;
    }

    public function forUser(string $user): self
    {
        return $this->user($user);
    }

    public function conversation(string $conversationId): self
    {
        $this->options['conversation_id'] = $conversationId;

        return $this;
    }

    public function withSystemPrompt(string $systemPrompt): self
    {
        $this->options['system_prompt'] = $systemPrompt;

        return $this;
    }

    public function withMessages(array $messages): self
    {
        $this->options['messages'] = $messages;

        return $this;
    }

    public function withSeed(int $seed): self
    {
        $this->options['seed'] = $seed;

        return $this;
    }

    public function withMetadata(array $metadata): self
    {
        $existing = $this->options['metadata'] ?? [];
        $this->options['metadata'] = array_merge(is_array($existing) ? $existing : [], $metadata);

        return $this;
    }

    public function withParameters(array $parameters): self
    {
        $existing = $this->options['parameters'] ?? [];
        $this->options['parameters'] = array_merge(is_array($existing) ? $existing : [], $parameters);

        return $this;
    }

    public function withContext(array $context): self
    {
        $existing = $this->options['context'] ?? [];
        $this->options['context'] = array_merge(is_array($existing) ? $existing : [], $context);

        return $this;
    }

    public function withFiles(array $files): self
    {
        $existing = $this->options['files'] ?? [];
        $this->options['files'] = array_merge(is_array($existing) ? $existing : [], $files);

        return $this;
    }

    public function withTools(array $tools): self
    {
        $serialized = array_map(function ($tool): array {
            if ($tool instanceof ProviderToolInterface) {
                return $tool->toArray();
            }

            if (is_object($tool) && method_exists($tool, 'toArray')) {
                return (array) $tool->toArray();
            }

            return (array) $tool;
        }, $tools);

        $existing = $this->options['functions'] ?? [];
        $this->options['functions'] = array_merge(is_array($existing) ? $existing : [], $serialized);

        return $this;
    }

    public function withFunctionCall(?array $functionCall): self
    {
        $this->options['function_call'] = $functionCall;

        return $this;
    }

    public function withStructuredOutput(StructuredOutputSchema|array $schema, string $name = 'response', bool $strict = true): self
    {
        $definition = $schema instanceof StructuredOutputSchema
            ? $schema->toArray()
            : StructuredOutputSchema::make($schema, $name, $strict)->toArray();

        return $this->withMetadata([
            'structured_output' => $definition,
        ]);
    }

    public function withRetry(int $maxAttempts = 3, string $backoff = 'exponential'): self
    {
        $this->retryEnabled = true;
        $this->maxRetries = max(1, $maxAttempts);
        $this->backoffStrategy = $backoff;

        return $this;
    }

    public function fallbackTo(string $engine): self
    {
        $this->fallbackEngine = $engine;

        return $this;
    }

    public function cache(bool $enabled = true, ?int $ttl = null): self
    {
        return $this->withMetadata([
            'cache' => [
                'enabled' => $enabled,
                'ttl' => $ttl,
            ],
        ]);
    }

    public function rateLimit(bool $enabled = true): self
    {
        return $this->withMetadata([
            'rate_limit' => [
                'enabled' => $enabled,
            ],
        ]);
    }

    public function withModeration(string $level = 'medium'): self
    {
        return $this->withMetadata([
            'moderation' => [
                'enabled' => true,
                'level' => $level,
            ],
        ]);
    }

    public function generate(string $prompt, array $parameters = []): AIResponse
    {
        if ($parameters !== []) {
            $this->withParameters($parameters);
        }

        ['options' => $finalOptions, 'retry' => $retryOptions] = $this->releasePromptState();

        return $this->executePrompt(
            fn (array $options) => $this->manager->generatePrompt($prompt, $options),
            $finalOptions,
            $retryOptions
        );
    }

    public function generateStream(string $prompt, array $parameters = []): \Generator
    {
        if ($parameters !== []) {
            $this->withParameters($parameters);
        }

        ['options' => $finalOptions, 'retry' => $retryOptions] = $this->releasePromptState();

        $attempt = 1;
        $lastException = null;
        $options = $finalOptions;

        while ($attempt <= $retryOptions['max_retries']) {
            try {
                return $this->manager->streamPrompt($prompt, $options);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if (!$retryOptions['enabled'] || $attempt === $retryOptions['max_retries']) {
                    if ($retryOptions['fallback_engine'] !== null && $retryOptions['fallback_engine'] !== '') {
                        $fallbackOptions = $options;
                        $fallbackOptions['engine'] = $retryOptions['fallback_engine'];
                        unset($fallbackOptions['model']);

                        return $this->manager->streamPrompt($prompt, $fallbackOptions);
                    }

                    throw $exception;
                }

                $this->sleepForRetry($attempt, $retryOptions['backoff_strategy']);
                $attempt++;
            }
        }

        throw $lastException ?? new \RuntimeException('Unable to generate streaming response.');
    }

    public function generateImage(string $prompt, int $count = 1): AIResponse
    {
        return $this->withParameters(['image_count' => $count])->generate($prompt);
    }

    public function generateVideo(string $prompt, int $count = 1): AIResponse
    {
        return $this->withParameters(['video_count' => $count])->generate($prompt);
    }

    public function generateAudio(string $prompt, float $minutes = 1.0): AIResponse
    {
        return $this->withParameters(['audio_minutes' => $minutes])->generate($prompt);
    }

    public function audioToText(string $audioPath, array $parameters = []): AIResponse
    {
        if ($parameters !== []) {
            $this->withParameters($parameters);
        }

        $options = $this->releaseOptions();

        return $this->manager->audioToText($this->buildAudioOperationRequest('', [$audioPath], $options));
    }

    public function speechToText(string $audioPath, array $parameters = []): AIResponse
    {
        return $this->audioToText($audioPath, $parameters);
    }

    public function speechToSpeech(string $audioPath, string $prompt = '', array $parameters = []): AIResponse
    {
        if ($parameters !== []) {
            $this->withParameters($parameters);
        }

        $options = $this->releaseOptions();

        return $this->manager->speechToSpeech($this->buildAudioOperationRequest($prompt, [$audioPath], $options));
    }

    public function estimateCost(string $prompt): array
    {
        $finalOptions = $this->releaseOptions();
        $engine = $finalOptions['engine'] ?? config('ai-engine.default', 'openai');
        $model = $finalOptions['model'] ?? config('ai-engine.default_model', 'gpt-4o');

        return $this->manager->estimateCost([[
            'prompt' => $prompt,
            'engine' => $engine instanceof \BackedEnum ? $engine->value : $engine,
            'model' => $model instanceof \BackedEnum ? $model->value : $model,
            'parameters' => is_array($finalOptions['parameters'] ?? null) ? $finalOptions['parameters'] : [],
        ]]);
    }

    public function send(array $messages, array $options = []): AIResponse
    {
        $finalOptions = array_merge($this->releaseOptions(), $options);

        return $this->manager->send($messages, $finalOptions);
    }

    public function stream(array $messages, array $options = []): \Generator
    {
        $finalOptions = array_merge($this->releaseOptions(), $options);

        return $this->manager->stream($messages, $finalOptions);
    }

    protected function executePrompt(callable $callback, array $options, array $retryOptions): AIResponse
    {
        $attempt = 1;
        $lastException = null;

        while ($attempt <= $retryOptions['max_retries']) {
            try {
                return $callback($options);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if (!$retryOptions['enabled'] || $attempt === $retryOptions['max_retries']) {
                    break;
                }

                $this->sleepForRetry($attempt, $retryOptions['backoff_strategy']);
                $attempt++;
            }
        }

        if ($retryOptions['fallback_engine'] !== null && $retryOptions['fallback_engine'] !== '') {
            $fallbackOptions = $options;
            $fallbackOptions['engine'] = $retryOptions['fallback_engine'];
            unset($fallbackOptions['model']);

            return $callback($fallbackOptions);
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        throw new \RuntimeException('Unable to generate response.');
    }

    protected function releasePromptState(): array
    {
        $state = [
            'options' => $this->options,
            'retry' => [
                'enabled' => $this->retryEnabled,
                'max_retries' => $this->maxRetries,
                'backoff_strategy' => $this->backoffStrategy,
                'fallback_engine' => $this->fallbackEngine,
            ],
        ];

        $this->resetState();

        return $state;
    }

    protected function releaseOptions(): array
    {
        $options = $this->options;
        $this->resetState();

        return $options;
    }

    protected function resetState(): void
    {
        $this->options = [];
        $this->retryEnabled = false;
        $this->maxRetries = 3;
        $this->backoffStrategy = 'exponential';
        $this->fallbackEngine = null;
    }

    protected function buildAudioOperationRequest(string $prompt, array $files, array $options): AIRequest
    {
        return new AIRequest(
            prompt: $prompt,
            engine: $options['engine'] ?? null,
            model: $options['model'] ?? null,
            parameters: is_array($options['parameters'] ?? null) ? $options['parameters'] : [],
            userId: isset($options['user']) ? (string) $options['user'] : ($options['user_id'] ?? null),
            conversationId: isset($options['conversation_id']) ? (string) $options['conversation_id'] : null,
            context: is_array($options['context'] ?? null) ? $options['context'] : [],
            files: array_merge(is_array($options['files'] ?? null) ? $options['files'] : [], $files),
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

    protected function sleepForRetry(int $attempt, string $backoffStrategy): void
    {
        $milliseconds = match ($backoffStrategy) {
            'linear' => $attempt * 1000,
            'exponential' => (int) (pow(2, $attempt - 1) * 1000),
            default => 1000,
        };

        usleep($milliseconds * 1000);
    }
}
