<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

/**
 * Describes how a single video model maps a normalized AIRequest into a
 * provider-specific payload. This is the single source of truth for a model's
 * endpoint, supported options, and first/last frame field names.
 *
 * Field names are taken from each provider's live API schema (FAL model API
 * pages / Replicate model schemas), so only documented options are forwarded.
 */
final class VideoModelSpec
{
    /**
     * @param  'fal_ai'|'replicate'  $engine
     * @param  'text'|'image'|'reference'  $kind
     * @param  'required'|'optional'|'none'  $promptMode
     * @param  array<string, array{field: string, cast: string, default?: mixed}>  $options
     *         Canonical option key => mapping. `field` is the provider payload key,
     *         `cast` is one of string|int|float|bool|raw, optional `default` is sent
     *         when the caller does not supply the option.
     * @param  'kling'|'seedance'|null  $augmentStyle  How to fold references into the prompt.
     */
    public function __construct(
        public readonly string $engine,
        public readonly string $endpoint,
        public readonly string $kind,
        public readonly float $creditIndex,
        public readonly string $promptMode = 'required',
        public readonly ?string $firstFrameField = null,
        public readonly ?string $lastFrameField = null,
        public readonly bool $firstFrameRequired = false,
        public readonly ?string $referenceImageField = null,
        public readonly bool $supportsVideoRefs = false,
        public readonly bool $supportsAudioRefs = false,
        public readonly bool $supportsElements = false,
        public readonly bool $supportsMultiPrompt = false,
        public readonly ?string $augmentStyle = null,
        public readonly array $options = [],
    ) {
    }

    /**
     * Clone this spec with a different endpoint (for sibling tiers that share a schema).
     */
    public function withEndpoint(string $endpoint): self
    {
        return new self(
            engine: $this->engine,
            endpoint: $endpoint,
            kind: $this->kind,
            creditIndex: $this->creditIndex,
            promptMode: $this->promptMode,
            firstFrameField: $this->firstFrameField,
            lastFrameField: $this->lastFrameField,
            firstFrameRequired: $this->firstFrameRequired,
            referenceImageField: $this->referenceImageField,
            supportsVideoRefs: $this->supportsVideoRefs,
            supportsAudioRefs: $this->supportsAudioRefs,
            supportsElements: $this->supportsElements,
            supportsMultiPrompt: $this->supportsMultiPrompt,
            augmentStyle: $this->augmentStyle,
            options: $this->options,
        );
    }

    public function isFal(): bool
    {
        return $this->engine === 'fal_ai';
    }

    public function isReplicate(): bool
    {
        return $this->engine === 'replicate';
    }

    public function acceptsPrompt(): bool
    {
        return $this->promptMode !== 'none';
    }

    public function requiresPrompt(): bool
    {
        return $this->promptMode === 'required';
    }

    /**
     * Merge this model's whitelisted options into a payload, casting each value and
     * applying spec defaults. Only documented fields for the model are forwarded.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function applyOptions(array $payload, array $parameters): array
    {
        foreach ($this->options as $key => $option) {
            $value = $parameters[$key] ?? $parameters[$option['field']] ?? null;

            if ($value === null || $value === '') {
                if (array_key_exists('default', $option)) {
                    $payload[$option['field']] = $option['default'];
                }
                continue;
            }

            $payload[$option['field']] = self::cast($value, $option['cast']);
        }

        return $payload;
    }

    public static function cast(mixed $value, string $cast): mixed
    {
        return match ($cast) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    public static function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
