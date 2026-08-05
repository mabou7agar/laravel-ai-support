<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

/**
 * Additive, versioned discovery metadata for an agent tool.
 *
 * Empty fields mean "unspecified", never "denied". Consumers may use the
 * metadata for ranking or telemetry, but must not exclude a legacy tool merely
 * because it inherits these defaults.
 */
final readonly class AgentToolCapabilityMetadataDTO
{
    /**
     * @param list<string> $capabilities
     * @param list<string> $domains
     * @param list<string> $requires
     * @param list<string> $outcomes
     */
    public function __construct(
        public string $schemaVersion = '1',
        public array $capabilities = [],
        public array $domains = [],
        public ?string $costClass = null,
        public ?string $latencyClass = null,
        public array $requires = [],
        public array $outcomes = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'capabilities' => $this->capabilities,
            'domains' => $this->domains,
            'cost_class' => $this->costClass,
            'latency_class' => $this->latencyClass,
            'requires' => $this->requires,
            'outcomes' => $this->outcomes,
        ];
    }
}
