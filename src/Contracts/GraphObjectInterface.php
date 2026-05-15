<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

interface GraphObjectInterface
{
    /**
     * Return a sanitized object payload that is safe to expose in retrieval responses.
     *
     * @return array<string, mixed>
     */
    public function toGraphObject(): array;
}
