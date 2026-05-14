<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\AutonomousCollectorSessionState;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorSessionService;

class CollectorConfigResolver
{
    public function __construct(
        protected AutonomousCollectorSessionService $collectorService,
    ) {
    }

    public function resolve(AutonomousCollectorSessionState $state): ?AutonomousCollectorConfig
    {
        if ($state->configName === null || $state->configName === '') {
            return null;
        }

        return AutonomousCollectorRegistry::getConfig($state->configName)
            ?: $this->collectorService->getRegisteredConfig($state->configName);
    }

    public function resolveStartMatch(string $message, ?array $match = null): ?array
    {
        return $match ?: AutonomousCollectorRegistry::findConfigForMessage($message);
    }

    public function register(AutonomousCollectorConfig $config, UnifiedActionContext $context, string $nameHint = ''): string
    {
        $name = trim((string) ($config->name ?? ''));
        if ($name === '') {
            $name = trim($nameHint);
        }
        if ($name === '') {
            $name = 'collector_' . substr(sha1($context->sessionId . '|' . $config->goal), 0, 16);
        }

        $this->collectorService->registerConfigAs($name, $config);

        return $name;
    }
}
