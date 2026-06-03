<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts\RAG;

interface NodeContextProvider
{
    public function getAvailableNodes(): array;
}
