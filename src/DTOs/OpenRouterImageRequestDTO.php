<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class OpenRouterImageRequestDTO
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $endpoint,
        public array $payload,
        public ?int $timeoutSeconds,
        public bool $usesDedicatedApi,
    ) {
    }
}
