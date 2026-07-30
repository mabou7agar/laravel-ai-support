<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AssistantResourceQuery;
use LaravelAIEngine\DTOs\AssistantResourceResult;

interface AssistantResourceProvider
{
    public function supports(AssistantResourceQuery $query): bool;

    public function search(AssistantResourceQuery $query): AssistantResourceResult;
}
