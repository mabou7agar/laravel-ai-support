<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AssistantResourceItem;
use LaravelAIEngine\DTOs\AssistantResourceQuery;

interface AssistantResourceAccessPolicy
{
    public function allows(AssistantResourceItem $item, AssistantResourceQuery $query): bool;
}
