<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class LearnedDesignGenerationRequest
{
    public function __construct(
        public string $prompt,
        public array $scope = [],
        public string $type = 'design',
        public string $format = 'html',
        public int $limit = 5,
        public ?string $engine = null,
        public ?string $model = null,
        public int $maxTokens = 2500,
        public float $temperature = 0.25,
        public int $sourceContextChars = 12000,
        public bool $composeHtml = true,
        public array $metadata = [],
        public ?string $mediaUrl = null,
    ) {
        $this->prompt = trim($this->prompt);
        $this->type = trim($this->type) !== '' ? trim($this->type) : 'design';
        $this->format = strtolower(trim($this->format));
        $this->limit = max(1, $this->limit);
        $this->maxTokens = max(256, $this->maxTokens);
        $this->sourceContextChars = max(0, $this->sourceContextChars);
        $this->mediaUrl = is_string($this->mediaUrl) && trim($this->mediaUrl) !== ''
            ? trim($this->mediaUrl)
            : null;
    }

    public function normalizedFormat(): string
    {
        return in_array($this->format, ['html', 'markdown'], true) ? $this->format : 'html';
    }
}
