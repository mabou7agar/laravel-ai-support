<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\DTOs\ScopedKnowledgeMatch;

interface ScopedKnowledgeIndex
{
    /**
     * @param iterable<ScopedKnowledgeDocument> $documents
     * @return list<ScopedKnowledgeMatch>
     */
    public function search(iterable $documents, string $query, int $limit = 8): array;
}
