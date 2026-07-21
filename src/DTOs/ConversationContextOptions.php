<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\ConversationContextMode;

final readonly class ConversationContextOptions
{
    public function __construct(
        public ConversationContextMode $mode,
        public ?string $scope = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $scope = $options['context_scope'] ?? null;
        $scope = is_scalar($scope) && trim((string) $scope) !== ''
            ? trim((string) $scope)
            : null;

        return new self(
            mode: ConversationContextMode::resolve($options['context_mode'] ?? null),
            scope: $scope,
        );
    }
}
