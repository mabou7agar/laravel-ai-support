<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface RoutingStageContract
{
    public function name(): string;

    public function decide(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): ?RoutingDecision;
}
