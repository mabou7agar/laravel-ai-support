<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\UnifiedActionContext;

interface ToolExposurePolicyContract
{
    /**
     * @param list<string> $registeredTools
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function allowedToolNames(
        UnifiedActionContext $context,
        array $registeredTools,
        array $options = [],
    ): array;
}
