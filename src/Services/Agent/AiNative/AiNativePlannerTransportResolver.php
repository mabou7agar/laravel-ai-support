<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AiNativePlannerTransportSelection;
use LaravelAIEngine\Services\ModelCapabilityProfileResolver;

class AiNativePlannerTransportResolver
{
    public function __construct(
        private readonly PromptJsonPlannerTransport $promptJson,
        private readonly NativeToolPlannerTransport $nativeTools,
        private readonly ModelCapabilityProfileResolver $capabilities
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function resolve(string $provider, string $model, array $options = []): AiNativePlannerTransportSelection
    {
        $requested = strtolower(trim((string) (
            $options['planner_transport']
            ?? config('ai-agent.ai_native.planner_transport.mode', 'prompt_json')
        )));

        if ($requested === 'native_tools') {
            return new AiNativePlannerTransportSelection(
                $this->nativeTools,
                $requested,
                'explicit_native_tools'
            );
        }

        if ($requested !== 'auto') {
            return new AiNativePlannerTransportSelection(
                $this->promptJson,
                $requested !== '' ? $requested : 'prompt_json',
                $requested === 'prompt_json' || $requested === ''
                    ? 'explicit_prompt_json'
                    : 'unknown_mode_fallback'
            );
        }

        $profile = $this->capabilities->resolve(
            $provider,
            $model,
            (array) ($options['model_capabilities'] ?? [])
        );

        if ($profile->supportsNativeTools()) {
            return new AiNativePlannerTransportSelection(
                $this->nativeTools,
                'auto',
                'model_supports_native_tools',
                $profile
            );
        }

        return new AiNativePlannerTransportSelection(
            $this->promptJson,
            'auto',
            'native_tools_not_supported',
            $profile
        );
    }
}
