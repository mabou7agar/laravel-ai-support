<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\SearchDocument;

interface SearchDocumentInterface
{
    public function toSearchDocument(): SearchDocument|array;
}
