<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final class RoutingDecision
{
    public function __construct(
        public readonly string $action,
        public readonly string $source,
        public readonly string $confidence,
        public readonly string $reason,
        public readonly array $payload = [],
        public readonly array $metadata = []
    ) {
    }

    public static function abstained(string $source, string $reason, array $metadata = []): self
    {
        return new self(
            action: RoutingDecisionAction::ABSTAIN,
            source: $source,
            confidence: 'none',
            reason: $reason,
            metadata: $metadata
        );
    }

    public function isAbstention(): bool
    {
        return $this->action === RoutingDecisionAction::ABSTAIN;
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
        ];
    }
}
