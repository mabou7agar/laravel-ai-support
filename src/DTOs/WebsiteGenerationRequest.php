<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

/**
 * Input for the design-intelligence-grounded website builder.
 */
final class WebsiteGenerationRequest
{
    /**
     * Stacks the builder knows how to target. The stack drives the prompt
     * instructions and the expected output format, not a data lookup.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_STACKS = ['html', 'react', 'next', 'vue', 'svelte'];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $prompt,
        public readonly string $stack = 'html',
        public readonly ?string $projectName = null,
        public readonly ?string $page = null,
        public readonly ?string $engine = null,
        public readonly ?string $model = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly bool $qualityReview = true,
        public readonly bool $persist = false,
        public readonly ?DesignSystem $designSystem = null,
        public readonly ?string $userId = null,
        public readonly array $metadata = [],
        public readonly ?string $baseContent = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        $stack = self::normalizeStack($data['stack'] ?? null);

        return new self(
            prompt: trim((string) ($data['prompt'] ?? '')),
            stack: $stack,
            projectName: isset($data['project_name']) ? (string) $data['project_name'] : null,
            page: isset($data['page']) ? (string) $data['page'] : null,
            engine: isset($data['engine']) ? (string) $data['engine'] : null,
            model: isset($data['model']) ? (string) $data['model'] : null,
            maxTokens: isset($data['max_tokens']) ? (int) $data['max_tokens'] : null,
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            qualityReview: (bool) ($data['quality_review'] ?? config('ai-engine.design.quality_review', true)),
            persist: (bool) ($data['persist'] ?? false),
            userId: $userId,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            baseContent: isset($data['base_content']) && trim((string) $data['base_content']) !== ''
                ? (string) $data['base_content']
                : null,
        );
    }

    /**
     * Whether this request edits an existing document (vs. generating fresh).
     */
    public function isModification(): bool
    {
        return $this->baseContent !== null && trim($this->baseContent) !== '';
    }

    public static function normalizeStack(mixed $stack): string
    {
        $stack = is_string($stack) ? strtolower(trim($stack)) : '';
        $aliases = [
            'nextjs' => 'next',
            'next.js' => 'next',
            'reactjs' => 'react',
            'vuejs' => 'vue',
            'sveltekit' => 'svelte',
            'html-tailwind' => 'html',
            'tailwind' => 'html',
        ];
        $stack = $aliases[$stack] ?? $stack;

        return in_array($stack, self::SUPPORTED_STACKS, true) ? $stack : 'html';
    }

    /**
     * Whether the target stack produces a full standalone HTML document.
     */
    public function isHtmlDocument(): bool
    {
        return $this->stack === 'html';
    }

    public function resolvedProjectName(): string
    {
        if ($this->projectName !== null && trim($this->projectName) !== '') {
            return trim($this->projectName);
        }

        return 'Website';
    }
}
