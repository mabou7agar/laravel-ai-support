<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use LaravelAIEngine\Contracts\ActionAuditLogger;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class NullActionAuditLogger implements ActionAuditLogger
{
    public function prepared(string $actionId, array $action, array $payload, array $result, ?UnifiedActionContext $context): void
    {
    }

    public function executed(string $actionId, array $action, array $payload, ActionResult $result, ?UnifiedActionContext $context): void
    {
    }
}

