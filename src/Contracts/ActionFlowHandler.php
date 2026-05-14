<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface ActionFlowHandler
{
    /**
     * @return array<string, mixed>|null
     */
    public function action(string $actionId, ?UnifiedActionContext $context = null): ?array;

    /**
     * @return array<string, mixed>
     */
    public function catalog(?UnifiedActionContext $context = null, ?string $module = null): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array;

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(string $actionId, array $payload, bool $confirmed, ?UnifiedActionContext $context = null): ActionResult|array;

    /**
     * @param array<string, mixed> $contextData
     * @return array<string, mixed>
     */
    public function suggest(array $contextData = [], ?UnifiedActionContext $context = null): array;
}
