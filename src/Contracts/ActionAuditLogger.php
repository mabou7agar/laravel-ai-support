<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface ActionAuditLogger
{
    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     */
    public function prepared(string $actionId, array $action, array $payload, array $result, ?UnifiedActionContext $context): void;

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     */
    public function executed(string $actionId, array $action, array $payload, ActionResult $result, ?UnifiedActionContext $context): void;
}

