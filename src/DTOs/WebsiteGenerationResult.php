<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

/**
 * Output of the website builder: the generated code plus the grounding design
 * system and quality-control metadata.
 */
final class WebsiteGenerationResult
{
    /**
     * @param array<string, mixed> $qualityReview
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly string $stack,
        public readonly string $format,
        public readonly DesignSystem $designSystem,
        public readonly string $engine,
        public readonly string $model,
        public readonly int $tokensUsed = 0,
        public readonly float $creditsUsed = 0.0,
        public readonly array $qualityReview = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'stack' => $this->stack,
            'format' => $this->format,
            'design_system' => $this->designSystem->toArray(),
            'engine' => $this->engine,
            'model' => $this->model,
            'tokens_used' => $this->tokensUsed,
            'credits_used' => $this->creditsUsed,
            'quality_review' => $this->qualityReview,
            'metadata' => $this->metadata,
        ];
    }
}
