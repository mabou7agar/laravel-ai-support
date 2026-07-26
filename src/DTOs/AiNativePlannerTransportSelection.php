<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Services\Agent\AiNative\AiNativePlannerTransportContract;

class AiNativePlannerTransportSelection
{
    public function __construct(
        public readonly AiNativePlannerTransportContract $transport,
        public readonly string $requested,
        public readonly string $reason,
        public readonly ?ModelCapabilityProfile $profile = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetry(): array
    {
        return [
            'requested' => $this->requested,
            'effective' => $this->transport->name(),
            'reason' => $this->reason,
            'capability_source' => $this->profile?->source,
            'supports_native_tools' => $this->profile?->supportsNativeTools(),
            'supports_tool_choice' => $this->profile?->supportsToolChoice(),
            'supports_structured_output' => $this->profile?->supportsStructuredOutput(),
            'supports_reasoning' => $this->profile?->supportsReasoning(),
        ];
    }
}
