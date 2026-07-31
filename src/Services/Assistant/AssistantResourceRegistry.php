<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Assistant;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Config\Repository;
use LaravelAIEngine\Contracts\AssistantResourceProvider;
use LaravelAIEngine\Contracts\AssistantResourceAccessPolicy;
use LaravelAIEngine\DTOs\AssistantResourceQuery;
use LaravelAIEngine\DTOs\AssistantResourceResult;
use RuntimeException;

final class AssistantResourceRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly AssistantResourceAccessPolicy $accessPolicy,
    ) {
    }

    /** @return list<AssistantResourceProvider> */
    public function providers(): array
    {
        $providers = [];
        foreach ((array) $this->config->get('ai-agent.assistant.resource_providers', []) as $class) {
            if (!is_string($class) || trim($class) === '') {
                continue;
            }
            $provider = $this->container->make($class);
            if (!$provider instanceof AssistantResourceProvider) {
                throw new RuntimeException(sprintf(
                    'Assistant resource provider [%s] must implement %s.',
                    $class,
                    AssistantResourceProvider::class,
                ));
            }
            $providers[] = $provider;
        }

        return $providers;
    }

    public function search(AssistantResourceQuery $query): AssistantResourceResult
    {
        $items = [];
        $messages = [];
        $metrics = [];
        $sources = [];
        $providerNames = [];

        foreach ($this->providers() as $provider) {
            if (!$provider->supports($query)) {
                continue;
            }
            $result = $provider->search($query);
            $providerNames[] = $provider::class;
            if ($result->message !== null) {
                $messages[] = $result->message;
            }
            foreach ($result->items as $item) {
                if (!$this->accessPolicy->allows($item, $query)) {
                    continue;
                }
                $items[$item->type.':'.$item->id] = $item;
            }
            $metrics = [...$metrics, ...$result->metrics];
            $sources = [...$sources, ...$result->sources];
            if (count($items) >= $query->limit) {
                break;
            }
        }

        return new AssistantResourceResult(
            items: array_slice(array_values($items), 0, max(1, $query->limit)),
            message: $messages[0] ?? null,
            metrics: $metrics,
            sources: $sources,
            metadata: ['providers' => $providerNames],
        );
    }
}
