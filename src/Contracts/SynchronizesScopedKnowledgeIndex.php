<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;

interface SynchronizesScopedKnowledgeIndex
{
    /**
     * @param iterable<ScopedKnowledgeDocument> $documents
     */
    public function sync(iterable $documents, bool $force = false): int;
}
