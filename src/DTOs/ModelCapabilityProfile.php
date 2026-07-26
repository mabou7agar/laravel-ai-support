<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class ModelCapabilityProfile
{
    /**
     * @param array<int, string> $capabilities
     * @param array<int, string> $supportedParameters
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $capabilities = [],
        public readonly array $supportedParameters = [],
        public readonly string $source = 'inferred',
        public readonly array $metadata = []
    ) {
    }

    public function supportsNativeTools(): bool
    {
        return $this->supportsCapability('function_calling')
            || $this->supportsParameter('tools');
    }

    public function supportsToolChoice(): bool
    {
        return $this->supportsParameter('tool_choice');
    }

    public function supportsStructuredOutput(): bool
    {
        return $this->supportsCapability('json_mode')
            || $this->supportsParameter('response_format')
            || $this->supportsParameter('structured_outputs')
            || $this->supportsParameter('json_schema');
    }

    public function supportsReasoning(): bool
    {
        return $this->supportsCapability('reasoning')
            || $this->supportsParameter('reasoning')
            || $this->supportsParameter('include_reasoning');
    }

    public function supportsParameter(string $parameter): bool
    {
        return in_array(strtolower($parameter), $this->supportedParameters, true);
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array(strtolower($capability), $this->capabilities, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'capabilities' => $this->capabilities,
            'supported_parameters' => $this->supportedParameters,
            'source' => $this->source,
            'supports_native_tools' => $this->supportsNativeTools(),
            'supports_tool_choice' => $this->supportsToolChoice(),
            'supports_structured_output' => $this->supportsStructuredOutput(),
            'supports_reasoning' => $this->supportsReasoning(),
        ];
    }
}
