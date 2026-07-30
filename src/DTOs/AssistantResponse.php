<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class AssistantResponse
{
    /**
     * @param list<array<string, mixed>> $activities
     * @param list<AssistantResourceItem> $resources
     * @param list<array<string, mixed>> $metrics
     * @param list<array<string, mixed>> $actions
     * @param list<array<string, mixed>> $sources
     * @param array<string, mixed>|null $speech
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $message,
        public string $state = 'completed',
        public array $activities = [],
        public array $resources = [],
        public array $metrics = [],
        public array $actions = [],
        public array $sources = [],
        public ?array $speech = null,
        public array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'state' => $this->state,
            'activities' => $this->activities,
            'resources' => array_map(static fn (AssistantResourceItem $item): array => $item->toArray(), $this->resources),
            'metrics' => $this->metrics,
            'actions' => $this->actions,
            'sources' => $this->sources,
            'speech' => $this->speech,
            'metadata' => $this->metadata,
        ];
    }
}
