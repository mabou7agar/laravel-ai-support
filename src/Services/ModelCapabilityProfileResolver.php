<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\ModelCapabilityProfile;
use LaravelAIEngine\Models\AIModel;
use Throwable;

class ModelCapabilityProfileResolver
{
    public function __construct(
        private readonly AIModelCapabilityDetector $detector,
        private readonly ?AIModelRegistry $registry = null
    ) {
    }

    /**
     * Resolve a provider-neutral capability profile.
     *
     * Host overrides take precedence over synchronized catalog metadata. This
     * allows a host to qualify a model before its provider catalog has been
     * synchronized, without adding model-specific logic to the package.
     *
     * @param array<string, mixed> $requestOverrides
     */
    public function resolve(string $provider, string $model, array $requestOverrides = []): ModelCapabilityProfile
    {
        $provider = strtolower(trim($provider));
        $model = trim($model);
        $record = $this->modelRecord($provider, $model);
        $recordMetadata = (array) ($record?->metadata ?? []);
        $configured = $this->configuredOverride($provider, $model);
        $overrides = array_replace_recursive($configured, $requestOverrides);

        $supportedParameters = $this->normalizeStrings(array_merge(
            (array) ($recordMetadata['supported_parameters'] ?? []),
            (array) ($overrides['supported_parameters'] ?? [])
        ));

        $capabilities = $this->inferredCapabilities(
            $provider,
            $model,
            $recordMetadata,
            $supportedParameters
        );

        if ($record !== null) {
            $capabilities = array_merge($capabilities, (array) ($record->capabilities ?? []));

            if ((bool) $record->supports_function_calling) {
                $capabilities[] = 'function_calling';
            }

            if ((bool) $record->supports_json_mode) {
                $capabilities[] = 'json_mode';
            }
        }

        $capabilities = $this->normalizeStrings(array_merge(
            $capabilities,
            (array) ($overrides['capabilities'] ?? [])
        ));

        $capabilities = $this->applyBooleanOverrides($capabilities, $overrides);
        $supportedParameters = $this->applySupportedParameterOverrides($supportedParameters, $overrides);

        return new ModelCapabilityProfile(
            provider: $provider,
            model: $model,
            capabilities: $capabilities,
            supportedParameters: $supportedParameters,
            source: $overrides !== []
                ? 'override'
                : ($record !== null ? 'registry' : 'inferred'),
            metadata: array_replace_recursive($recordMetadata, (array) ($overrides['metadata'] ?? []))
        );
    }

    private function modelRecord(string $provider, string $model): ?AIModel
    {
        try {
            $registry = $this->registry;
            if ($registry === null && function_exists('app') && app()->bound(AIModelRegistry::class)) {
                $registry = app(AIModelRegistry::class);
            }

            $record = $registry?->getModel($model);

            return $record !== null && strtolower((string) $record->provider) === $provider
                ? $record
                : null;
        } catch (Throwable) {
            // The package can be used before its optional model-registry
            // migrations are installed. Capability inference must still work.
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function configuredOverride(string $provider, string $model): array
    {
        $overrides = (array) config('ai-agent.ai_native.model_capabilities', []);
        $providerOverrides = (array) ($overrides[$provider] ?? []);
        $profile = $providerOverrides[$model] ?? $overrides[$model] ?? [];

        return is_array($profile) ? $profile : [];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, string> $supportedParameters
     * @return array<int, string>
     */
    private function inferredCapabilities(
        string $provider,
        string $model,
        array $metadata,
        array $supportedParameters
    ): array {
        if ($provider === 'openrouter') {
            return $this->detector->detectOpenRouterCapabilities(array_replace_recursive($metadata, [
                'id' => $metadata['id'] ?? $model,
                'name' => $metadata['name'] ?? $model,
                'supported_parameters' => $supportedParameters,
            ]));
        }

        return $this->detector->detectCapabilities($model);
    }

    /**
     * @param array<int, string> $capabilities
     * @param array<string, mixed> $overrides
     * @return array<int, string>
     */
    private function applyBooleanOverrides(array $capabilities, array $overrides): array
    {
        $mapping = [
            'supports_native_tools' => 'function_calling',
            'supports_structured_output' => 'json_mode',
            'supports_reasoning' => 'reasoning',
        ];

        foreach ($mapping as $key => $capability) {
            if (!array_key_exists($key, $overrides)) {
                continue;
            }

            $capabilities = array_values(array_diff($capabilities, [$capability]));
            if ((bool) $overrides[$key]) {
                $capabilities[] = $capability;
            }
        }

        return $this->normalizeStrings($capabilities);
    }

    /**
     * @param array<int, string> $supportedParameters
     * @param array<string, mixed> $overrides
     * @return array<int, string>
     */
    private function applySupportedParameterOverrides(array $supportedParameters, array $overrides): array
    {
        $mapping = [
            'supports_native_tools' => ['tools', 'tool_choice'],
            'supports_structured_output' => ['response_format', 'structured_outputs', 'json_schema'],
            'supports_reasoning' => ['reasoning', 'include_reasoning'],
        ];

        foreach ($mapping as $key => $parameters) {
            if (array_key_exists($key, $overrides) && (bool) $overrides[$key] === false) {
                $supportedParameters = array_values(array_diff($supportedParameters, $parameters));
            }
        }

        return $this->normalizeStrings($supportedParameters);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function normalizeStrings(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $values
        ), static fn (string $value): bool => $value !== '')));
    }
}
