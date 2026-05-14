<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\RAGSource;

interface RAGRetrieverContract
{
    public function name(): string;

    /**
     * @return array<int, RAGSource>
     */
    public function retrieve(array $queries, array $collections, array $options = [], int|string|null $userId = null): array;
}
