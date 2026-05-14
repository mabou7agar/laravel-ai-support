<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final class RoutingTrace
{
    /**
     * @param array<int, RoutingDecision> $decisions
     */
    public function __construct(
        public readonly array $decisions = [],
        public readonly ?RoutingDecision $selected = null
    ) {
    }

    public function record(RoutingDecision $decision): self
    {
        $decisions = $this->decisions;
        $decisions[] = $decision;

        return new self($decisions, $this->selected);
    }

    public function select(RoutingDecision $decision): self
    {
        return new self($this->decisions, $decision);
    }

    public function toArray(): array
    {
        return [
            'decisions' => array_map(
                static fn (RoutingDecision $decision): array => $decision->toArray(),
                $this->decisions
            ),
            'selected' => $this->selected?->toArray(),
        ];
    }
}
