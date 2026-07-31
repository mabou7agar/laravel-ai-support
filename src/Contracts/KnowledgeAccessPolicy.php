<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;

interface KnowledgeAccessPolicy
{
    /** @param array<string, mixed> $context */
    public function canAccess(ScopedKnowledgeDocument $document, array $context): bool;
}
