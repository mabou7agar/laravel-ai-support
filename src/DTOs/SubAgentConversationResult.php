<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class SubAgentConversationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $target,
        public readonly array $participants,
        public readonly array $transcript,
        public readonly array $results = [],
        public readonly string $stoppedReason = 'completed',
        public readonly int $roundsCompleted = 0,
        public readonly ?string $error = null,
        public readonly array $metadata = []
    ) {
    }

    public static function failure(
        string $target,
        string $error,
        array $participants = [],
        array $transcript = [],
        array $results = [],
        string $stoppedReason = 'failed',
        int $roundsCompleted = 0,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            target: $target,
            participants: $participants,
            transcript: $transcript,
            results: $results,
            stoppedReason: $stoppedReason,
            roundsCompleted: $roundsCompleted,
            error: $error,
            metadata: $metadata
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'success' => $this->success,
            'target' => $this->target,
            'participants' => $this->participants,
            'transcript' => $this->transcript,
            'results' => $this->results,
            'stopped_reason' => $this->stoppedReason,
            'rounds_completed' => $this->roundsCompleted,
            'error' => $this->error,
            'metadata' => $this->metadata,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
