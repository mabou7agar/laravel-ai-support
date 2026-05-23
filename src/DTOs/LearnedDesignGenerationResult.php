<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class LearnedDesignGenerationResult
{
    /**
     * @param array<int, LearningSearchResult> $matches
     */
    public function __construct(
        public string $content,
        public string $format,
        public array $matches,
        public string $engine,
        public string $model,
        public ?int $tokensUsed = null,
        public ?float $creditsUsed = null,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'format' => $this->format,
            'engine' => $this->engine,
            'model' => $this->model,
            'tokens_used' => $this->tokensUsed,
            'credits_used' => $this->creditsUsed,
            'matches' => array_map(static fn (LearningSearchResult $match): array => [
                'score' => $match->score,
                'kind' => $match->item->kind,
                'title' => $match->item->title,
                'source_id' => $match->source->sourceId,
                'source_title' => $match->source->title,
            ], $this->matches),
            'metadata' => $this->metadata,
        ];
    }
}
