<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning;

use LaravelAIEngine\DTOs\LearningIngestionResult;
use LaravelAIEngine\DTOs\LearnedDesignGenerationRequest;
use LaravelAIEngine\DTOs\LearnedDesignGenerationResult;
use LaravelAIEngine\DTOs\LearningSourceRequest;

class LearningBuilder
{
    protected ?string $sourceType = null;
    protected ?string $source = null;
    protected ?string $adapter = null;
    protected string $type = 'general';
    protected ?string $title = null;
    protected array $metadata = [];
    protected array $scope = [];
    protected bool $shouldIndex = false;
    protected ?string $vectorStoreId = null;
    protected string $vectorStoreName = 'Learned Knowledge';

    public function __construct(protected LearningService $service) {}

    public function fromText(string $text, ?string $title = null): self
    {
        $this->sourceType = 'text';
        $this->source = $text;
        $this->title = $title ?? $this->title;

        return $this;
    }

    public function fromFile(string $path, ?string $disk = null): self
    {
        $this->sourceType = 'file';
        $this->source = $path;

        if ($disk !== null && $disk !== '') {
            $this->metadata['disk'] = $disk;
        }

        return $this;
    }

    public function fromUrl(string $url): self
    {
        $this->sourceType = 'url';
        $this->source = $url;

        return $this;
    }

    public function fromDesignSlug(string $slug): self
    {
        $this->sourceType = 'getdesign_slug';
        $this->source = $slug;
        $this->adapter = 'getdesign';
        $this->type = 'design';

        return $this;
    }

    public function using(string $adapter): self
    {
        $this->adapter = $adapter;

        return $this;
    }

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_replace_recursive($this->metadata, $metadata);

        return $this;
    }

    public function scope(mixed $userId = null, mixed $tenantId = null, mixed $workspaceId = null, ?string $sessionId = null): self
    {
        $this->scope = [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'session_id' => $sessionId,
        ];

        return $this;
    }

    public function index(?string $storeId = null, string $storeName = 'Learned Knowledge'): self
    {
        $this->shouldIndex = true;
        $this->vectorStoreId = $storeId;
        $this->vectorStoreName = $storeName;

        return $this;
    }

    public function save(): LearningIngestionResult
    {
        if ($this->sourceType === null || $this->source === null || $this->source === '') {
            throw new \InvalidArgumentException('Learning source is required before save().');
        }

        return $this->service->ingest(new LearningSourceRequest(
            sourceType: $this->sourceType,
            source: $this->source,
            type: $this->type,
            title: $this->title,
            adapter: $this->adapter,
            metadata: $this->metadata,
            userId: $this->scope['user_id'] ?? null,
            tenantId: $this->scope['tenant_id'] ?? null,
            workspaceId: $this->scope['workspace_id'] ?? null,
            sessionId: $this->scope['session_id'] ?? null,
            shouldIndex: $this->shouldIndex,
            vectorStoreId: $this->vectorStoreId,
            vectorStoreName: $this->vectorStoreName,
        ));
    }

    public function search(string $query, array $scope = [], int $limit = 5, ?string $type = null): array
    {
        return $this->service->search($query, $scope, $limit, $type);
    }

    public function generateDesign(string $prompt, array $options = []): LearnedDesignGenerationResult
    {
        return app(LearnedDesignGeneratorService::class)->generate(new LearnedDesignGenerationRequest(
            prompt: $prompt,
            scope: (array) ($options['scope'] ?? []),
            type: (string) ($options['type'] ?? 'design'),
            format: (string) ($options['format'] ?? 'html'),
            limit: (int) ($options['limit'] ?? 5),
            engine: isset($options['engine']) ? (string) $options['engine'] : null,
            model: isset($options['model']) ? (string) $options['model'] : null,
            maxTokens: (int) ($options['max_tokens'] ?? 2500),
            temperature: (float) ($options['temperature'] ?? 0.25),
            sourceContextChars: (int) ($options['source_context_chars'] ?? 12000),
            composeHtml: (bool) ($options['compose_html'] ?? true),
            metadata: (array) ($options['metadata'] ?? []),
            mediaUrl: isset($options['media_url']) ? (string) $options['media_url'] : null,
        ));
    }
}
