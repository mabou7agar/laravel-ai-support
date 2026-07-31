<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\ConversationEntityReference;

interface ConversationEntityMemory
{
    /** @param array<string, mixed> $scope */
    public function remember(string $sessionId, ConversationEntityReference $reference, array $scope = []): void;

    /** @param array<string, mixed> $scope */
    public function focus(string $sessionId, ?string $type = null, array $scope = []): ?ConversationEntityReference;

    /** @param array<string, mixed> $scope @return list<ConversationEntityReference> */
    public function recent(string $sessionId, ?string $type = null, int $limit = 10, array $scope = []): array;

    /** @param array<string, mixed> $scope */
    public function forget(string $sessionId, array $scope = []): void;
}
