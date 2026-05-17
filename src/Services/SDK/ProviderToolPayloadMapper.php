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
        $mcpServers = [];
        $toolConfig = [];
        $betaHeaders = [];

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
                if (isset($mapped['tool'])) {
                    $tools[] = $mapped['tool'];
                } elseif (isset($mapped['mcp_server'])) {
                    $mcpServers[] = $mapped['mcp_server'];
                } else {
                    $tools[] = $mapped;
                }

                if (isset($mapped['tool_config']) && is_array($mapped['tool_config'])) {
                    $toolConfig = array_replace_recursive($toolConfig, $mapped['tool_config']);
                }
            }

            foreach ((array) ($mapped['beta_headers'] ?? []) as $betaHeader) {
                $betaHeaders[] = $betaHeader;
            }
        }

        return [
            'functions' => $functions,
            'tools' => $tools,
            'mcp_servers' => $mcpServers,
            'tool_config' => $toolConfig,
            'beta_headers' => array_values(array_unique($betaHeaders)),
        ];
    }

    protected function isProviderToolType(string $type): bool
    {
        return in_array($type, [
            'web_search',
            'web_fetch',
            'file_search',
            'code_interpreter',
            'computer_use',
            'mcp_server',
            'google_maps',
            'image_generation',
            'tool_search',
            'hosted_shell',
            'apply_patch',
            'provider_skill',
        ], true);
    }

    protected function mapProviderTool(string $provider, array $tool): ?array
    {
        return match ($provider) {
            EngineEnum::OpenAI->value     => $this->mapOpenAITool($tool),
            EngineEnum::Anthropic->value  => $this->mapAnthropicTool($tool),
            EngineEnum::Gemini->value     => $this->mapGeminiTool($tool),
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
            'code_interpreter' => [
                'type' => 'code_interpreter',
                'container' => array_replace_recursive(
                    ['type' => 'auto'],
                    array_filter([
                        'memory_limit' => $tool['memory_limit'] ?? null,
                        'file_ids' => array_values((array) ($tool['file_ids'] ?? [])),
                    ], static fn ($value): bool => $value !== null && $value !== [])
                ),
            ],
            'computer_use' => array_filter([
                'type' => 'computer_use_preview',
                'display_width' => $tool['display_width'] ?? null,
                'display_height' => $tool['display_height'] ?? null,
                'environment' => $tool['environment'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== []),
            'mcp_server' => array_filter([
                'type' => 'mcp',
                'server_label' => $tool['label'] ?? null,
                'server_url' => $tool['url'] ?? null,
                'headers' => (array) ($tool['headers'] ?? []),
                'authorization' => $tool['authorization_token'] ?? null,
                'connector_id' => $tool['connector_id'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== []),
            'image_generation' => array_filter([
                'type' => 'image_generation',
                'size' => $tool['size'] ?? null,
                'quality' => $tool['quality'] ?? null,
                'format' => $tool['format'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== ''),
            'tool_search' => array_filter([
                'type' => 'tool_search',
                'namespaces' => array_values((array) ($tool['namespaces'] ?? [])),
                'max_results' => $tool['max_results'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== []),
            'hosted_shell' => array_filter([
                'type' => 'hosted_shell',
                'container' => array_replace_recursive(
                    ['type' => 'auto'],
                    (array) ($tool['container'] ?? [])
                ),
            ], static fn ($value): bool => $value !== null && $value !== []),
            'apply_patch' => array_filter([
                'type' => 'apply_patch',
                'workspace' => $tool['workspace'] ?? null,
                'allowed_paths' => array_values((array) ($tool['allowed_paths'] ?? [])),
            ], static fn ($value): bool => $value !== null && $value !== []),
            'provider_skill' => array_filter([
                'type' => 'skill',
                'name' => $tool['name'] ?? null,
                'version' => $tool['version'] ?? null,
                'input_schema' => (array) ($tool['input_schema'] ?? []),
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
            'code_interpreter' => [
                'tool' => [
                    'type' => 'code_execution_20250825',
                    'name' => 'code_execution',
                ],
                'beta_headers' => ['code-execution-2025-08-25'],
            ],
            'computer_use' => [
                'tool' => array_filter([
                    'type' => 'computer_20250124',
                    'name' => 'computer',
                    'display_width_px' => $tool['display_width'] ?? null,
                    'display_height_px' => $tool['display_height'] ?? null,
                    'display_number' => $tool['display_number'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== []),
                'beta_headers' => ['computer-use-2025-01-24'],
            ],
            'mcp_server' => [
                'mcp_server' => array_filter([
                    'type' => 'url',
                    'name' => $tool['label'] ?? null,
                    'url' => $tool['url'] ?? null,
                    'authorization_token' => $tool['authorization_token'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== []),
                'beta_headers' => ['mcp-client-2025-04-04'],
            ],
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
            'google_maps' => [
                'tool' => [
                    'googleMaps' => array_filter([
                        'enableWidget' => (bool) ($tool['enable_widget'] ?? false),
                    ], static fn ($value): bool => $value !== false),
                ],
                'tool_config' => $this->geminiMapsToolConfig($tool),
            ],
            'code_interpreter' => ['codeExecution' => (object) []],
            default => null,
        };
    }

    protected function geminiMapsToolConfig(array $tool): array
    {
        if (!isset($tool['latitude'], $tool['longitude'])) {
            return [];
        }

        return [
            'toolConfig' => [
                'retrievalConfig' => [
                    'latLng' => [
                        'latitude' => (float) $tool['latitude'],
                        'longitude' => (float) $tool['longitude'],
                    ],
                ],
            ],
        ];
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
