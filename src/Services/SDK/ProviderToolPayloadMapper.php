<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use LaravelAIEngine\Enums\EngineEnum;

class ProviderToolPayloadMapper
{
    public function splitForProvider(string $provider, array $definitions): array
    {
        $functions = [];
        $tools = [];

        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? '');
            if (!$this->isProviderToolType($type)) {
                $functions[] = $definition;
                continue;
            }

            $mapped = $this->mapProviderTool($provider, $definition);
            if ($mapped !== null) {
                $tools[] = $mapped;
            }
        }

        return [
            'functions' => $functions,
            'tools' => $tools,
        ];
    }

    protected function isProviderToolType(string $type): bool
    {
        return in_array($type, ['web_search', 'web_fetch', 'file_search'], true);
    }

    protected function mapProviderTool(string $provider, array $tool): ?array
    {
        return match ($provider) {
            EngineEnum::OPENAI => $this->mapOpenAITool($tool),
            EngineEnum::ANTHROPIC => $this->mapAnthropicTool($tool),
            EngineEnum::GEMINI => $this->mapGeminiTool($tool),
            default => null,
        };
    }

    protected function mapOpenAITool(array $tool): ?array
    {
        return match ((string) ($tool['type'] ?? '')) {
            'web_search' => array_filter([
                'type' => 'web_search_preview',
                'user_location' => $this->openAiLocation((array) ($tool['location'] ?? [])),
            ], static fn ($value): bool => $value !== null && $value !== []),
            'file_search' => array_filter([
                'type' => 'file_search',
                'vector_store_ids' => array_values((array) ($tool['stores'] ?? [])),
                'filters' => (array) ($tool['where'] ?? []),
            ], static fn ($value): bool => $value !== null && $value !== []),
            default => null,
        };
    }

    protected function mapAnthropicTool(array $tool): ?array
    {
        return match ((string) ($tool['type'] ?? '')) {
            'web_search' => array_filter([
                'type' => 'web_search_20250305',
                'name' => 'web_search',
                'max_uses' => $tool['max'] ?? null,
                'allowed_domains' => array_values((array) ($tool['allow'] ?? [])),
                'user_location' => (array) ($tool['location'] ?? []),
            ], static fn ($value): bool => $value !== null && $value !== []),
            'web_fetch' => array_filter([
                'type' => 'web_fetch',
                'name' => 'web_fetch',
                'max_uses' => $tool['max'] ?? null,
                'allowed_domains' => array_values((array) ($tool['allow'] ?? [])),
            ], static fn ($value): bool => $value !== null && $value !== []),
            default => null,
        };
    }

    protected function mapGeminiTool(array $tool): ?array
    {
        return match ((string) ($tool['type'] ?? '')) {
            'web_search' => ['googleSearch' => (object) []],
            'web_fetch' => ['urlContext' => (object) []],
            'file_search' => [
                'retrieval' => [
                    'disableAttribution' => false,
                    'metadataFilters' => (array) ($tool['where'] ?? []),
                ],
            ],
            default => null,
        };
    }

    protected function openAiLocation(array $location): ?array
    {
        if ($location === []) {
            return null;
        }

        return array_filter([
            'type' => 'approximate',
            'city' => $location['city'] ?? null,
            'region' => $location['region'] ?? null,
            'country' => $location['country'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
