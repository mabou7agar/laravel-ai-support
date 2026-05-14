<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final class AgentRuntimeCapabilities
{
    public function __construct(
        public readonly bool $streaming = false,
        public readonly bool $interrupts = false,
        public readonly bool $tools = false,
        public readonly bool $artifacts = false,
        public readonly bool $humanApprovals = false,
        public readonly bool $subAgents = false,
        public readonly bool $remoteCallbacks = false,
        public readonly array $metadata = []
    ) {
    }

    public static function laravel(): self
    {
        return new self(
            streaming: false,
            interrupts: true,
            tools: true,
            artifacts: true,
            humanApprovals: true,
            subAgents: true,
            remoteCallbacks: false,
            metadata: ['runtime' => 'laravel']
        );
    }

    public static function langGraph(bool $enabled): self
    {
        return new self(
            streaming: true,
            interrupts: $enabled,
            tools: $enabled,
            artifacts: $enabled,
            humanApprovals: $enabled,
            subAgents: $enabled,
            remoteCallbacks: $enabled,
            metadata: ['runtime' => 'langgraph', 'enabled' => $enabled]
        );
    }

    public function toArray(): array
    {
        return [
            'streaming' => $this->streaming,
            'interrupts' => $this->interrupts,
            'tools' => $this->tools,
            'artifacts' => $this->artifacts,
            'human_approvals' => $this->humanApprovals,
            'sub_agents' => $this->subAgents,
            'remote_callbacks' => $this->remoteCallbacks,
            'metadata' => $this->metadata,
        ];
    }
}
