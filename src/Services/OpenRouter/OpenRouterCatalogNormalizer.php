<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\OpenRouter;

final class OpenRouterCatalogNormalizer
{
    /**
     * Convert the dedicated per-model endpoint response into the same shape as
     * one item from OpenRouter's bulk /models catalog.
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>|null
     */
    public function fromEndpointDetails(array $model): ?array
    {
        $modelId = trim((string) ($model['id'] ?? ''));
        $endpoints = array_values(array_filter(
            (array) ($model['endpoints'] ?? []),
            static fn (mixed $endpoint): bool => is_array($endpoint),
        ));

        if ($modelId === '' || $endpoints === []) {
            return null;
        }

        $primary = $endpoints[0];
        $supportedParameters = [];
        foreach ($endpoints as $endpoint) {
            $supportedParameters = array_merge(
                $supportedParameters,
                (array) ($endpoint['supported_parameters'] ?? []),
            );
        }

        return array_replace($model, [
            'id' => $modelId,
            'pricing' => (array) ($primary['pricing'] ?? []),
            'supported_parameters' => array_values(array_unique(array_map(
                static fn (mixed $parameter): string => (string) $parameter,
                $supportedParameters,
            ))),
            'context_length' => $primary['context_length'] ?? null,
            'top_provider' => [
                'context_length' => $primary['context_length'] ?? null,
                'max_completion_tokens' => $primary['max_completion_tokens'] ?? null,
            ],
        ]);
    }

    /**
     * Store canonical input/output prices per 1K tokens for existing package
     * consumers while retaining every provider-native price and override.
     *
     * @param array<string, mixed> $pricing
     * @return array<string, mixed>|null
     */
    public function pricing(array $pricing): ?array
    {
        if ($pricing === []) {
            return null;
        }

        $prompt = $this->perThousand($pricing['prompt'] ?? null);
        $completion = $this->perThousand($pricing['completion'] ?? null);
        $imageOutput = $this->perThousand($pricing['image_output'] ?? null);

        return array_filter([
            'unit' => 'usd_per_1k_tokens',
            'input' => $prompt,
            // Image generation is billed at image_output when supplied. Using
            // completion here would understate the provider cost.
            'output' => $imageOutput ?? $completion,
            'text_output' => $completion,
            'image_input' => $this->perThousand($pricing['image'] ?? null),
            'image_output' => $imageOutput,
            'audio_input' => $this->perThousand($pricing['audio'] ?? null),
            'internal_reasoning' => $this->perThousand($pricing['internal_reasoning'] ?? null),
            'input_cache_read' => $this->perThousand($pricing['input_cache_read'] ?? null),
            'input_cache_write' => $this->perThousand($pricing['input_cache_write'] ?? null),
            'input_cache_write_1h' => $this->perThousand($pricing['input_cache_write_1h'] ?? null),
            'provider_unit' => 'provider_native_usd',
            'provider' => $this->numericValues($pricing),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function perThousand(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return round((float) $value * 1000, 12);
    }

    private function numericValues(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->numericValues($item), $value);
        }

        return is_numeric($value) ? (float) $value : $value;
    }
}
