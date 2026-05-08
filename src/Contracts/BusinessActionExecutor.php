<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\UnifiedActionContext;

interface BusinessActionExecutor
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function prepare(array $payload, ?UnifiedActionContext $context, array $action): array;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     */
    public function execute(array $payload, ?UnifiedActionContext $context, array $action): mixed;
}
