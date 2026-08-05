<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\Contracts\ToolExposurePolicyContract;
use LaravelAIEngine\DTOs\UnifiedActionContext;

final class AllowListedToolExposurePolicy implements ToolExposurePolicyContract
{
    public function allowedToolNames(
        UnifiedActionContext $context,
        array $registeredTools,
        array $options = [],
    ): array {
        $registered = $this->strings($registeredTools);
        $selection = (array) ($options['tool_selection'] ?? []);
        if (! array_key_exists('exposed_tools', $selection)) {
            return $registered;
        }

        $available = array_flip($registered);

        return array_values(array_filter(
            $this->strings($selection['exposed_tools'] ?? []),
            static fn (string $name): bool => isset($available[$name]),
        ));
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== '')));
    }
}
