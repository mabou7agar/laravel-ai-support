<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final class RAGCitation
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $title = null,
        public readonly ?string $url = null,
        public readonly ?string $sourceId = null,
        public readonly array $metadata = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: self::nullableString($data['type'] ?? $data['citation_type'] ?? null) ?? 'source',
            title: self::nullableString($data['title'] ?? $data['citation_title'] ?? null),
            url: self::nullableString($data['url'] ?? $data['citation_url'] ?? null),
            sourceId: self::nullableString($data['source_id'] ?? $data['id'] ?? null),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (is_scalar($value)) {
            $string = trim((string) $value);

            return $string !== '' ? $string : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $locale = function_exists('app') && app()->bound('translator')
            ? (string) app()->getLocale()
            : '';
        foreach (array_unique(array_filter([$locale, 'en', 'ar'])) as $key) {
            if (array_key_exists($key, $value)) {
                $localized = self::nullableString($value[$key]);
                if ($localized !== null) {
                    return $localized;
                }
            }
        }

        foreach ($value as $candidate) {
            $string = self::nullableString($candidate);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'url' => $this->url,
            'source_id' => $this->sourceId,
            'metadata' => $this->metadata,
        ];
    }
}
