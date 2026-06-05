<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools\Selectors;

/**
 * Default, back-compatible selector: expose every registered tool (no trimming).
 */
class AllToolSelector implements ToolSelectorContract
{
    public function select(array $tools, string $message, array $state, array $options): array
    {
        return $tools;
    }
}
