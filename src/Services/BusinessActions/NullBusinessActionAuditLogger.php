<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\BusinessActions;

use LaravelAIEngine\Contracts\BusinessActionAuditLogger;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class NullBusinessActionAuditLogger implements BusinessActionAuditLogger
{
    public function prepared(string $actionId, array $action, array $payload, array $result, ?UnifiedActionContext $context): void
    {
    }

    public function executed(string $actionId, array $action, array $payload, ActionResult $result, ?UnifiedActionContext $context): void
    {
    }
}

