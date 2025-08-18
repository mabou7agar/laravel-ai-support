<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\DTOs\InteractiveAction;

class AIResponse
{
    public function __construct(
        public readonly string $content,
        public readonly EngineEnum $engine,
        public readonly EntityEnum $model,
        public readonly array $metadata = [],
        public readonly ?int $tokensUsed = null,
        public readonly ?float $creditsUsed = null,
        public readonly ?float $latency = null,
        public readonly ?string $requestId = null,
        public readonly ?array $usage = null,
        public readonly bool $cached = false,
        public readonly ?string $finishReason = null,
        public readonly array $files = [],
        public readonly array $actions = [],
        public readonly ?string $error = null,
        public readonly bool $success = true
    ) {}

    /**
     * Create a successful response
     */
    public static function success(
        string $content,
        EngineEnum $engine,
        EntityEnum $model,
        array $metadata = [],
        array $actions = []
    ): self {
        return new self(
            content: $content,
            engine: $engine,
            model: $model,
            metadata: $metadata,
            actions: $actions,
            success: true
        );
    }

    /**
     * Create an error response
     */
    public static function error(
        string $error,
        EngineEnum $engine,
        EntityEnum $model,
        array $metadata = []
    ): self {
        return new self(
            content: '',
            engine: $engine,
            model: $model,
            metadata: $metadata,
            error: $error,
            success: false
        );
    }

    /**
     * Add usage information
     */
    public function withUsage(
        ?int $tokensUsed = null,
        ?float $creditsUsed = null,
        ?float $latency = null
    ): self {
        return new self(
            content: $this->content,
            engine: $this->engine,
            model: $this->model,
            metadata: $this->metadata,
            tokensUsed: $tokensUsed ?? $this->tokensUsed,
            creditsUsed: $creditsUsed ?? $this->creditsUsed,
            latency: $latency ?? $this->latency,
            requestId: $this->requestId,
            usage: $this->usage,
            cached: $this->cached,
            finishReason: $this->finishReason,
            files: $this->files,
            error: $this->error,
            success: $this->success
        );
    }

    /**
     * Add request ID
     */
    public function withRequestId(string $requestId): self
    {
        return new self(
            content: $this->content,
            engine: $this->engine,
            model: $this->model,
            metadata: $this->metadata,
            tokensUsed: $this->tokensUsed,
            creditsUsed: $this->creditsUsed,
            latency: $this->latency,
            requestId: $requestId,
            usage: $this->usage,
            cached: $this->cached,
            finishReason: $this->finishReason,
            files: $this->files,
            error: $this->error,
            success: $this->success
        );
    }

    /**
     * Mark as cached response
     */
    public function markAsCached(): self
    {
        return new self(
            content: $this->content,
            engine: $this->engine,
            model: $this->model,
            metadata: $this->metadata,
            tokensUsed: $this->tokensUsed,
            creditsUsed: $this->creditsUsed,
            latency: $this->latency,
            requestId: $this->requestId,
            usage: $this->usage,
            cached: true,
            finishReason: $this->finishReason,
            files: $this->files,
            error: $this->error,
            success: $this->success
        );
    }

    /**
     * Add files (for image/video/audio responses)
     */
    public function withFiles(array $files): self
    {
        return new self(
            content: $this->content,
            engine: $this->engine,
            model: $this->model,
            metadata: $this->metadata,
            tokensUsed: $this->tokensUsed,
            creditsUsed: $this->creditsUsed,
            latency: $this->latency,
            requestId: $this->requestId,
            usage: $this->usage,
            cached: $this->cached,
            finishReason: $this->finishReason,
            files: $files,
            actions: $this->actions,
            error: $this->error,
            success: $this->success
        );
    }

    /**
     * Add interactive actions to the response
     */
    public function withActions(array $actions): self
    {
        return new self(
            content: $this->content,
            engine: $this->engine,
            model: $this->model,
            metadata: $this->metadata,
            tokensUsed: $this->tokensUsed,
            creditsUsed: $this->creditsUsed,
            latency: $this->latency,
            requestId: $this->requestId,
            usage: $this->usage,
            cached: $this->cached,
            finishReason: $this->finishReason,
            files: $this->files,
            actions: $actions,
            error: $this->error,
            success: $this->success
        );
    }

    /**
     * Set finish reason
     */
    public function withFinishReason(?string $finishReason): self
    {
        return new self(
            content: $this->content,
            engine: $this->engine,
            model: $this->model,
            metadata: $this->metadata,
            tokensUsed: $this->tokensUsed,
            creditsUsed: $this->creditsUsed,
            latency: $this->latency,
            requestId: $this->requestId,
            usage: $this->usage,
            cached: $this->cached,
            finishReason: $finishReason,
            files: $this->files,
            error: $this->error,
            success: $this->success
        );
    }

    /**
     * Add detailed usage information
     */
    public function withDetailedUsage(array $usage): self
    {
        return new self(
            content: $this->content,
            engine: $this->engine,
            model: $this->model,
            metadata: $this->metadata,
            tokensUsed: $this->tokensUsed,
            creditsUsed: $this->creditsUsed,
            latency: $this->latency,
            requestId: $this->requestId,
            usage: $usage,
            cached: $this->cached,
            finishReason: $this->finishReason,
            files: $this->files,
            error: $this->error,
            success: $this->success
        );
    }

    /**
     * Check if the response was successful
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Alias for isSuccessful() for compatibility
     */
    public function isSuccess(): bool
    {
        return $this->isSuccessful();
    }

    /**
     * Check if the response has an error
     */
    public function isError(): bool
    {
        return !$this->success;
    }

    /**
     * Check if the response was cached
     */
    public function isCached(): bool
    {
        return $this->cached;
    }

    /**
     * Get the content type based on the model
     */
    public function getContentType(): string
    {
        return $this->model->contentType();
    }

    /**
     * Check if this is a text response
     */
    public function isTextResponse(): bool
    {
        return $this->getContentType() === 'text';
    }

    /**
     * Check if this is an image response
     */
    public function isImageResponse(): bool
    {
        return $this->getContentType() === 'image';
    }

    /**
     * Check if this is a video response
     */
    public function isVideoResponse(): bool
    {
        return $this->getContentType() === 'video';
    }

    /**
     * Check if this is an audio response
     */
    public function isAudioResponse(): bool
    {
        return $this->getContentType() === 'audio';
    }

    /**
     * Check if this response has interactive actions
     */
    public function hasActions(): bool
    {
        return !empty($this->actions);
    }

    /**
     * Get the interactive actions
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Get actions of a specific type
     */
    public function getActionsByType(string $type): array
    {
        return array_filter($this->actions, function($action) use ($type) {
            if (is_array($action)) {
                return ($action['type'] ?? null) === $type;
            }
            if ($action instanceof InteractiveAction) {
                return $action->type->value === $type;
            }
            return false;
        });
    }

    /**
     * Get the first file (useful for single file responses)
     */
    public function getFirstFile(): ?string
    {
        return $this->files[0] ?? null;
    }

    /**
     * Get cost information
     */
    public function getCostInfo(): array
    {
        return [
            'tokens_used' => $this->tokensUsed,
            'credits_used' => $this->creditsUsed,
            'credit_index' => $this->model->creditIndex(),
            'cached' => $this->cached,
        ];
    }

    /**
     * Get performance information
     */
    public function getPerformanceInfo(): array
    {
        return [
            'latency' => $this->latency,
            'cached' => $this->cached,
            'engine' => $this->engine->value,
            'model' => $this->model->value,
        ];
    }

    /**
     * Check if the response has an error
     */
    public function hasError(): bool
    {
        return !$this->success && $this->error !== null;
    }

    /**
     * Get the credits used for this response
     */
    public function getCreditsUsed(): float
    {
        if ($this->creditsUsed !== null) {
            return $this->creditsUsed;
        }
        
        return $this->usage['total_cost'] ?? 0;
    }

    /**
     * Get the tokens used for this response
     */
    public function getTokensUsed(): int
    {
        if ($this->tokensUsed !== null) {
            return $this->tokensUsed;
        }
        
        return $this->usage['tokens'] ?? 0;
    }

    /**
     * Get the processing time for this response
     */
    public function getProcessingTime(): float
    {
        return $this->metadata['processing_time'] ?? 0;
    }

    /**
     * Convert the response to an array
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'engine' => $this->engine,
            'model' => $this->model,
            'metadata' => $this->metadata,
            'usage' => $this->usage ?? [],
            'success' => $this->success,
            'error' => $this->error,
            'tokensUsed' => $this->tokensUsed,
            'creditsUsed' => $this->creditsUsed,
            'latency' => $this->latency,
            'requestId' => $this->requestId,
            'cached' => $this->cached,
            'finishReason' => $this->finishReason,
            'files' => $this->files,
        ];
    }

    /**
     * JSON serialization
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Create an AIResponse from an array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: $data['content'] ?? '',
            engine: $data['engine'] ?? null,
            model: $data['model'] ?? null,
            metadata: $data['metadata'] ?? [],
            tokensUsed: $data['tokensUsed'] ?? null,
            creditsUsed: $data['creditsUsed'] ?? null,
            latency: $data['latency'] ?? null,
            requestId: $data['requestId'] ?? null,
            usage: $data['usage'] ?? [],
            cached: $data['cached'] ?? false,
            finishReason: $data['finishReason'] ?? null,
            files: $data['files'] ?? [],
            error: $data['error'] ?? null,
            success: $data['success'] ?? true
        );
    }
}
