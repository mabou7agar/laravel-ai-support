<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class CollectorReroutePolicy
{
    public function __construct(
        protected ?LocaleResourceService $localeResources = null,
    ) {
    }

    public function shouldExitForMessage(string $message, AutonomousCollectorConfig $config): bool
    {
        $messageLower = strtolower(trim($message));
        if ($messageLower === '') {
            return false;
        }

        foreach ($this->locale()->lexicon('intent.query_prefixes') as $pattern) {
            if (!str_starts_with($messageLower, (string) $pattern)) {
                continue;
            }

            $collectorName = strtolower((string) $config->name);
            if ($collectorName !== '' && str_contains($messageLower, $collectorName)) {
                return true;
            }

            foreach ((array) ($config->context['reroute_entities'] ?? []) as $entity) {
                if (str_contains($messageLower, strtolower((string) $entity))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }
}
