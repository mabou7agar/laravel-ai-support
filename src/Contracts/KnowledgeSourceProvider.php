<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;

interface KnowledgeSourceProvider
{
    /** @param array<string, mixed> $context @return iterable<ScopedKnowledgeDocument|array<string, mixed>> */
    public function documents(array $context = []): iterable;
}
